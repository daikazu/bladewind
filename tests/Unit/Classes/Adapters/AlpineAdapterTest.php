<?php

declare(strict_types=1);

use Daikazu\BladeWind\Classes\Adapters\AlpineAdapter;
use Daikazu\BladeWind\Classes\RuntimeClassification;

it('scans element bindings and transition class lists', function (): void {
    $node = firstTagNode('<div :class="{ \'hidden\': !open, block: open }" x-bind:class="open ? \'rotate-180\' : \'\'" x-transition:enter="transition ease-out duration-100" x-transition:leave-end="opacity-0" :class="themeClasses[current]"></div>');

    $runtime = (new AlpineAdapter)->attributes($node);

    expect(array_map(fn ($r) => [$r->attribute, $r->tokens, $r->classification], $runtime))->toBe([
        [':class', ['hidden', 'block'], RuntimeClassification::Enumerable],
        ['x-bind:class', ['rotate-180'], RuntimeClassification::Enumerable],
        ['x-transition:enter', ['transition', 'ease-out', 'duration-100'], RuntimeClassification::Static],
        ['x-transition:leave-end', ['opacity-0'], RuntimeClassification::Static],
        [':class', [], RuntimeClassification::Unresolved],
    ])
        ->and($runtime[4]->unresolved)->toBe(['themeClasses[current]'])
        ->and($runtime[0]->adapter)->toBe('alpine')
        ->and($runtime[0]->remove)->toBeFalse();
});

it('treats a bound class on a component tag as php, but escaped and x-bind as alpine', function (): void {
    $node = firstTagNode('<x-chip :class="$tone ? \'a\' : \'b\'" ::class="{ ring: focused }" x-bind:class="\'shadow\'" />');

    $runtime = (new AlpineAdapter)->attributes($node);

    expect(array_map(fn ($r) => [$r->attribute, $r->tokens], $runtime))->toBe([
        ['::class', ['ring']],
        ['x-bind:class', ['shadow']],
    ]);
});

it('treats blade echoes inside a binding as unresolved parts', function (): void {
    $node = firstTagNode('<div x-bind:class="{ \'active\': open, {{ $extra }}: true }"></div>');

    $runtime = (new AlpineAdapter)->attributes($node)[0];

    expect($runtime->tokens)->toBe(['active'])
        ->and($runtime->classification)->toBe(RuntimeClassification::Partial)
        ->and($runtime->unresolved)->toContain('{{ $extra }}');
});

it('drops a placeholder glued to literal text instead of leaking it as a token', function (): void {
    $node = firstTagNode('<div x-bind:class="\'bg-{{ $color }}-500 mt-1\'"></div>');

    $runtime = (new AlpineAdapter)->attributes($node)[0];

    expect($runtime->tokens)->toBe(['mt-1'])
        ->and($runtime->classification)->toBe(RuntimeClassification::Partial)
        ->and($runtime->unresolved)->toContain('{{ $color }}')
        ->and(array_filter($runtime->tokens, fn (string $t): bool => str_contains($t, '__bladeEcho__')))->toBe([]);
});

it('drops a glued placeholder from an object key on an element binding', function (): void {
    $node = firstTagNode('<div :class="{ \'ring-{{ $w }}\': on, ok: on }"></div>');

    $runtime = (new AlpineAdapter)->attributes($node)[0];

    expect($runtime->tokens)->toBe(['ok'])
        ->and($runtime->classification)->toBe(RuntimeClassification::Partial)
        ->and(array_filter($runtime->tokens, fn (string $t): bool => str_contains($t, '__bladeEcho__')))->toBe([]);
});

it('matches x-bind:class case-insensitively', function (): void {
    $node = firstTagNode('<div X-Bind:Class="\'shadow\'"></div>');

    $runtime = (new AlpineAdapter)->attributes($node);

    expect($runtime)->toHaveCount(1)
        ->and($runtime[0]->attribute)->toBe('X-Bind:Class')
        ->and($runtime[0]->tokens)->toBe(['shadow']);
});

it('does not recognise ::class on a plain element, only on a component tag', function (): void {
    $node = firstTagNode('<div ::class="{ a: b }"></div>');

    expect((new AlpineAdapter)->attributes($node))->toBe([]);
});

it('substitutes the original echo text back into a condition instead of leaking the placeholder', function (): void {
    $node = firstTagNode('<div :class="{ \'hidden\': {{ $open }} }"></div>');

    $runtime = (new AlpineAdapter)->attributes($node)[0];

    expect($runtime->tokens)->toBe(['hidden'])
        ->and($runtime->conditional)->toHaveCount(1)
        ->and($runtime->conditional[0]->tokens)->toBe(['hidden'])
        ->and($runtime->conditional[0]->condition)->toBe('{{ $open }}');
});
