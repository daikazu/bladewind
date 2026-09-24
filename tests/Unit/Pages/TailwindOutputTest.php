<?php

declare(strict_types=1);

use Daikazu\BladeWind\Pages\PageCssBuilder;
use Daikazu\BladeWind\Pages\StylesheetSplitter;
use Daikazu\BladeWind\Pages\UtilityRuleIndex;

/**
 * The stylesheets these tests read are output the real Tailwind compiler produced from the inputs in
 * tests/fixtures/tailwind (regenerate with `node tests/fixtures/tailwind/build.mjs`, once per input),
 * not hand-written approximations. A hand-written fixture only contains shapes its author thought
 * of; real output also has shapes like a user `@layer utilities {}` block compiling into a
 * class-less rule inside the utilities layer, or a `color-mix()` theme value compiling into a
 * `@supports` block inside the theme layer.
 */
function tailwindFixtureCss(string $file = 'app-tailwind.css'): string
{
    return (string) file_get_contents(test()->fixturePath('public-tailwind/build/assets/'.$file));
}

it('splits real Tailwind output and puts it back together byte for byte', function (): void {
    $css = tailwindFixtureCss();
    $split = (new StylesheetSplitter)->split($css);

    expect($split->found)->toBeTrue()
        ->and(substr_count($split->root, '@layer utilities;'))->toBe(1)
        ->and($split->root)->not->toContain('.flex{display:flex}')
        ->and($split->utilities)->toContain('[x-cloak]{display:none!important}')
        ->and($split->reassemble())->toBe($css);
});

it('shakes the support layers of real Tailwind output out of the root', function (): void {
    $split = (new StylesheetSplitter)->split(tailwindFixtureCss());

    expect($split->supportShaken)->toBeTrue()
        // The statements keep each layer's cascade position; not one support declaration is left behind.
        ->and($split->root)->toContain('@layer properties;@layer theme;@layer base{')
        ->and($split->root)->toContain('@layer components;@layer utilities;')
        ->and($split->root)->not->toContain('--color-red-500')
        ->and($split->root)->not->toContain('@property')
        ->and($split->root)->not->toContain('@keyframes');
});

it('reads the theme blocks, the properties layer and the registrations of real Tailwind output', function (): void {
    $split = (new StylesheetSplitter)->split(tailwindFixtureCss());

    expect($split->theme)->toHaveCount(1)
        ->and($split->theme[0]->selector)->toBe(':root,:host')
        ->and($split->theme[0]->declarations)->toHaveKeys(['--font-sans', '--color-red-500', '--spacing', '--animate-spin', '--default-font-family'])
        ->and($split->theme[0]->declarations['--color-red-500'])->toBe('oklch(63.7% .237 25.331)')
        ->and($split->theme[0]->declarations['--animate-spin'])->toBe('spin 1s linear infinite')
        ->and($split->theme[0]->declarations['--default-font-family'])->toBe('var(--font-sans)')
        // Every value the theme carries is a custom property, commas and quoted font names included.
        ->and(array_keys($split->theme[0]->declarations))->each->toStartWith('--')
        ->and($split->properties?->wrappers)->toHaveCount(1)
        ->and($split->properties?->wrappers[0])->toStartWith('@supports (((-webkit-hyphens:none))')
        ->and($split->properties?->selector)->toBe('*,:before,:after,::backdrop')
        ->and($split->properties?->declarations)->toBe(['--tw-space-y-reverse' => '0', '--tw-border-style' => 'solid'])
        ->and(array_keys($split->propertyRules))->toBe(['--tw-space-y-reverse', '--tw-border-style'])
        ->and($split->propertyRules['--tw-border-style'])->toBe('@property --tw-border-style{syntax:"*";inherits:false;initial-value:solid}')
        ->and($split->keyframes)->toBe(['spin' => '@keyframes spin{to{transform:rotate(360deg)}}']);
});

it('attributes every rule of the real utilities layer to a token or to every page', function (): void {
    $index = UtilityRuleIndex::build((new StylesheetSplitter)->split(tailwindFixtureCss())->utilities);

    $positions = [];

    foreach ($index->tokens() as $token) {
        foreach ($index->rulesFor($token) as $rule) {
            $positions[$rule->position] = true;
        }
    }

    foreach ($index->unconditional() as $rule) {
        $positions[$rule->position] = true;
    }

    // Nothing in the layer is dropped: every rule is reachable from a class token or is carried by
    // every page. A rule that is neither would silently disappear from every page stylesheet.
    expect($index->count())->toBeGreaterThan(15)
        ->and(count($positions))->toBe($index->count())
        ->and($index->unconditional())->toHaveCount(1)
        ->and($index->unconditional()[0]->selector)->toBe('[x-cloak]');
});

it('builds a page from real Tailwind output with the rules its tokens need and nothing else', function (): void {
    $index = UtilityRuleIndex::build((new StylesheetSplitter)->split(tailwindFixtureCss())->utilities);
    $css = (new PageCssBuilder)->build(['flex', 'dark:bg-black'], $index);

    expect($css)->not->toContain('@layer')
        ->toContain('[x-cloak]{display:none!important}')
        ->toContain('.flex{display:flex}')
        ->toContain('.dark\:bg-black:where(.dark,.dark *){background-color:var(--color-black)}')
        ->not->toContain('.animate-spin')
        ->not->toContain('.items-center');
});

it('shakes a color-mix theme value into its plain block and its @supports wrapper', function (): void {
    // Tailwind's Vite plugin runs Lightning CSS, which compiles
    // `--color-brand-hover:color-mix(in oklab, var(--color-brand) 90%, black)` into a static
    // fallback in the plain block plus the real value inside a `@supports` test nested in the theme
    // layer. Refusing that shape would turn support-layer tree-shaking off for any theme that
    // derives one colour from another, a common Tailwind 4 pattern (see
    // tests/fixtures/tailwind/input-colormix.css).
    $css = tailwindFixtureCss('app-tailwind-colormix.css');
    $split = (new StylesheetSplitter)->split($css);

    expect($split->supportShaken)->toBeTrue()
        ->and($split->unshakenReason)->toBeNull()
        ->and($split->theme)->toHaveCount(2)
        ->and($split->theme[0]->wrappers)->toBe([])
        ->and($split->theme[0]->selector)->toBe(':root,:host')
        ->and($split->theme[0]->declarations['--color-brand-hover'])->toBe('#0075cc')
        ->and($split->theme[1]->wrappers)->toBe(['@supports (color:color-mix(in lab, red, red))'])
        ->and($split->theme[1]->selector)->toBe(':root,:host')
        ->and($split->theme[1]->declarations)->toBe(['--color-brand-hover' => 'color-mix(in oklab, var(--color-brand) 90%, black)'])
        ->and($split->root)->toContain('@layer theme;')
        ->and($split->root)->not->toContain('--color-brand')
        ->and($split->reassemble())->toBe($css);
});

it('shakes a prefixed build, whose theme variables share the --tw-* namespace', function (): void {
    // `@import "tailwindcss" prefix(tw)` puts the theme in the same namespace as Tailwind's own
    // internals: `--tw-spacing` is a theme variable, `--tw-border-style` a properties-layer default
    // with a registration, and nothing may key one by the other's rules.
    $css = tailwindFixtureCss('app-tailwind-prefix.css');
    $split = (new StylesheetSplitter)->split($css);

    expect($split->supportShaken)->toBeTrue()
        ->and($split->theme)->toHaveCount(1)
        ->and($split->theme[0]->declarations)->toHaveKeys(['--tw-spacing', '--tw-animate-spin', '--tw-color-gray-100'])
        ->and($split->theme[0]->declarations)->not->toHaveKey('--tw-border-style')
        ->and($split->properties?->declarations)->toBe(['--tw-border-style' => 'solid'])
        ->and(array_keys($split->propertyRules))->toBe(['--tw-border-style'])
        ->and(array_keys($split->keyframes))->toBe(['spin'])
        ->and($split->reassemble())->toBe($css);
});
