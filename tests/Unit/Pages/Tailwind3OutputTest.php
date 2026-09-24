<?php

declare(strict_types=1);

use Daikazu\BladeWind\Pages\Drivers\Tailwind3Driver;
use Daikazu\BladeWind\Pages\FlatStylesheetSplitter;
use Daikazu\BladeWind\Pages\PageCssBuilder;
use Daikazu\BladeWind\Pages\SupportCssBuilder;
use Daikazu\BladeWind\Pages\UtilityRuleIndex;
use Daikazu\BladeWind\Pages\VariableGraph;

/**
 * The stylesheet these tests read is output the real Tailwind 3 compiler produced from
 * tests/fixtures/tailwind3 (regenerate with `npm install && npm run build` there), the way
 * `TailwindOutputTest` reads a real Tailwind 4 build.
 */
function tailwind3Index(): UtilityRuleIndex
{
    $driver = new Tailwind3Driver(new FlatStylesheetSplitter);

    return UtilityRuleIndex::build($driver->split(tailwind3FixtureCss())->utilities, $driver->indexTokens(...));
}

it('attributes every rule after the cut to a token or to every page', function (): void {
    $index = tailwind3Index();
    $positions = [];

    foreach ($index->tokens() as $token) {
        foreach ($index->rulesFor($token) as $rule) {
            $positions[$rule->position] = true;
        }
    }

    foreach ($index->unconditional() as $rule) {
        $positions[$rule->position] = true;
    }

    expect($index->count())->toBeGreaterThan(15)
        ->and(count($positions))->toBe($index->count())
        ->and($index->unconditional())->toHaveCount(1)
        ->and($index->unconditional()[0]->selector)->toBe('[x-cloak]')
        // A page carrying class="group", class="peer" or <html class="dark"> pulls in no variant
        // rule of its own: each is filed under the utility on the element it styles.
        ->and($index->rulesFor('group'))->toBe([])
        ->and($index->rulesFor('peer'))->toBe([])
        ->and($index->rulesFor('dark'))->toBe([])
        ->and($index->rulesFor('group-hover:flex'))->toHaveCount(1)
        ->and($index->rulesFor('peer-checked:block'))->toHaveCount(1)
        ->and($index->rulesFor('dark:bg-black'))->toHaveCount(1)
        ->and($index->rulesFor('prose')[0]->selector)->toBe('.prose h1')
        ->and($index->rulesFor('[&_p]:m-0')[0]->selector)->toBe('.\[\&_p\]\:m-0 p');
});

it('builds a bare page from real Tailwind 3 output with the rules its tokens need and nothing else', function (): void {
    $driver = new Tailwind3Driver(new FlatStylesheetSplitter);
    $css = $driver->wrapUtilities((new PageCssBuilder)->build(['flex', 'dark:bg-black', 'sm:flex'], tailwind3Index()));

    expect($css)->not->toContain('@layer')
        ->toContain('[x-cloak]{display:none!important}')
        ->toContain('.flex{display:flex}')
        ->toContain('.dark\:bg-black:is(.dark *){')
        ->toContain('@media (width>=640px){.sm\:flex{display:flex}}')
        ->not->toContain('.animate-spin')
        ->not->toContain('.items-center')
        ->not->toContain('@keyframes');
});

it('gives the root every keyframe and the page nothing but its utilities, with every variable defined', function (): void {
    $driver = new Tailwind3Driver(new FlatStylesheetSplitter);
    $stylesheet = tailwind3FixtureCss();
    $split = $driver->split($stylesheet);
    $graph = VariableGraph::fromSplit($split);
    $builder = new SupportCssBuilder;
    $utilities = $driver->wrapUtilities((new PageCssBuilder)->build(['animate-spin', 'space-y-6', 'bg-[var(--brand)]'], UtilityRuleIndex::build($split->utilities, $driver->indexTokens(...))));

    $root = $builder->root($split, $graph);
    $page = $builder->page($split, $graph, $graph->closure(VariableGraph::refs($utilities)), $utilities);

    // The graph has nothing to declare: `--tw-space-y-reverse` and `--brand` are plain declarations
    // in the root, which is where the invariant finds them.
    expect($graph->closure(VariableGraph::refs($utilities)))->toBe([])
        ->and($root)->toBe($split->root.'@keyframes spin{to{transform:rotate(360deg)}}')
        ->and($page)->toBe($utilities)
        ->and($page)->toContain('.animate-spin{animation:1s linear infinite spin}');

    assertNoUndefinedVariables($root, $page, $stylesheet, '<div style="animation:spin 1s;color:var(--brand)"></div>');
});
