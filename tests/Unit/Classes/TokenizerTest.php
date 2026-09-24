<?php

declare(strict_types=1);

use Daikazu\BladeWind\Classes\Tokenizer;

it('splits on any whitespace run and keeps duplicates in order', function (): void {
    expect(Tokenizer::split("  flex\tflex \n items-center  "))->toBe(['flex', 'flex', 'items-center'])
        ->and(Tokenizer::split(''))->toBe([])
        ->and(Tokenizer::split("   \n "))->toBe([]);
});
