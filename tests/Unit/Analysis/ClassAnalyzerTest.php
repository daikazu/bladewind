<?php

declare(strict_types=1);

use Daikazu\BladeWind\Analysis\ClassAnalysis;
use Daikazu\BladeWind\Classes\ClassGroup;
use Daikazu\BladeWind\Classes\GroupClassification;

function groupsOf(ClassAnalysis $analysis): array
{
    return array_map(fn (ClassGroup $g) => [$g->on, $g->tag, $g->source, $g->classification, $g->tokens, array_map(fn ($c) => [$c->tokens, $c->condition], $g->conditional), $g->unresolved, $g->bag, $g->line], $analysis->groups);
}

it('records static, enumerable, mixed, and unresolved class attributes', function (): void {
    $analysis = analyzeClasses(<<<'BLADE'
<div class="flex gap-2">
<span class="{{ $active ? 'bg-blue-500' : 'bg-gray-500' }}">a</span>
<span class="p-2 {{ $extra }}">b</span>
<span class="bg-{{ $color }}-500">c</span>
</div>
BLADE);

    expect(groupsOf($analysis))->toBe([
        ['element', 'div', 'attribute', GroupClassification::Static, ['flex', 'gap-2'], [], [], false, 1],
        ['element', 'span', 'attribute', GroupClassification::Enumerable, [], [[['bg-blue-500'], '$active'], [['bg-gray-500'], '$active']], [], false, 2],
        ['element', 'span', 'attribute', GroupClassification::Mixed, ['p-2'], [], ['$extra'], false, 3],
        ['element', 'span', 'attribute', GroupClassification::Unresolved, [], [], ['bg-{{ $color }}-500'], false, 4],
    ])
        ->and(array_map(fn ($u) => [$u->kind, $u->expression, $u->line], $analysis->unresolved))->toBe([
            ['class-attribute', '$extra', 3], ['class-attribute', 'bg-{{ $color }}-500', 4],
        ])
        ->and(array_map(fn ($d) => $d->code.'@'.$d->line, $analysis->diagnostics))->toBe(['BW2001@3', 'BW2001@4']);
});

it('reads class helpers in attribute position, in class attribute echoes, and as directives', function (): void {
    $analysis = analyzeClasses(<<<'BLADE'
<section @class(['rounded-lg p-4', 'shadow' => $raised, $extra]) {{ $attributes->merge(['class' => 'bg-white']) }}>
<div class="{{ Arr::toCssClasses(['border' => $bordered]) }}">x</div>
<span {{ $attributes->class(['px-2', 'font-bold' => $strong]) }}></span>
<i {{ $attributes }}></i>
</section>
BLADE);

    expect(groupsOf($analysis))->toBe([
        ['element', 'section', 'class-directive', GroupClassification::Mixed, ['rounded-lg', 'p-4'], [[['shadow'], '$raised']], ['$extra'], false, 1],
        ['element', 'section', 'attributes-merge', GroupClassification::Static, ['bg-white'], [], [], true, 1],
        ['element', 'div', 'to-css-classes', GroupClassification::Enumerable, [], [[['border'], '$bordered']], [], false, 2],
        ['element', 'span', 'attributes-class', GroupClassification::Enumerable, ['px-2'], [[['font-bold'], '$strong']], [], true, 3],
        ['element', 'i', 'attributes', GroupClassification::Static, [], [], [], true, 4],
    ])
        ->and(array_map(fn ($d) => [$d->code, $d->line], $analysis->diagnostics))->toBe([['BW2004', 1]])
        ->and($analysis->diagnostics[0]->message)->toContain('@class element $extra');
});

it('records an $attributes chain it cannot read as unresolved, keeping every literal as a candidate', function (): void {
    // The idiomatic component roots: a method the analyser does not read, or a chain past the one
    // it does. None of these may look complete: the conditional `hidden` and the `p-19` a later
    // call adds are future states the rendered HTML cannot show.
    $analysis = analyzeClasses(<<<'BLADE'
<div {{ $attributes->except('wire:model')->class(['p-6', 'hidden' => $h]) }}>a</div>
<div {{ $attributes->class(['p-16'])->merge(['class' => 'p-17']) }}>b</div>
<div {{ $attributes->class(['p-18'])->when(true, fn ($a) => $a->class('p-19')) }}>c</div>
<div {{ $attributes->twMerge('p-10 mt-2') }}>d</div>
<div {{ $attrs }}>e</div>
BLADE);

    $groups = groupsOf($analysis);

    expect(array_map(fn ($g) => [$g[2], $g[3]], $groups))->toBe([
        ['attributes', GroupClassification::Unresolved],
        ['attributes-class', GroupClassification::Mixed],
        ['attributes-class', GroupClassification::Mixed],
        ['attributes', GroupClassification::Unresolved],
    ])
        ->and($groups[1][4])->toBe(['p-16'])
        ->and($groups[2][4])->toBe(['p-18'])
        ->and(array_map(fn ($d) => [$d->code, $d->line], $analysis->diagnostics))->toBe([['BW2004', 1], ['BW2004', 2], ['BW2004', 3], ['BW2004', 4]])
        ->and(array_merge(...array_map(fn ($c) => $c->tokens, $analysis->candidates)))->toContain('p-6', 'hidden', 'p-17', 'p-19', 'p-10', 'mt-2');
});

it('keeps every string in the other Alpine attributes as a candidate, since :class can read them', function (): void {
    // Classes held in x-data, an x-bind object or a classList call never pass through a :class
    // binding the analyser can enumerate; the strings are still right there in the markup.
    $analysis = analyzeClasses(<<<'BLADE'
<div x-data="{ on: 'p-55', off: 'p-56' }" :class="active ? on : off"></div>
<div x-data="{ btn: { ':class'() { return this.open ? 'p-57' : 'p-58' } } }"><button x-bind="btn">x</button></div>
<div @click="$el.classList.add('p-59')" x-init="$el.classList.toggle('p-60')" x-on:mouseenter="$el.classList.add('p-61')"></div>
<div :title="'not-a-class-attr'" x-transition:enter="p-62"></div>
BLADE);

    $candidates = array_merge(...array_map(fn ($c) => $c->tokens, $analysis->candidates));

    expect($candidates)->toContain('p-55', 'p-56', 'p-57', 'p-58', 'p-59', 'p-60', 'p-61')
        ->and(array_unique(array_map(fn ($c) => $c->origin, $analysis->candidates)))->toContain('alpine-attribute')
        // The transition list is a runtime entry, not a candidate: it is enumerated outright.
        ->and(array_map(fn ($r) => $r->tokens, $analysis->runtime))->toContain(['p-62']);
});

it('treats a bound class on a component tag as php and reports unresolved parts', function (): void {
    $analysis = analyzeClasses('<x-chip :class="$tone === \'a\' ? \'text-red-500\' : classes($x)" class="m-1" />');

    expect(groupsOf($analysis))->toBe([
        ['component', 'x-chip', 'attribute', GroupClassification::Mixed, [], [[['text-red-500'], "\$tone === 'a'"]], ['classes($x)'], false, 1],
        ['component', 'x-chip', 'attribute', GroupClassification::Static, ['m-1'], [], [], false, 1],
    ])
        ->and($analysis->unresolved[0]->kind)->toBe('php-bound-class')
        ->and($analysis->diagnostics[0]->code)->toBe('BW2001');
});

it('collects runtime classes through the adapters with candidates and diagnostics', function (): void {
    $analysis = analyzeClasses(<<<'BLADE'
<button wire:loading.class="opacity-50" :class="{ 'hidden': !open }" x-bind:class="themeClasses[current] || 'block'">x</button>
<div :class="pick('bg-red-500 text-white')"></div>
BLADE);

    expect(array_map(fn ($r) => [$r->adapter, $r->attribute, $r->tokens, $r->classification->value], $analysis->runtime))->toBe([
        ['livewire', 'wire:loading.class', ['opacity-50'], 'static'],
        ['alpine', ':class', ['hidden'], 'enumerable'],
        ['alpine', 'x-bind:class', ['block'], 'partial'],
        ['alpine', ':class', [], 'unresolved'],
    ])
        ->and(array_map(fn ($c) => [$c->origin, $c->tokens, $c->line], $analysis->candidates))->toBe([
            ['alpine', ['bg-red-500', 'text-white'], 2],
        ])
        ->and(array_map(fn ($u) => [$u->kind, $u->expression], $analysis->unresolved))->toBe([
            ['alpine', 'themeClasses[current]'], ['alpine', "pick('bg-red-500 text-white')"],
        ])
        ->and(array_map(fn ($d) => $d->code, $analysis->diagnostics))->toBe(['BW2002', 'BW2002'])
        ->and($analysis->diagnostics[1]->message)->toContain('Recorded candidates: bg-red-500, text-white');
});

it('scans php blocks for candidates and orders everything by position', function (): void {
    $analysis = analyzeClasses("@php \$fallback = 'text-gray-400 italic'; @endphp\n<p class=\"mt-1\"></p>");

    expect(array_map(fn ($c) => [$c->origin, $c->tokens, $c->line], $analysis->candidates))->toBe([['php-block', ['text-gray-400', 'italic'], 1]])
        ->and($analysis->groups[0]->line)->toBe(2);
});

it('falls through to the mixed-value path instead of dropping a second echo after a helper echo', function (): void {
    $analysis = analyzeClasses('<div class="{{ Arr::toCssClasses([\'a\' => $x]) }} {{ $extra }}"></div>');

    expect(groupsOf($analysis))->toBe([
        ['element', 'div', 'attribute', GroupClassification::Unresolved, [], [], ["Arr::toCssClasses(['a' => \$x])", '$extra'], false, 1],
    ])
        ->and(array_map(fn ($u) => [$u->kind, $u->expression], $analysis->unresolved))->toBe([
            ['class-attribute', "Arr::toCssClasses(['a' => \$x]), \$extra"],
        ])
        ->and(array_map(fn ($d) => $d->code, $analysis->diagnostics))->toBe(['BW2001']);
});

it('does not lose a helper argument due to raw bracket counting inside a string literal', function (): void {
    $analysis = analyzeClasses(<<<'BLADE'
<span {{ $attributes->class(['a' => $x, 'content-[\'(\']' => true]) }}></span>
BLADE);

    expect($analysis->groups[0]->classification)->toBe(GroupClassification::Enumerable)
        ->and($analysis->groups[0]->unresolved)->toBe([])
        ->and(array_map(fn ($c) => [$c->tokens, $c->condition], $analysis->groups[0]->conditional))->toBe([
            [['a'], '$x'],
            [["content-['(']"], 'true'],
        ])
        ->and($analysis->diagnostics)->toBe([]);
});

it('describes a missing array argument instead of leaving it blank', function (): void {
    $analysis = analyzeClasses('<span {{ $attributes->class() }}></span>');

    expect($analysis->diagnostics[0]->message)->toBe("\$attributes->class element (no array argument) is not a string literal or a 'classes' => condition pair and was skipped.");
});

it('does not treat FooArr::toCssClasses as the Arr helper', function (): void {
    $analysis = analyzeClasses('<div class="{{ FooArr::toCssClasses([\'a\' => $x]) }}"></div>');

    expect($analysis->groups[0]->source)->toBe(ClassGroup::SOURCE_ATTRIBUTE)
        ->and($analysis->groups[0]->classification)->toBe(GroupClassification::Unresolved)
        ->and($analysis->groups[0]->unresolved)->toBe(["FooArr::toCssClasses(['a' => \$x])"]);
});
