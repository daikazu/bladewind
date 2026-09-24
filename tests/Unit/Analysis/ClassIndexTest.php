<?php

declare(strict_types=1);

use Daikazu\BladeWind\Analysis\ClassIndex;
use Daikazu\BladeWind\Declarations\Safelist;

it('counts occurrences per classification with declared and safelisted overlays', function (): void {
    $analysis = analyzeClasses('<div class="flex flex" :class="{ flex: on, hidden: off }" wire:loading.class="opacity-50"><span class="{{ $a ? \'flex\' : \'block\' }}"></span><i :class="f(\'grid\')"></i></div>');

    $index = (new ClassIndex)->build($analysis, ['bg-red-500', 'flex'], new Safelist(['opacity-*', 'grid']));

    expect(array_keys($index))->toBe(['bg-red-500', 'block', 'flex', 'grid', 'hidden', 'opacity-50'])
        ->and($index['flex']->toArray())->toBe(['static' => 2, 'enumerable' => 1, 'runtime' => 1, 'candidate' => 0, 'declared' => true, 'safelisted' => false])
        ->and($index['bg-red-500']->toArray())->toBe(['static' => 0, 'enumerable' => 0, 'runtime' => 0, 'candidate' => 0, 'declared' => true, 'safelisted' => false])
        ->and($index['opacity-50']->runtime)->toBe(1)
        ->and($index['opacity-50']->safelisted)->toBeTrue()
        ->and($index['grid']->candidate)->toBe(1)
        ->and($index['grid']->safelisted)->toBeTrue()
        ->and($index['hidden']->runtime)->toBe(1)
        ->and($index['block']->enumerable)->toBe(1);
});
