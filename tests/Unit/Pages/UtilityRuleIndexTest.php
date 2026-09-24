<?php

declare(strict_types=1);

use Daikazu\BladeWind\Pages\IndexedRule;
use Daikazu\BladeWind\Pages\SelectorTokens;
use Daikazu\BladeWind\Pages\StylesheetSplitter;
use Daikazu\BladeWind\Pages\UtilityRuleIndex;

it('indexes every utilities rule under each class its selector names', function (): void {
    $css = (string) file_get_contents($this->fixturePath('public/build/assets/app-test.css'));
    $index = UtilityRuleIndex::build((new StylesheetSplitter)->split($css)->utilities);

    $flex = $index->rulesFor('flex');
    $sm = $index->rulesFor('sm:flex');
    $space = $index->rulesFor('space-y-6');
    $p4 = $index->rulesFor('p-4');

    expect($flex)->toHaveCount(1)->and($flex[0]->selector)->toBe('.flex')->and($flex[0]->body)->toBe('display:flex')->and($flex[0]->wrappers)->toBe([])
        ->and($sm)->toHaveCount(1)->and($sm[0]->wrappers)->toBe(['@media (width>=40rem)'])
        ->and($space)->toHaveCount(1)->and($space[0]->selector)->toBe(':where(.space-y-6>:not(:last-child))')
        ->and($p4)->toHaveCount(1)->and($p4[0]->selector)->toBe('.p-4,.px-4')
        ->and($index->rulesFor('px-4')[0]->position)->toBe($p4[0]->position)
        ->and($index->rulesFor('p-40'))->toBe([])
        // A marker class a variant tests for is not what its rule is about: `group-hover:flex` is
        // indexed, `group` is not, so a page carrying class="group" pulls in no variant rules.
        ->and($index->rulesFor('group'))->toBe([])
        ->and($index->rulesFor('group-hover:flex'))->toHaveCount(1)
        ->and($index->rulesFor('dark'))->toBe([])
        ->and($index->rulesFor('dark:bg-black'))->toHaveCount(1)
        ->and($index->rulesFor('missing'))->toBe([])
        ->and($index->count())->toBeGreaterThan(10);
});

it('carries a rule owned by no class into every page, even when a class appears inside :not() or beside a class-less alternative', function (): void {
    $index = UtilityRuleIndex::build('p:not(.a){x:1}:is(.a,p){x:2}.b{x:3}');

    expect(array_map(static fn (IndexedRule $rule): string => $rule->selector, $index->unconditional()))->toBe(['p:not(.a)', ':is(.a,p)'])
        ->and($index->rulesFor('a'))->toBe([])
        ->and($index->rulesFor('b'))->toHaveCount(1);
});

it('keeps rules in stylesheet order and ignores braces inside strings and comments', function (): void {
    $index = UtilityRuleIndex::build('.a{content:"}"}/* .b{ */@supports (x:y){.b{x:y}.c\:d{x:"{"}}.e{x:y}');

    expect(array_map(static fn (IndexedRule $rule): string => $rule->selector, [...$index->rulesFor('a'), ...$index->rulesFor('b'), ...$index->rulesFor('c:d'), ...$index->rulesFor('e')]))->toBe(['.a', '.b', '.c\:d', '.e'])
        ->and($index->rulesFor('b')[0]->wrappers)->toBe(['@supports (x:y)'])
        ->and($index->rulesFor('a')[0]->position)->toBeLessThan($index->rulesFor('e')[0]->position);
});

it('records rules no class token can be responsible for as unconditional', function (): void {
    $index = UtilityRuleIndex::build('.flex{display:flex}[x-cloak]{display:none!important}@keyframes wiggle{to{transform:rotate(3deg)}}a[href$=".pdf"]:after{content:"pdf"}@font-face{font-family:x}');

    $unconditional = array_map(static fn (IndexedRule $rule): string => $rule->selector.'{'.$rule->body.'}', $index->unconditional());

    expect($unconditional)->toBe([
        '[x-cloak]{display:none!important}',
        '@keyframes wiggle{to{transform:rotate(3deg)}}',
        'a[href$=".pdf"]:after{content:"pdf"}',
        '@font-face{font-family:x}',
    ])
        // A keyframe step is part of the at-rule's body, never a rule of its own.
        ->and($index->count())->toBe(5)
        ->and($index->rulesFor('pdf'))->toBe([])
        ->and($index->rulesFor('flex'))->toHaveCount(1)
        ->and($index->unconditional())->not->toContain($index->rulesFor('flex')[0]);
});

it('keeps a wrapper at-rule around an unconditional rule and indexes the rules inside it', function (): void {
    $index = UtilityRuleIndex::build('@media (width>=40rem){[x-cloak]{display:none}.sm\:flex{display:flex}}');

    expect($index->unconditional())->toHaveCount(1)
        ->and($index->unconditional()[0]->selector)->toBe('[x-cloak]')
        ->and($index->unconditional()[0]->wrappers)->toBe(['@media (width>=40rem)'])
        ->and($index->rulesFor('sm:flex'))->toHaveCount(1);
});

it('folds a nested style rule into its enclosing rule instead of indexing it on its own', function (): void {
    $index = UtilityRuleIndex::build('.parent{color:red;.child{color:blue}}');

    expect($index->count())->toBe(1)
        ->and($index->rulesFor('child'))->toHaveCount(1)->and($index->rulesFor('child')[0]->selector)->toBe('.parent')
        ->and($index->rulesFor('parent'))->toHaveCount(1)->and($index->rulesFor('parent')[0]->selector)->toBe('.parent')
        ->and($index->rulesFor('child')[0])->toBe($index->rulesFor('parent')[0]);

    $ordered = UtilityRuleIndex::build('.b{x:y}.a{color:red;.a{color:blue}}')->rulesFor('a');

    expect($ordered)->not->toBeEmpty();

    $positions = array_map(static fn (IndexedRule $rule): int => $rule->position, $ordered);

    expect($positions)->toBe(collect($positions)->sort()->values()->all());
});

it('carries a rule unconditionally when any selector in its list names no class', function (): void {
    $index = UtilityRuleIndex::build('.a{x:1}code,.code{x:2}.border-box,a,article{x:3}.b:not(.c),p{x:4}.d,.e{x:5}');

    // `code,.code` styles every code element and `.border-box,a,article` every link, whatever the
    // page's classes: neither is owned by its class token, so both reach every page.
    expect(array_map(static fn ($rule): string => $rule->selector, $index->unconditional()))->toBe(['code,.code', '.border-box,a,article', '.b:not(.c),p'])
        ->and($index->rulesFor('code'))->toBe([])
        ->and($index->rulesFor('border-box'))->toBe([])
        ->and($index->rulesFor('b'))->toBe([])
        ->and($index->rulesFor('d'))->toHaveCount(1)
        ->and($index->rulesFor('e'))->toHaveCount(1)
        ->and($index->tokens())->toBe(['a', 'd', 'e']);
});

it('indexes under whatever reading of a selector list it is given', function (): void {
    $css = '.group:hover .group-hover\\:flex{display:flex}.b{x:y}';

    // The default reading files the marker class too; a reading that keeps only the last class
    // of each selector does not. Which one is right is the CSS framework's call.
    $default = UtilityRuleIndex::build($css);
    $last = UtilityRuleIndex::build($css, static fn (string $selectorList): array => array_slice(SelectorTokens::tokens($selectorList), -1));

    expect($default->rulesFor('group'))->toHaveCount(1)
        ->and($default->rulesFor('group-hover:flex'))->toHaveCount(1)
        ->and($last->rulesFor('group'))->toBe([])
        ->and($last->rulesFor('group-hover:flex'))->toHaveCount(1)
        ->and($last->rulesFor('b'))->toHaveCount(1)
        ->and($last->tokens())->toBe(['group-hover:flex', 'b']);
});
