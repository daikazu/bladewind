<?php

declare(strict_types=1);

use Daikazu\BladeWind\Analysis\ViewAnalyzer;
use Daikazu\BladeWind\Declarations\ComponentDeclarations;
use Daikazu\BladeWind\Declarations\Safelist;
use Daikazu\BladeWind\Integration\CompatHash;
use Daikazu\BladeWind\Manifest\ManifestStore;

it('records helper groups and livewire runtime classes for the chip component', function (): void {
    $classes = analyzeFixture('views/components/chip.blade.php')->classes;

    expect($classes->groups)->toHaveCount(1)
        ->and($classes->groups[0]->source)->toBe('attributes-class')
        ->and($classes->groups[0]->tokens)->toBe(['inline-flex', 'rounded-full', 'px-2'])
        ->and($classes->groups[0]->conditional[0]->tokens)->toBe(['bg-gray-100'])
        ->and($classes->groups[0]->bag)->toBeTrue()
        ->and(array_map(fn ($r) => [$r->attribute, $r->tokens, $r->remove], $classes->runtime))->toBe([
            ['wire:loading.class', ['opacity-50'], false],
            ['wire:loading.class.remove', ['opacity-100'], true],
            ['wire:dirty.class', ['border-yellow-500'], false],
        ])
        ->and($classes->index['bg-gray-100']->enumerable)->toBe(1)
        ->and($classes->index['opacity-50']->runtime)->toBe(1);
});

it('records alpine bindings, transitions, and an unresolved binding for the dropdown', function (): void {
    $entry = analyzeFixture('views/components/dropdown.blade.php');
    $classes = $entry->classes;

    expect(array_map(fn ($r) => [$r->attribute, $r->classification->value, $r->tokens], $classes->runtime))->toBe([
        ['x-bind:class', 'enumerable', ['rotate-180']],
        [':class', 'enumerable', ['hidden', 'block']],
        ['x-transition:enter', 'static', ['transition', 'ease-out', 'duration-100']],
        [':class', 'unresolved', []],
    ])
        ->and($classes->unresolved[0]->expression)->toBe('themeClasses[current]')
        ->and($classes->candidates)->toBe([])
        ->and(array_map(fn ($d) => $d->code, $entry->diagnostics))->toBe(['BW2002']);
});

it('records directive, merge, and toCssClasses helpers for the panel', function (): void {
    $entry = analyzeFixture('views/components/panel.blade.php');
    $classes = $entry->classes;

    expect(array_map(fn ($g) => [$g->source, $g->classification->value, $g->tokens, $g->unresolved, $g->bag], $classes->groups))->toBe([
        ['class-directive', 'mixed', ['rounded-lg', 'p-4'], ['$extra'], false],
        ['attributes-merge', 'static', ['bg-white'], [], true],
        ['to-css-classes', 'enumerable', [], [], false],
    ])
        ->and(array_map(fn ($d) => $d->code, $entry->diagnostics))->toBe(['BW2004']);
});

it('records mixed and unresolved attributes, php-bound classes, escaped bindings, and php block candidates for the classes page', function (): void {
    $entry = analyzeFixture('views/pages/classes.blade.php');
    $classes = $entry->classes;

    expect(array_map(fn ($g) => [$g->line, $g->on, $g->classification->value], $classes->groups))->toBe([
        [2, 'element', 'enumerable'], [3, 'element', 'mixed'], [4, 'element', 'unresolved'], [5, 'component', 'enumerable'],
    ])
        ->and(array_map(fn ($r) => [$r->line, $r->attribute, $r->tokens], $classes->runtime))->toBe([[6, '::class', ['ring']]])
        ->and(array_map(fn ($c) => [$c->origin, $c->tokens], $classes->candidates))->toBe([['php-block', ['text-gray-400', 'italic']]])
        ->and(array_map(fn ($u) => [$u->kind, $u->line], $classes->unresolved))->toBe([['class-attribute', 3], ['class-attribute', 4]])
        ->and(array_map(fn ($d) => $d->code.'@'.$d->line, $entry->diagnostics))->toBe(['BW2001@3', 'BW2001@4', 'BW1004@8']);
});

it('turns a declared dynamic target list into declared edges and flags declared classes', function (): void {
    config()->set('bladewind.components', [
        'pages.classes' => ['dynamic' => ['button', 'nope']],
        'chip' => ['classes' => ['bg-red-500']],
    ]);
    config()->set('bladewind.safelist', ['opacity-*']);
    foreach ([ComponentDeclarations::class, Safelist::class, CompatHash::class, ViewAnalyzer::class] as $abstract) {
        app()->forgetInstance($abstract);
    }

    $page = analyzeFixture('views/pages/classes.blade.php');
    $dynamic = array_values(array_filter($page->dependencies, fn ($d) => $d->type->value === 'dynamic-component'));

    expect(array_map(fn ($d) => [$d->target, $d->resolution->value, $d->resolvedPath === null ? null : basename($d->resolvedPath)], $dynamic))->toBe([
        ['button', 'declared', 'button.blade.php'], ['nope', 'declared', null],
    ])
        ->and(array_map(fn ($d) => $d->code, $page->diagnostics))->not->toContain('BW1004')
        ->and(array_map(fn ($d) => $d->code, $page->diagnostics))->toContain('BW1003');

    $chip = analyzeFixture('views/components/chip.blade.php');

    expect($chip->classes->index['bg-red-500']->toArray())->toBe(['static' => 0, 'enumerable' => 0, 'runtime' => 0, 'candidate' => 0, 'declared' => true, 'safelisted' => false])
        ->and($chip->classes->index['opacity-50']->safelisted)->toBeTrue();
});

it('invalidates stored entries when declarations change', function (): void {
    $store = app(ManifestStore::class);
    $store->put(analyzeFixture('views/components/chip.blade.php'));
    $path = $this->fixturePath('views/components/chip.blade.php');

    expect($store->get($path))->not->toBeNull();

    config()->set('bladewind.safelist', ['text-*']);
    app()->forgetInstance(CompatHash::class);
    app()->forgetInstance(ManifestStore::class);

    expect(app(ManifestStore::class)->get($path))->toBeNull();
});
