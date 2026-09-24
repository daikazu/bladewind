<?php

declare(strict_types=1);

use Daikazu\BladeWind\Classes\MixedValue;

it('returns static tokens for a value without echoes', function (): void {
    $result = MixedValue::enumerate('flex items-center gap-2');

    expect($result->staticTokens)->toBe(['flex', 'items-center', 'gap-2'])->and($result->resolvedFully())->toBeTrue();
});

it('keeps static tokens around an unresolved echo', function (): void {
    $result = MixedValue::enumerate('p-2 {{ $extra }} mt-1');

    expect($result->staticTokens)->toBe(['p-2', 'mt-1'])->and($result->unresolved)->toBe(['$extra']);
});

it('enumerates a ternary echo', function (): void {
    $result = MixedValue::enumerate("rounded {{ \$active ? 'bg-blue-500' : 'bg-gray-500' }}");

    expect($result->staticTokens)->toBe(['rounded'])
        ->and(array_map(fn ($c) => $c->tokens, $result->conditions))->toBe([['bg-blue-500'], ['bg-gray-500']])
        ->and($result->resolvedFully())->toBeTrue();
});

it('marks a token that mixes literal text and an echo as unresolved as a whole', function (): void {
    $result = MixedValue::enumerate('p-2 bg-{{ $color }}-500 {!! $raw !!}text');

    expect($result->staticTokens)->toBe(['p-2'])
        ->and($result->unresolved)->toBe(['bg-{{ $color }}-500', '{!! $raw !!}text']);
});
