<?php

declare(strict_types=1);

use Daikazu\BladeWind\Pages\Drivers\Tailwind4Driver;
use Daikazu\BladeWind\Pages\HtmlVariableScanner;
use Daikazu\BladeWind\Pages\PageCssBuilder;
use Daikazu\BladeWind\Pages\StylesheetSplitter;
use Daikazu\BladeWind\Pages\SupportCssBuilder;
use Daikazu\BladeWind\Pages\UtilityRuleIndex;
use Daikazu\BladeWind\Pages\VariableGraph;

/**
 * The root file and one page's file for a fixture, built the way the request path builds them, and
 * digested. The digests hold refactors behind the CSS-framework driver seam to byte-identical
 * Tailwind 4 output: a digest that moves is either a deliberate change to what a page receives
 * (update the digest and say why in the commit) or a regression.
 *
 * @param  list<string>  $tokens
 * @return array{0: string, 1: string} the root file's digest and the page file's digest
 */
function goldenDigests(string $fixture, array $tokens, string $html = ''): array
{
    $css = (string) file_get_contents(test()->fixturePath($fixture));
    $driver = new Tailwind4Driver(new StylesheetSplitter);
    $split = $driver->split($css);
    $graph = VariableGraph::fromSplit($split);
    $builder = new SupportCssBuilder;

    $utilities = $driver->wrapUtilities((new PageCssBuilder)->build($tokens, UtilityRuleIndex::build($split->utilities, $driver->indexTokens(...))));
    $seeds = [...VariableGraph::refs($utilities), ...HtmlVariableScanner::names($html)];
    $names = $graph->closure(array_values(array_diff($graph->closure($seeds), $builder->rootNames($split, $graph))));

    return [
        hash('xxh128', $builder->root($split, $graph)),
        hash('xxh128', $builder->page($split, $graph, $names, $utilities)),
    ];
}

/**
 * @return array<string, array{0: string, 1: list<string>, 2: string}> fixture, tokens, HTML
 */
function goldenSets(): array
{
    $tokens = ['flex', 'items-center', 'gap-2', 'p-4', 'dark:bg-black', 'hover:bg-gray-100', 'sm:flex', '2xl:p-4', 'group-hover:flex', 'peer-checked:block', '[&_p]:m-0', 'animate-spin', 'space-y-6', 'text-red-500/50'];
    $html = '<div style="color:var(--color-red-500)"></div>';

    return [
        'app-test empty' => ['public/build/assets/app-test.css', [], ''],
        'app-test' => ['public/build/assets/app-test.css', ['flex', 'p-4', 'sm:flex', 'block', 'dark:bg-black'], $html],
        'app-tailwind empty' => ['public-tailwind/build/assets/app-tailwind.css', [], ''],
        'app-tailwind' => ['public-tailwind/build/assets/app-tailwind.css', $tokens, $html],
        'app-tailwind-colormix' => ['public-tailwind/build/assets/app-tailwind-colormix.css', [...$tokens, 'bg-brand', 'hover:bg-brand-hover'], $html],
        'app-tailwind-prefix' => ['public-tailwind/build/assets/app-tailwind-prefix.css', array_map(static fn (string $token): string => 'tw:'.$token, $tokens), $html],
    ];
}

const GOLDEN_DIGESTS = [
    'app-test empty' => ['bde763fb8eee6a562c1f70a68aa74d22', '99aa06d3014798d86001c324468d497f'],
    'app-test' => ['bde763fb8eee6a562c1f70a68aa74d22', '4dd6021356bf5cfcf60c29f51348a959'],
    'app-tailwind empty' => ['a70da4d730b5fb815b09cb629ade3866', '570d4c7f78a23f444faa3eb1cebf25e2'],
    'app-tailwind' => ['a70da4d730b5fb815b09cb629ade3866', '4c542cecc2d7ca91b4240891a189273a'],
    'app-tailwind-colormix' => ['dfe12cad4409ca692bd06d3b75ab5f3b', '493d4d9e1c94135f5c6f373d2a53c950'],
    'app-tailwind-prefix' => ['281e3427e8c6f839cffa3a0cf080a5f3', '4a3de6d024b15b1f9e0f73ff68ebaffe'],
];

it('produces byte-identical root and page files for every compiled fixture', function (string $fixture, array $tokens, string $html, array $expected): void {
    expect(goldenDigests($fixture, $tokens, $html))->toBe($expected);
})->with(function (): array {
    $expected = GOLDEN_DIGESTS;
    $cases = [];

    foreach (goldenSets() as $name => [$fixture, $tokens, $html]) {
        $cases[$name] = [$fixture, $tokens, $html, $expected[$name]];
    }

    return $cases;
});
