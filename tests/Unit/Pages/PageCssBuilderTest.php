<?php

declare(strict_types=1);

use Daikazu\BladeWind\Pages\PageCssBuilder;
use Daikazu\BladeWind\Pages\StylesheetSplitter;
use Daikazu\BladeWind\Pages\UtilityRuleIndex;

function fixtureUtilityIndex(): UtilityRuleIndex
{
    $css = (string) file_get_contents(test()->fixturePath('public/build/assets/app-test.css'));

    return UtilityRuleIndex::build((new StylesheetSplitter)->split($css)->utilities);
}

it('emits only the rules the tokens need, in stylesheet order, with wrappers grouped', function (): void {
    $css = (new PageCssBuilder)->build(['sm:gap-4', 'p-4', 'flex', 'sm:flex', 'js-hook', 'block'], fixtureUtilityIndex());

    // The run comes back bare; the caller wraps it in whatever layer the stylesheet uses.
    expect($css)->not->toContain('@layer')
        ->and($css)->toContain('.flex{display:flex}')
        ->and($css)->toContain('.p-4,.px-4{padding:calc(var(--spacing) * 4)}')
        ->and($css)->toContain('@media (width>=40rem){.sm\:flex{display:flex}}')
        ->and($css)->toContain('@media (width>=40rem){.sm\:gap-4{gap:calc(var(--spacing) * 4)}}')
        ->and($css)->toContain('.block{display:block}')
        ->and($css)->not->toContain('.items-center')
        ->and($css)->not->toContain('.dark\:bg-black')
        ->and(strpos($css, '.flex{'))->toBeLessThan(strpos($css, '.p-4,'))
        ->and(strpos($css, '.p-4,'))->toBeLessThan(strpos($css, '.block{'))
        ->and(substr_count($css, '.p-4,.px-4'))->toBe(1)
        ->and(substr_count($css, '.flex{display:flex}'))->toBe(1);
});

it('emits the unconditional rules of the layer whatever the page uses, even for an empty set', function (): void {
    $index = UtilityRuleIndex::build('.flex{display:flex}[x-cloak]{display:none!important}@keyframes wiggle{to{transform:rotate(3deg)}}.block{display:block}');

    expect((new PageCssBuilder)->build([], $index))->toBe('[x-cloak]{display:none!important}@keyframes wiggle{to{transform:rotate(3deg)}}')
        ->and((new PageCssBuilder)->build(['flex'], $index))->toBe('.flex{display:flex}[x-cloak]{display:none!important}@keyframes wiggle{to{transform:rotate(3deg)}}')
        // An unconditional rule is emitted once, at its own position, however it is reached.
        ->and(substr_count((new PageCssBuilder)->build(['flex', 'block'], $index), '[x-cloak]'))->toBe(1);
});

it('merges consecutive rules that share a wrapper into one block and returns nothing for unknown tokens', function (): void {
    $index = UtilityRuleIndex::build('@media (x){.a{x:1}.b{x:2}}.c{x:3}@media (x){.d{x:4}}');

    expect((new PageCssBuilder)->build(['a', 'b', 'c', 'd'], $index))->toBe('@media (x){.a{x:1}.b{x:2}}.c{x:3}@media (x){.d{x:4}}')
        ->and((new PageCssBuilder)->build(['a', 'd'], $index))->toBe('@media (x){.a{x:1}}@media (x){.d{x:4}}')
        ->and((new PageCssBuilder)->build(['zzz'], $index))->toBe('');
});

it('re-emits an @layer order statement in position, so a page file keeps the stylesheet\'s layer order', function (): void {
    // Application CSS after a flat framework's first class rule: `@layer a, b;` says b outranks a
    // whatever order their blocks appear in. Dropping the statement lets first appearance decide,
    // and `.k` resolves the other way round.
    $index = UtilityRuleIndex::build('@layer a,b;@layer b{.k{x:2}}@layer a{.k{x:3}}.z{x:4}');

    expect((new PageCssBuilder)->build(['k'], $index))->toBe('@layer a,b;@layer b{.k{x:2}}@layer a{.k{x:3}}')
        ->and((new PageCssBuilder)->build(['z'], $index))->toBe('@layer a,b;.z{x:4}');

    // Inside a wrapper the statement stays with its wrapper, and a statement between two selected
    // rules keeps the run contiguous.
    $wrapped = UtilityRuleIndex::build('@media (x){@layer a,b;.m{x:1}}.n{x:2}@layer c;.o{x:3}');

    expect((new PageCssBuilder)->build(['m', 'n', 'o'], $wrapped))->toBe('@media (x){@layer a,b;.m{x:1}}.n{x:2}@layer c;.o{x:3}')
        // A declaration's semicolon inside a rule is not a statement.
        ->and((new PageCssBuilder)->build(['p'], UtilityRuleIndex::build('.p{a:1;b:2}')))->toBe('.p{a:1;b:2}');
});
