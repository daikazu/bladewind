<?php

declare(strict_types=1);

use Daikazu\BladeWind\Pages\Drivers\Bootstrap5Driver;
use Daikazu\BladeWind\Pages\Drivers\BulmaDriver;
use Daikazu\BladeWind\Pages\Drivers\FoundationDriver;
use Daikazu\BladeWind\Pages\Drivers\TachyonsDriver;
use Daikazu\BladeWind\Pages\Drivers\Tailwind3Driver;
use Daikazu\BladeWind\Pages\Drivers\Tailwind4Driver;
use Daikazu\BladeWind\Pages\FlatStylesheetSplitter;
use Daikazu\BladeWind\Pages\HtmlVariableScanner;
use Daikazu\BladeWind\Pages\PageCssBuilder;
use Daikazu\BladeWind\Pages\SplitStylesheet;
use Daikazu\BladeWind\Pages\StylesheetSplitter;
use Daikazu\BladeWind\Pages\SupportCssBuilder;
use Daikazu\BladeWind\Pages\UtilityRuleIndex;
use Daikazu\BladeWind\Pages\VariableGraph;

/**
 * A hand-written stylesheet with one of each support part: a properties layer inside a wrapper, two
 * theme blocks declaring the same name, a base layer that reads a theme variable of its own, the
 * `@property` registrations of the `--tw-*` defaults and a keyframe reached through a theme value.
 *
 * `--tw-c` and `--tw-d` are the one-sided halves: a properties-layer default with no registration,
 * and a registration with no default. Tailwind pairs them, but the pairing is its convention rather
 * than a rule of CSS, and an emitter that assumed it would drop whichever half it did not look for.
 */
const SUPPORT_CSS = '@layer properties{@supports (x:y){*,:before{--tw-a:0;--tw-b:solid;--tw-c:none}}}'
    .'@layer theme{:root,:host{--color-x:red;--color-y:green;--animate-wiggle:wiggle 1s ease}.dark{--color-x:blue}}'
    .'@layer base{a{color:var(--color-y)}}'
    .'@layer utilities{.a{color:var(--color-x)}}'
    .'@property --tw-a{syntax:"*";inherits:false;initial-value:0}'
    .'@property --tw-b{syntax:"*";inherits:false;initial-value:solid}'
    .'@property --tw-d{syntax:"*";inherits:false;initial-value:0}'
    .'@keyframes wiggle{to{transform:rotate(3deg)}}';

/**
 * The root file and one page's file for a fixture stylesheet, built the way the request-time path
 * builds them: the page's seeds are what its utilities and its HTML read, closed over the values
 * they reach, less what the root already carries.
 *
 * @param  list<string>  $tokens  the page's class set
 * @return array{0: string, 1: string, 2: string, 3: SplitStylesheet} the root file, the page file, the stylesheet they came from and its split
 */
function supportFiles(string $fixture, array $tokens, string $html = ''): array
{
    $css = (string) file_get_contents(test()->fixturePath($fixture));
    $driver = match (true) {
        str_contains($fixture, 'tailwind3') => new Tailwind3Driver(new FlatStylesheetSplitter),
        str_contains($fixture, 'tachyons') => new TachyonsDriver(new FlatStylesheetSplitter),
        str_contains($fixture, 'bootstrap5') => new Bootstrap5Driver(new FlatStylesheetSplitter),
        str_contains($fixture, 'bulma') => new BulmaDriver(new FlatStylesheetSplitter),
        str_contains($fixture, 'foundation') => new FoundationDriver(new FlatStylesheetSplitter),
        default => new Tailwind4Driver(new StylesheetSplitter),
    };
    $split = $driver->split($css);
    $graph = VariableGraph::fromSplit($split);
    $builder = new SupportCssBuilder;

    $utilities = $driver->wrapUtilities((new PageCssBuilder)->build($tokens, UtilityRuleIndex::build($split->utilities, $driver->indexTokens(...))));
    $seeds = [...VariableGraph::refs($utilities), ...HtmlVariableScanner::names($html)];
    $names = array_values(array_diff($graph->closure($seeds), $builder->rootNames($split, $graph)));

    return [$builder->root($split, $graph), $builder->page($split, $graph, $names, $utilities), $css, $split];
}

it('keeps in the root only the support declarations the root itself reads', function (): void {
    $split = (new StylesheetSplitter)->split(SUPPORT_CSS);
    $graph = VariableGraph::fromSplit($split);
    $builder = new SupportCssBuilder;

    // The base layer reads `--color-y` and nothing else, so the theme layer keeps that one
    // declaration, the `.dark` block goes entirely, and the properties layer stays a statement.
    // The keyframe is the one thing the root carries without reading it: every `@keyframes` rule
    // ships with the file every page loads, because nothing on the server sees what animates.
    expect($builder->rootNames($split, $graph))->toBe(['--color-y'])
        ->and($builder->rootKeyframes($split))->toBe(['wiggle'])
        ->and($builder->root($split, $graph))->toBe('@layer properties;@layer theme{:root,:host{--color-y:green}}@layer base{a{color:var(--color-y)}}@layer utilities;@keyframes wiggle{to{transform:rotate(3deg)}}');

    assertNoUndefinedVariables($builder->root($split, $graph), '', SUPPORT_CSS);
});

it('defines in the root a variable only a keyframe reads', function (): void {
    // Tailwind 4's own way to add an animation: `@theme { --animate-glow: ...; @keyframes glow { ... } }`.
    // Every keyframe travels in the root, so what a keyframe reads has to be defined there too.
    $css = '@layer theme{:root,:host{--color-brand:red;--animate-glow:glow 1s infinite}}'
        .'@layer base{h1{x:1}}'
        .'@layer utilities{.animate-glow{animation:var(--animate-glow)}}'
        .'@keyframes glow{to{box-shadow:0 0 8px var(--color-brand)}}';
    $split = (new StylesheetSplitter)->split($css);
    $graph = VariableGraph::fromSplit($split);
    $builder = new SupportCssBuilder;
    $utilities = '@layer utilities{.animate-glow{animation:var(--animate-glow)}}';
    $names = array_values(array_diff($graph->closure(VariableGraph::refs($utilities)), $builder->rootNames($split, $graph)));
    $root = $builder->root($split, $graph);
    $page = $builder->page($split, $graph, $names, $utilities);

    expect($builder->rootNames($split, $graph))->toBe(['--color-brand'])
        ->and($root)->toContain('--color-brand:red')
        ->and($page)->toContain('--animate-glow:glow 1s infinite')->not->toContain('--color-brand');

    assertNoUndefinedVariables($root, $page, $css);
});

it('defines in the root a variable a companion stylesheet reads', function (): void {
    // A second stylesheet built with `@reference "app.css"` (or any hand-written CSS in another Vite
    // entry) reads the theme without declaring it: it is linked whole after the root and page
    // files, so the root has to carry whatever it reads.
    $split = (new StylesheetSplitter)->split(SUPPORT_CSS);
    $graph = VariableGraph::fromSplit($split);
    $builder = new SupportCssBuilder;
    $companion = '.prose{color:var(--color-x)}';

    expect($builder->rootNames($split, $graph, $companion))->toBe(['--color-x', '--color-y'])
        ->and($builder->root($split, $graph, $companion))->toContain('--color-x:red')->toContain('.dark{--color-x:blue}')
        // Without the companion the root reads only `--color-y`; the memo tells the two apart.
        ->and($builder->rootNames($split, $graph))->toBe(['--color-y']);

    assertNoUndefinedVariables($builder->root($split, $graph, $companion), '', SUPPORT_CSS, $companion);
});

it('carries the theme blocks a page names, in source order, around its utilities', function (): void {
    $split = (new StylesheetSplitter)->split(SUPPORT_CSS);
    $graph = VariableGraph::fromSplit($split);
    $utilities = '@layer utilities{.a{color:var(--color-x)}}';

    $page = (new SupportCssBuilder)->page($split, $graph, ['--color-x'], $utilities);

    // Both declarations of the name travel: the `.dark` block is what the variant resolves to.
    expect($page)->toBe('@layer theme{:root,:host{--color-x:red}.dark{--color-x:blue}}'.$utilities);

    assertNoUndefinedVariables((new SupportCssBuilder)->root($split, $graph), $page, SUPPORT_CSS);
});

it('carries the --tw-* default and the @property registration of a name a page needs', function (): void {
    $split = (new StylesheetSplitter)->split(SUPPORT_CSS);
    $graph = VariableGraph::fromSplit($split);
    $utilities = '@layer utilities{.b{border-style:var(--tw-a)}}';

    $page = (new SupportCssBuilder)->page($split, $graph, ['--tw-a'], $utilities);

    // The default and the registration are the one name declared twice, and only `--tw-a`'s pair is
    // emitted: the wrapper comes with the properties layer, `--tw-b` does not come at all.
    expect($page)->toBe('@layer properties{@supports (x:y){*,:before{--tw-a:0}}}'
        .$utilities
        .'@property --tw-a{syntax:"*";inherits:false;initial-value:0}');

    assertNoUndefinedVariables((new SupportCssBuilder)->root($split, $graph), $page, SUPPORT_CSS);
});

it('carries whichever half of a one-sided --tw-* the stylesheet actually declares', function (): void {
    $split = (new StylesheetSplitter)->split(SUPPORT_CSS);
    $graph = VariableGraph::fromSplit($split);
    $utilities = '@layer utilities{.e{display:var(--tw-c);order:var(--tw-d)}}';
    $builder = new SupportCssBuilder;

    // `--tw-c` is a properties-layer default with no registration and `--tw-d` a registration with
    // no default: each page carries the half that exists, and neither emits an empty block for the
    // half that does not.
    expect($builder->page($split, $graph, ['--tw-c'], $utilities))
        ->toBe('@layer properties{@supports (x:y){*,:before{--tw-c:none}}}'.$utilities)
        ->and($builder->page($split, $graph, ['--tw-d'], $utilities))
        ->toBe($utilities.'@property --tw-d{syntax:"*";inherits:false;initial-value:0}');

    $page = $builder->page($split, $graph, ['--tw-c', '--tw-d'], $utilities);

    expect($page)->toBe('@layer properties{@supports (x:y){*,:before{--tw-c:none}}}'
        .$utilities
        .'@property --tw-d{syntax:"*";inherits:false;initial-value:0}');

    assertNoUndefinedVariables($builder->root($split, $graph), $page, SUPPORT_CSS);
});

it('closes the names it is given rather than trusting the caller to have closed them', function (): void {
    // `--color-x` is declared in two blocks and `--wraps` reads it; a caller that seeded only the
    // outer name must still get the one its value reads, or the page emits `var(--color-x)` with
    // nothing behind it.
    $css = str_replace('--color-y:green', '--color-y:green;--wraps:var(--color-x)', SUPPORT_CSS);
    $split = (new StylesheetSplitter)->split($css);
    $graph = VariableGraph::fromSplit($split);
    $utilities = '@layer utilities{.f{color:var(--wraps)}}';

    $page = (new SupportCssBuilder)->page($split, $graph, ['--wraps'], $utilities);

    expect($page)->toBe('@layer theme{:root,:host{--color-x:red;--wraps:var(--color-x)}.dark{--color-x:blue}}'.$utilities);

    assertNoUndefinedVariables((new SupportCssBuilder)->root($split, $graph), $page, $css);
});

it('leaves the keyframe rule to the root even for the page that animates it', function (): void {
    $split = (new StylesheetSplitter)->split(SUPPORT_CSS);
    $graph = VariableGraph::fromSplit($split);
    $utilities = '@layer utilities{.c{animation:var(--animate-wiggle)}}';

    $page = (new SupportCssBuilder)->page($split, $graph, ['--animate-wiggle'], $utilities);
    $root = (new SupportCssBuilder)->root($split, $graph);

    // The theme value travels with the page that reads it; the rule it names is in the root, which
    // that page loads too.
    expect($page)->toBe('@layer theme{:root,:host{--animate-wiggle:wiggle 1s ease}}'.$utilities)
        ->and($page)->not->toContain('@keyframes')
        ->and($root)->toContain('@keyframes wiggle{to{transform:rotate(3deg)}}');

    assertNoUndefinedVariables($root, $page, SUPPORT_CSS);
});

it('gives the root every keyframe the stylesheet defines, whatever names them', function (): void {
    // Not one of these three roots reads `wiggle`: the first animates it, the second reaches it
    // through a theme value, and the third never mentions an animation at all. All three carry it.
    $direct = (new StylesheetSplitter)->split(str_replace('@layer base{a{color:var(--color-y)}}', '@layer base{a{animation:wiggle 1s}}', SUPPORT_CSS));
    $throughValue = (new StylesheetSplitter)->split(str_replace('@layer base{a{color:var(--color-y)}}', '@layer base{a{animation:var(--animate-wiggle)}}', SUPPORT_CSS));
    $unrelated = (new StylesheetSplitter)->split(SUPPORT_CSS);
    $builder = new SupportCssBuilder;

    expect($builder->rootKeyframes($direct))->toBe(['wiggle'])
        ->and($builder->root($direct, VariableGraph::fromSplit($direct)))->toEndWith('@keyframes wiggle{to{transform:rotate(3deg)}}')
        ->and($builder->rootKeyframes($throughValue))->toBe(['wiggle'])
        ->and($builder->root($throughValue, VariableGraph::fromSplit($throughValue)))->toContain('--animate-wiggle:wiggle 1s ease')
        ->and($builder->rootKeyframes($unrelated))->toBe(['wiggle'])
        ->and($builder->root($unrelated, VariableGraph::fromSplit($unrelated)))->toEndWith('@keyframes wiggle{to{transform:rotate(3deg)}}');
});

it('emits the statement alone for a layer the file needs nothing from', function (): void {
    $split = (new StylesheetSplitter)->split(SUPPORT_CSS);
    $graph = VariableGraph::fromSplit($split);
    $builder = new SupportCssBuilder;
    $utilities = '@layer utilities{.d{display:flex}}';

    // Neither support layer opens: an empty subset is no block at all, not an empty one.
    expect($builder->page($split, $graph, [], $utilities))->toBe($utilities)
        ->and($builder->page($split, $graph, [], ''))->toBe('')
        // A name nothing declares has nothing to emit for it either.
        ->and($builder->page($split, $graph, ['--nowhere'], $utilities))->toBe($utilities)
        ->and($builder->root($split, $graph))->toContain(SplitStylesheet::PROPERTIES_STATEMENT);
});

it('leaves the root whole and the page bare when the support layers could not be shaken', function (): void {
    $split = (new StylesheetSplitter)->split('@layer theme{:root{--x:1;color:red}}@layer utilities{.a{color:var(--x)}}');
    $graph = VariableGraph::fromSplit($split);
    $builder = new SupportCssBuilder;
    $utilities = '@layer utilities{.a{color:var(--x)}}';

    expect($split->supportShaken)->toBeFalse()
        ->and($builder->root($split, $graph))->toBe($split->root)
        ->and($builder->root($split, $graph))->toContain('--x:1')
        ->and($builder->rootNames($split, $graph))->toBe([])
        ->and($builder->rootKeyframes($split))->toBe([])
        ->and($builder->page($split, $graph, ['--x'], $utilities))->toBe($utilities);
});

it('leaves the fixture root with no theme declarations and gives the page the two it uses', function (): void {
    [$root, $page, $css, $split] = supportFiles('public/build/assets/app-test.css', ['flex', 'gap-2', 'rounded-lg']);

    // Nothing outside the utilities layer of this fixture reads a variable, so the whole theme layer
    // is the pages' to carry and the root keeps its statement.
    expect($root)->toBe($split->root)
        ->and($root)->toContain(SplitStylesheet::THEME_STATEMENT)
        ->and($root)->not->toContain('--spacing')
        ->and($page)->toStartWith('@layer theme{:root{--spacing:.25rem;--radius-lg:.5rem}}@layer utilities{')
        ->and($page)->toContain('.gap-2{gap:calc(var(--spacing) * 2)}');

    assertNoUndefinedVariables($root, $page, $css);
});

it('gives the Tailwind build root its font variables only, and the page everything else it uses', function (): void {
    $tokens = ['flex', 'animate-spin', 'gap-2', 'border', 'rounded-lg', 'space-y-6', 'text-red-500/50', 'hover:bg-gray-100', 'dark:bg-black'];
    [$root, $page, $css, $split] = supportFiles('public-tailwind/build/assets/app-tailwind.css', $tokens);
    $graph = VariableGraph::fromSplit($split);
    $builder = new SupportCssBuilder;

    expect($builder->rootNames($split, $graph))->toBe(['--default-font-family', '--default-mono-font-family', '--font-mono', '--font-sans'])
        ->and($builder->rootKeyframes($split))->toBe(['spin'])
        // The base layer's `font-family:var(--default-font-family,...)` is the whole of the root's
        // own reach, and each name it reaches drags in the one it resolves to.
        ->and($root)->toContain('@layer theme{:root,:host{--font-sans:-apple-system')
        ->and($root)->toContain('--default-font-family:var(--font-sans);--default-mono-font-family:var(--font-mono)}}')
        ->and($root)->toContain(SplitStylesheet::PROPERTIES_STATEMENT)
        ->and($root)->toContain(SplitStylesheet::UTILITIES_STATEMENT)
        ->and($root)->not->toContain('--color-red-500')
        ->and($root)->not->toContain('--spacing:')
        ->and($root)->not->toContain('@property')
        ->and($root)->toEndWith('@keyframes spin{to{transform:rotate(360deg)}}')
        ->and(strlen($root))->toBeLessThan(strlen($split->rootWithSupport()));

    expect($page)->toStartWith('@layer properties{@supports (((-webkit-hyphens:none))')
        ->and($page)->toContain('{*,:before,:after,::backdrop{--tw-space-y-reverse:0;--tw-border-style:solid}}}@layer theme{:root,:host{')
        // Source declaration order, and only the names this page's utilities reach.
        ->and($page)->toContain('--color-red-500:oklch(63.7% .237 25.331);--color-gray-100:oklch(96.7% .003 264.542);--color-black:#000;--spacing:.25rem;--radius-lg:.5rem;--animate-spin:spin 1s linear infinite}}@layer utilities{')
        ->and($page)->not->toContain('--font-sans')
        ->and($page)->not->toContain('@keyframes')
        ->and($page)->toEndWith('@property --tw-space-y-reverse{syntax:"*";inherits:false;initial-value:0}'
            .'@property --tw-border-style{syntax:"*";inherits:false;initial-value:solid}');

    assertNoUndefinedVariables($root, $page, $css);
});

it('pulls a variable into the page file that only the document names', function (): void {
    [$root, $page, $css] = supportFiles('public-tailwind/build/assets/app-tailwind.css', ['flex'], '<div style="color:var(--color-red-500)">x</div>');

    expect($page)->toContain('--color-red-500:oklch(63.7% .237 25.331)')
        ->and($page)->not->toContain('--color-gray-100')
        ->and($root)->not->toContain('--color-red-500');

    assertNoUndefinedVariables($root, $page, $css);
});

it('animates a page that never asked for an animation, out of the root', function (): void {
    // A page whose utilities are `.flex` alone. Under a per-page routing of keyframes its file would
    // carry none and neither would the root, so the `<style>` block below would animate nothing;
    // every keyframe living in the root is what makes both of these work with no configuration.
    $documentCss = '<style>.loader{animation:spin 2s linear infinite}</style>';
    [$root, $page, $css] = supportFiles('public-tailwind/build/assets/app-tailwind.css', ['flex'], $documentCss);

    expect($page)->not->toContain('@keyframes')
        ->and($page)->not->toContain('--animate-spin')
        ->and($root)->toContain('@keyframes spin{to{transform:rotate(360deg)}}');

    assertNoUndefinedVariables($root, $page, $css, $documentCss);
});

it('animates an inline style attribute out of the root as well', function (): void {
    $documentCss = '<div class="flex" style="animation:spin 1s linear infinite">x</div>';
    [$root, $page, $css] = supportFiles('public-tailwind/build/assets/app-tailwind.css', ['flex'], $documentCss);

    // Nothing about the inline animation reaches the page file — it does not have to.
    expect($page)->not->toContain('@keyframes')
        ->and($root)->toContain('@keyframes spin{to{transform:rotate(360deg)}}');

    assertNoUndefinedVariables($root, $page, $css, $documentCss);
});

it('carries both forms of a color-mix theme value into the page that uses it', function (): void {
    [$root, $page, $css] = supportFiles('public-tailwind/build/assets/app-tailwind-colormix.css', ['hover:bg-brand-hover']);

    // The static fallback and the `@supports`-wrapped `color-mix()` are two declarations of one
    // name, so both travel and the wrapper is reopened around the subset; `--color-brand` comes with
    // them because the wrapped value reads it.
    expect($page)->toBe('@layer theme{:root,:host{--color-brand:oklch(60% .2 250);--color-brand-hover:#0075cc}'
        .'@supports (color:color-mix(in lab, red, red)){:root,:host{--color-brand-hover:color-mix(in oklab, var(--color-brand) 90%, black)}}}'
        .'@layer utilities{@media (hover:hover){.hover\:bg-brand-hover:hover{background-color:var(--color-brand-hover)}}}')
        ->and($root)->not->toContain('--color-brand');

    assertNoUndefinedVariables($root, $page, $css);
});

it('defines every variable the two files read, for every class set of the compiled fixtures', function (): void {
    $sets = [
        'public/build/assets/app-test.css' => [
            [],
            ['flex'],
            ['gap-2', 'rounded-lg'],
            ['border', 'focus:outline-none', 'space-y-6', 'hover:bg-gray-100'],
            ['flex', 'flex-1', 'items-center', 'gap-2', 'rounded-lg', 'border', 'p-4', 'sm:flex', 'group-hover:flex', 'dark:bg-black', 'space-y-6', 'block', 'rounded', 'px-2', 'py-1', 'hover:bg-gray-100', 'sm:gap-4', 'opacity-50'],
        ],
        'public-tailwind/build/assets/app-tailwind.css' => [
            [],
            ['flex'],
            ['animate-spin'],
            ['space-y-6', 'border'],
            ['text-red-500/50', 'hover:bg-gray-100', 'dark:bg-black'],
            ['flex', 'animate-spin', 'items-center', 'gap-2', 'space-y-6', 'rounded-lg', 'border', 'p-4', 'text-red-500/50', 'group-hover:flex', 'peer-checked:block', 'hover:bg-gray-100', 'sm:flex', '2xl:p-4', 'dark:bg-black', '[&_p]:m-0'],
        ],
        // The two shapes `app-tailwind.css` does not cover: a `color-mix()` theme value inside its
        // `@supports` wrapper, and a `prefix(tw)` build whose theme shares the `--tw-*` namespace.
        'public-tailwind/build/assets/app-tailwind-colormix.css' => [
            [],
            ['bg-brand'],
            ['hover:bg-brand-hover', 'text-white', 'p-4'],
        ],
        'public-tailwind/build/assets/app-tailwind-prefix.css' => [
            ['tw:flex'],
            ['tw:animate-spin', 'tw:gap-2', 'tw:border', 'tw:hover:bg-gray-100'],
        ],
        // A layer-less build: nothing to shake, every `--tw-*` and user variable declared in the
        // root, the keyframe lifted out of the utilities into the root.
        'public-tailwind3/build/assets/app-tailwind3.css' => [
            [],
            ['flex'],
            ['animate-spin', 'bg-[var(--brand)]'],
            ['space-y-6', 'dark:bg-black', '2xl:p-4', 'text-red-500/50'],
            ['flex', 'animate-spin', 'items-center', 'gap-2', 'space-y-6', 'rounded-lg', 'border', 'p-4', 'text-red-500/50', 'group-hover:flex', 'peer-checked:block', 'hover:bg-gray-100', 'sm:flex', '2xl:p-4', 'dark:bg-black', '[&_p]:m-0', 'prose', 'bg-[var(--brand)]'],
        ],
        // No custom properties at all: the invariant has nothing to find, and must find nothing.
        'public-tachyons/build/assets/app-tachyons.css' => [
            [],
            ['flex', 'pa3'],
            ['hover-white', 'hide-child', 'child', 'flex-ns', 'w-50-m', 'dim', 'grow'],
        ],
        // Every `--bs-*` a component reads is declared on `:root` in the root or in the component's
        // own rule; the spinner and stripe keyframes are the root's.
        'public-bootstrap5/build/assets/app-bootstrap5.css' => [
            [],
            ['btn', 'btn-primary'],
            ['container', 'row', 'col-md-6', 'navbar', 'navbar-expand-lg', 'dropdown-menu', 'spinner-border', 'progress-bar-striped', 'progress-bar-animated', 'form-control', 'is-invalid', 'modal', 'fade', 'modal-backdrop', 'tooltip'],
        ],
        // The theme rules that hold every variable variant are unconditional (a class beside an
        // attribute selector), so the root's `:root` and the page's theme rules define everything.
        'public-bulma/build/assets/app-bulma.css' => [
            [],
            ['button', 'is-primary'],
            ['container', 'columns', 'column', 'is-half', 'navbar', 'navbar-burger', 'is-active', 'dropdown', 'modal', 'tabs', 'button', 'is-loading', 'progress', 'input', 'is-danger', 'title', 'is-hidden-mobile'],
        ],
        // No custom properties and no keyframes at all: the invariant must find nothing to check.
        'public-foundation/build/assets/app-foundation.css' => [
            [],
            ['button', 'primary'],
            ['grid-container', 'grid-x', 'grid-margin-x', 'cell', 'small-12', 'medium-6', 'top-bar', 'menu', 'dropdown-pane', 'is-open', 'reveal', 'reveal-overlay', 'callout', 'alert', 'input-group', 'is-invalid-input', 'tooltip'],
        ],
    ];

    foreach ($sets as $fixture => $tokenSets) {
        foreach ($tokenSets as $tokens) {
            $documentCss = '<div style="--gap:1rem;color:var(--color-black);animation:spin 1s">x</div>';
            [$root, $page, $css, $split] = supportFiles($fixture, $tokens, $documentCss);

            assertNoUndefinedVariables($root, $page, $css, $documentCss);
        }
    }
});
