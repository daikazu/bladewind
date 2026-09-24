<?php

declare(strict_types=1);

use Daikazu\BladeWind\Classes\Adapters\LivewireAdapter;
use Daikazu\BladeWind\Classes\RuntimeClassification;

it('matches every wire directive whose modifiers include class', function (): void {
    $node = firstTagNode('<button wire:loading.class="opacity-50 cursor-wait" wire:loading.class.remove="opacity-100" wire:loading.delay.class="animate-pulse" wire:dirty.class="border-yellow-500" wire:offline.class.remove="hidden" wire:target="save" wire:loading.attr="disabled">x</button>');

    $runtime = (new LivewireAdapter)->attributes($node);

    expect(array_map(fn ($r) => [$r->attribute, $r->tokens, $r->remove, $r->classification], $runtime))->toBe([
        ['wire:loading.class', ['opacity-50', 'cursor-wait'], false, RuntimeClassification::Static],
        ['wire:loading.class.remove', ['opacity-100'], true, RuntimeClassification::Static],
        ['wire:loading.delay.class', ['animate-pulse'], false, RuntimeClassification::Static],
        ['wire:dirty.class', ['border-yellow-500'], false, RuntimeClassification::Static],
        ['wire:offline.class.remove', ['hidden'], true, RuntimeClassification::Static],
    ])->and($runtime[0]->adapter)->toBe('livewire');
});

it('records wire:current, whose classes Livewire adds on the client when the link matches the URL', function (): void {
    $node = firstTagNode('<a href="/orders" wire:navigate wire:current="font-bold text-blue-600" wire:current.exact="underline" wire:current.strict="{{ $active }}">Orders</a>');

    $runtime = (new LivewireAdapter)->attributes($node);

    // The class is never in the server HTML: only the inventory can carry it to the page.
    expect(array_map(fn ($r) => [$r->attribute, $r->tokens, $r->remove, $r->classification], $runtime))->toBe([
        ['wire:current', ['font-bold', 'text-blue-600'], false, RuntimeClassification::Static],
        ['wire:current.exact', ['underline'], false, RuntimeClassification::Static],
        ['wire:current.strict', [], false, RuntimeClassification::Unresolved],
    ])->and($runtime[2]->unresolved)->toBe(['$active']);
});

it('enumerates or reports dynamic values', function (): void {
    $node = firstTagNode('<div wire:loading.class="{{ $busy ? \'opacity-50\' : \'opacity-75\' }}" wire:dirty.class="{{ $dirtyClasses }}"></div>');

    $runtime = (new LivewireAdapter)->attributes($node);

    expect($runtime[0]->classification)->toBe(RuntimeClassification::Enumerable)
        ->and(array_map(fn ($c) => $c->tokens, $runtime[0]->conditional))->toBe([['opacity-50'], ['opacity-75']])
        ->and($runtime[0]->tokens)->toBe(['opacity-50', 'opacity-75'])
        ->and($runtime[1]->classification)->toBe(RuntimeClassification::Unresolved)
        ->and($runtime[1]->unresolved)->toBe(['$dirtyClasses']);
});

it('matches wire attribute names and modifiers case-insensitively', function (): void {
    $node = firstTagNode('<div wire:Loading.Class="opacity-50"></div>');

    $runtime = (new LivewireAdapter)->attributes($node);

    expect($runtime)->toHaveCount(1)
        ->and($runtime[0]->attribute)->toBe('wire:Loading.Class')
        ->and($runtime[0]->tokens)->toBe(['opacity-50']);
});

it('ignores blade-bound wire attributes and component tags without wire classes', function (): void {
    expect((new LivewireAdapter)->attributes(firstTagNode('<x-chip :wire:loading.class="$x" class="a" />')))->toBe([]);
});
