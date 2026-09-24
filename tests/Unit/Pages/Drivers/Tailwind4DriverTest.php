<?php

declare(strict_types=1);

use Daikazu\BladeWind\Pages\Drivers\Tailwind4Driver;
use Daikazu\BladeWind\Pages\StylesheetSplitter;

function tailwind4Driver(): Tailwind4Driver
{
    return new Tailwind4Driver(new StylesheetSplitter);
}

it('recognises a stylesheet by its top-level utilities layer', function (): void {
    $real = (string) file_get_contents(test()->fixturePath('public-tailwind/build/assets/app-tailwind.css'));

    expect(tailwind4Driver()->name())->toBe('tailwind4')
        ->and(tailwind4Driver()->detect($real))->toBeTrue()
        ->and(tailwind4Driver()->detect('@layer  utilities {.a{x:y}}'))->toBeTrue()
        // A statement names the layer without opening it; a flat stylesheet has no layer at all.
        ->and(tailwind4Driver()->detect('@layer utilities;.a{x:y}'))->toBeFalse()
        ->and(tailwind4Driver()->detect('*,:before{--tw-a:0}.flex{display:flex}'))->toBeFalse();
});

it('splits with the layer splitter and wraps a page in the utilities layer', function (): void {
    $split = tailwind4Driver()->split('@layer theme{:root{--x:1}}@layer utilities{.a{color:var(--x)}}');

    expect($split->found)->toBeTrue()
        ->and($split->utilities)->toBe('.a{color:var(--x)}')
        ->and(tailwind4Driver()->wrapUtilities('.a{x:y}'))->toBe('@layer utilities{.a{x:y}}')
        // An empty run is nothing, not an empty layer block.
        ->and(tailwind4Driver()->wrapUtilities(''))->toBe('')
        ->and(tailwind4Driver()->expects())->toBe('top-level @layer utilities block');
});

it('indexes a rule under its subject classes, or under every class when it has no subject', function (): void {
    expect(tailwind4Driver()->indexTokens('.dark\:bg-black:where(.dark,.dark *)'))->toBe(['dark:bg-black'])
        ->and(tailwind4Driver()->indexTokens('.group-hover\:flex:is(:where(.group):hover *)'))->toBe(['group-hover:flex'])
        ->and(tailwind4Driver()->indexTokens(':where(.space-y-6>:not(:last-child))'))->toBe(['space-y-6'])
        ->and(tailwind4Driver()->indexTokens('.p-4,.px-4'))->toBe(['p-4', 'px-4'])
        ->and(tailwind4Driver()->indexTokens('[x-cloak]'))->toBe([]);
});
