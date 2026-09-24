<?php

declare(strict_types=1);

use Daikazu\BladeWind\Declarations\Safelist;

it('matches exact tokens and single trailing wildcards', function (): void {
    $safelist = new Safelist(['bg-red-500', 'text-*', 'hover:bg-*']);

    expect($safelist->matches('bg-red-500'))->toBeTrue()
        ->and($safelist->matches('bg-red-600'))->toBeFalse()
        ->and($safelist->matches('text-sm'))->toBeTrue()
        ->and($safelist->matches('text-'))->toBeTrue()
        ->and($safelist->matches('hover:bg-blue-500'))->toBeTrue()
        ->and($safelist->matches('text'))->toBeFalse()
        ->and($safelist->invalid())->toBe([])
        ->and($safelist->isEmpty())->toBeFalse();
});

it('rejects entries with whitespace, multiple or non-trailing wildcards, and empties', function (): void {
    $safelist = new Safelist(['a b', '*', 'bg-*-500', 'x**', '', 'ok-*']);

    expect($safelist->invalid())->toBe(['a b', '*', 'bg-*-500', 'x**', ''])
        ->and($safelist->matches('ok-1'))->toBeTrue()
        ->and((new Safelist([]))->isEmpty())->toBeTrue();
});

it('does not throw when a non-string entry contains invalid UTF-8', function (): void {
    $safelist = new Safelist([['bad' => "\xB1\x31"]]);

    expect($safelist->invalid())->toHaveCount(1);
});
