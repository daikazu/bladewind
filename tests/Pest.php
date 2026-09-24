<?php

declare(strict_types=1);

use Daikazu\BladeWind\Analysis\ClassAnalysis;
use Daikazu\BladeWind\Analysis\ClassAnalyzer;
use Daikazu\BladeWind\Analysis\ViewAnalyzer;
use Daikazu\BladeWind\Classes\Adapters\AlpineAdapter;
use Daikazu\BladeWind\Classes\Adapters\LivewireAdapter;
use Daikazu\BladeWind\Discovery\ViewLocator;
use Daikazu\BladeWind\Manifest\ViewEntry;
use Daikazu\BladeWind\Pages\VariableGraph;
use Daikazu\BladeWind\Parsing\ForteSourceParser;
use Daikazu\BladeWind\Parsing\Nodes\ComponentTag;
use Daikazu\BladeWind\Parsing\Nodes\Element;
use Daikazu\BladeWind\Parsing\Nodes\Node;
use Daikazu\BladeWind\Stylesheets\StylesheetIndex;
use Daikazu\BladeWind\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;

pest()->extend(TestCase::class)->in('Unit', 'Feature');

function analyzeFixture(string $relative): ViewEntry
{
    $path = test()->fixturePath($relative);

    return app(ViewAnalyzer::class)->analyze(app(ViewLocator::class)->describe($path), file_get_contents($path));
}

function analyzedEntry(string $relative): ViewEntry
{
    $path = test()->fixturePath($relative);

    return app(ViewAnalyzer::class)->analyze(app(ViewLocator::class)->describe($path), file_get_contents($path));
}

function fixtureStylesheetIndex(?string $publicPath = null): StylesheetIndex
{
    return new StylesheetIndex(['resources/css/app.css'], $publicPath ?? test()->fixturePath('public'), new Filesystem);
}

function analyzeClasses(string $source): ClassAnalysis
{
    return (new ClassAnalyzer([new LivewireAdapter, new AlpineAdapter]))->analyze((new ForteSourceParser)->parse($source));
}

function firstTagNode(string $source): Element|ComponentTag
{
    $found = null;
    (new ForteSourceParser)->parse($source)->walk(function (Node $node) use (&$found): void {
        if ($found === null && ($node instanceof Element || $node instanceof ComponentTag)) {
            $found = $node;
        }
    });

    return $found ?? throw new RuntimeException('no tag');
}

function tailwind3FixtureCss(): string
{
    return (string) file_get_contents(test()->fixturePath('public-tailwind3/build/assets/app-tailwind3.css'));
}

/**
 * A filesystem whose put() writes only part of what it was given and reports that length, the way
 * `file_put_contents()` returns a short count on a full disk without raising a warning.
 */
function shortWritingFilesystem(): Filesystem
{
    return new class extends Filesystem
    {
        public function put($path, $contents, $lock = false)
        {
            return parent::put($path, substr((string) $contents, 0, 2), $lock);
        }
    };
}

/**
 * Every variable and every keyframe the two files a page loads read is defined by one of them.
 *
 * This is the page-styles correctness rule ("a page must never lack a declaration it needs") as an
 * assertion: a `var(--name)` anywhere in the root, the page or the document's own CSS has to find its
 * name declared in the root or the page, as a plain declaration or as a `@property` registration
 * whose `initial-value` stands in for one.
 *
 * The names that count come from $stylesheet, deliberately not from the {@see VariableGraph} built
 * from the same split: the graph is derived from the splitter's output, so a splitter regression that
 * drops a theme name would drop it from the graph too and the assertion would pass while the page
 * lost a colour. Only names the stylesheet declares globally count (on `:root`, `:host`, `html` or
 * `*`, or through `@property`). A name a component declares on its own root and reads in a descendant
 * (`.dropdown-menu{--bs-dropdown-link-active-color:...}`) is defined by DOM ancestry, so a page that
 * carries the descendant's rule without the component leaves it undefined exactly as the full
 * stylesheet does. A name the stylesheet never declares is out of the check: nothing could define it.
 *
 * Keyframes are stricter: an animation named anywhere has to find its `@keyframes` rule in the root,
 * because an inline `style="animation:spin 1s"` or a line of JavaScript can name a keyframe with
 * nothing to route it by.
 *
 * @param  string  $stylesheet  the compiled stylesheet the two files were split out of
 * @param  string  $documentCss  CSS the document itself carries, if any; an HTML string is fine, only its `var()` and `animation` sites are read
 */
function assertNoUndefinedVariables(string $root, string $page, string $stylesheet, string $documentCss = ''): void
{
    $emitted = $root."\n".$page;
    $css = $emitted."\n".$documentCss;

    $declarable = globallyDeclaredCustomProperties($stylesheet);
    $defined = declaredCustomProperties($emitted);
    /** @var list<string> $missing */
    $missing = [];

    foreach (VariableGraph::refs($css) as $name) {
        if (isset($declarable[$name]) && ! isset($defined[$name])) {
            $missing[] = $name;
        }
    }

    /** @var list<string> $missingKeyframes */
    $missingKeyframes = [];

    foreach (VariableGraph::keyframesReferenced($css, definedKeyframes($stylesheet)) as $name) {
        if (preg_match('~@(?:-[a-z]+-)?keyframes\s+["\']?'.preg_quote($name, '~').'["\']?\s*\{~', $root) !== 1) {
            $missingKeyframes[] = $name;
        }
    }

    expect($missing)->toBe([])
        ->and($missingKeyframes)->toBe([]);
}

/**
 * Every custom property $css gives a value: the `--name:` declaration sites and the `@property`
 * registrations, whose `initial-value` is the property's value wherever nothing sets it.
 *
 * @return array<string, true>
 */
function declaredCustomProperties(string $css): array
{
    preg_match_all('~(--[\w-]+)\s*:~', $css, $declarations);
    preg_match_all('~@property\s+(--[\w-]+)~', $css, $registrations);

    return array_fill_keys([...$declarations[1], ...$registrations[1]], true);
}

/**
 * Every custom property $css declares on a global selector (`:root`, `:host`, `html` or `*` in the
 * selector list of the innermost block holding the declaration) or registers with `@property`.
 * Innermost blocks are enough: a layer or conditional group around the block only wraps it.
 *
 * @return array<string, true>
 */
function globallyDeclaredCustomProperties(string $css): array
{
    $names = [];

    preg_match_all('~([^{}]+)\{([^{}]*)\}~', $css, $blocks, PREG_SET_ORDER);

    foreach ($blocks as [, $prelude, $body]) {
        $selector = trim((string) preg_replace('~^.*[;}]~s', '', $prelude));

        if (preg_match('~(?:^|,)\s*(?::root|:host|html|\*)(?:[^,]*)(?:,|$)~', $selector) !== 1) {
            continue;
        }

        preg_match_all('~(--[\w-]+)\s*:~', $body, $declarations);
        $names = [...$names, ...$declarations[1]];
    }

    preg_match_all('~@property\s+(--[\w-]+)~', $css, $registrations);

    return array_fill_keys([...$names, ...$registrations[1]], true);
}

/**
 * The keyframe names $css defines, each once however many vendor spellings define it.
 *
 * @return list<string>
 */
function definedKeyframes(string $css): array
{
    preg_match_all('~@(?:-[a-z]+-)?keyframes\s+(?:"([^"]*)"|\'([^\']*)\'|([^\s{]+))~i', $css, $matches);

    $names = array_filter([...$matches[1], ...$matches[2], ...$matches[3]], static fn (string $name): bool => $name !== '');

    return array_values(array_unique($names));
}
