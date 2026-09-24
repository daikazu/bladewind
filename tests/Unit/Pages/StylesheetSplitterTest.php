<?php

declare(strict_types=1);

use Daikazu\BladeWind\Pages\PropertiesLayer;
use Daikazu\BladeWind\Pages\StylesheetSplitter;
use Daikazu\BladeWind\Pages\ThemeBlock;

it('separates the utilities layer from everything else and keeps the layer order', function (): void {
    $css = '@layer properties{@supports (x:y){*{--tw-a:0}}}@layer theme{:root{--spacing:.25rem}}@layer base{*{margin:0}}@layer components;@layer utilities{.flex{display:flex}@media (width>=40rem){.sm\:flex{display:flex}}.p-4{padding:1rem}}@keyframes spin{to{transform:rotate(360deg)}}@property --tw-a{syntax:"*";inherits:false}';

    $split = (new StylesheetSplitter)->split($css);

    expect($split->found)->toBeTrue()
        ->and($split->utilities)->toBe('.flex{display:flex}@media (width>=40rem){.sm\:flex{display:flex}}.p-4{padding:1rem}')
        ->and($split->root)->toBe('@layer properties;@layer theme;@layer base{*{margin:0}}@layer components;@layer utilities;')
        ->and($split->supportShaken)->toBeTrue()
        ->and($split->rootWithSupport())->toBe('@layer properties{@supports (x:y){*{--tw-a:0}}}@layer theme{:root{--spacing:.25rem}}@layer base{*{margin:0}}@layer components;@layer utilities;@property --tw-a{syntax:"*";inherits:false}@keyframes spin{to{transform:rotate(360deg)}}');
});

it('ignores braces inside strings and comments and a nested utilities layer', function (): void {
    $css = '/* @layer utilities{ */.a{content:"}"}@layer theme{.b{content:\'{\'}}@media print{@layer utilities{.c{x:y}}}@layer utilities{.d{x:y}}';

    $split = (new StylesheetSplitter)->split($css);

    expect($split->found)->toBeTrue()
        ->and($split->utilities)->toBe('.d{x:y}')
        // `content` is not a custom property, so the theme layer is one the splitter leaves whole.
        ->and($split->supportShaken)->toBeFalse()
        ->and($split->root)->toBe('/* @layer utilities{ */.a{content:"}"}@layer theme{.b{content:\'{\'}}@media print{@layer utilities{.c{x:y}}}@layer utilities;');
});

it('recognises the utilities prelude whatever whitespace separates its words', function (): void {
    $split = (new StylesheetSplitter)->split("@layer  utilities{.a{x:y}}@layer\nutilities{.b{x:y}}");

    expect($split->found)->toBeTrue()
        ->and($split->utilities)->toBe('.a{x:y}.b{x:y}')
        ->and($split->root)->toBe('@layer utilities;');
});

it('recognises the utilities prelude with a comment between its words', function (): void {
    $split = (new StylesheetSplitter)->split('@layer /*x*/ utilities{.a{x:y}}');

    expect($split->found)->toBeTrue()
        ->and($split->utilities)->toBe('.a{x:y}')
        ->and($split->root)->toBe('@layer utilities;');
});

it('reports a stylesheet without a top-level utilities layer', function (): void {
    $split = (new StylesheetSplitter)->split('.a{x:y}');

    expect($split->found)->toBeFalse()->and($split->utilities)->toBe('')->and($split->root)->toBe('.a{x:y}');
});

it('splits the fixture stylesheet so every extracted part reassembles it byte for byte', function (): void {
    $css = (string) file_get_contents($this->fixturePath('public/build/assets/app-test.css'));
    $split = (new StylesheetSplitter)->split($css);

    expect($split->found)->toBeTrue()
        ->and($split->supportShaken)->toBeTrue()
        ->and($split->root)->toContain('@layer theme;')
        ->and($split->theme)->toHaveCount(1)
        ->and($split->theme[0]->selector)->toBe(':root')
        ->and($split->theme[0]->declarations)->toBe(['--spacing' => '.25rem', '--radius-lg' => '.5rem'])
        ->and($split->reassemble())->toBe($css);
});

it('splits the theme layer, the properties layer, the @property rules and the keyframes', function (): void {
    $css = '@layer properties{@supports (a:b){*,:before{--tw-a:0;--tw-b:initial}}}@layer theme{:root,:host{--spacing:.25rem;--color-x:red}.dark{--color-x:blue}}@layer base{*{margin:0}}@layer components;@layer utilities{.a{color:var(--color-x)}}@property --tw-a{syntax:"*";inherits:false;initial-value:0}@keyframes spin{to{transform:rotate(1turn)}}';

    $split = (new StylesheetSplitter)->split($css);

    expect($split->supportShaken)->toBeTrue()
        ->and($split->root)->toBe('@layer properties;@layer theme;@layer base{*{margin:0}}@layer components;@layer utilities;')
        ->and($split->utilities)->toBe('.a{color:var(--color-x)}')
        ->and($split->theme)->toHaveCount(2)
        ->and($split->theme[0]->selector)->toBe(':root,:host')
        ->and($split->theme[0]->declarations)->toBe(['--spacing' => '.25rem', '--color-x' => 'red'])
        ->and($split->theme[1]->selector)->toBe('.dark')
        ->and($split->theme[1]->declarations)->toBe(['--color-x' => 'blue'])
        ->and($split->properties?->wrappers)->toBe(['@supports (a:b)'])
        ->and($split->properties?->selector)->toBe('*,:before')
        ->and($split->properties?->declarations)->toBe(['--tw-a' => '0', '--tw-b' => 'initial'])
        ->and($split->propertyRules)->toBe(['--tw-a' => '@property --tw-a{syntax:"*";inherits:false;initial-value:0}'])
        ->and($split->keyframes)->toBe(['spin' => '@keyframes spin{to{transform:rotate(1turn)}}'])
        ->and($split->reassemble())->toBe($css);
});

it('reads a properties layer that has no wrapper at all', function (): void {
    $css = '@layer properties{*{--tw-a:0}}@layer utilities{.a{x:y}}';

    $split = (new StylesheetSplitter)->split($css);

    expect($split->supportShaken)->toBeTrue()
        ->and($split->root)->toBe('@layer properties;@layer utilities;')
        ->and($split->properties?->wrappers)->toBe([])
        ->and($split->properties?->selector)->toBe('*')
        ->and($split->properties?->declarations)->toBe(['--tw-a' => '0'])
        ->and($split->reassemble())->toBe($css);
});

it('keeps a keyframe name that is registered twice whole', function (): void {
    $css = '@layer utilities{.a{x:y}}@-webkit-keyframes spin{to{-webkit-transform:rotate(1turn)}}@keyframes spin{to{transform:rotate(1turn)}}';

    $split = (new StylesheetSplitter)->split($css);

    expect($split->supportShaken)->toBeTrue()
        ->and($split->root)->toBe('@layer utilities;')
        ->and($split->keyframes)->toBe(['spin' => '@-webkit-keyframes spin{to{-webkit-transform:rotate(1turn)}}@keyframes spin{to{transform:rotate(1turn)}}'])
        ->and($split->reassemble())->toBe($css);
});

it('leaves the support layers whole when a theme block declares anything but a custom property', function (): void {
    $css = '@layer theme{:root{--x:1;color:red}}@layer utilities{.a{x:y}}@property --tw-a{syntax:"*"}';

    $split = (new StylesheetSplitter)->split($css);

    expect($split->found)->toBeTrue()
        ->and($split->supportShaken)->toBeFalse()
        ->and($split->unshakenReason)->toBe('non-custom declaration in @layer theme')
        ->and($split->root)->toBe('@layer theme{:root{--x:1;color:red}}@layer utilities;@property --tw-a{syntax:"*"}')
        ->and($split->theme)->toBe([])
        ->and($split->properties)->toBeNull()
        ->and($split->propertyRules)->toBe([])
        ->and($split->keyframes)->toBe([])
        ->and($split->reassemble())->toBe($css);
});

it('shakes a theme block wrapped in a conditional group, to any depth', function (): void {
    // The `@supports` shape is what Lightning CSS compiles a `color-mix()` theme value into; the
    // nested one is the general case, and a subset of either is re-emitted inside the same chain.
    $css = '@layer theme{:root{--x:1}@supports (color:color-mix(in lab, red, red)){:root{--x:color-mix(in oklab, red, blue)}}@media print{@supports (a:b){.dark{--y:2}}}}@layer utilities{.a{color:var(--x)}}';

    $split = (new StylesheetSplitter)->split($css);

    expect($split->supportShaken)->toBeTrue()
        ->and($split->unshakenReason)->toBeNull()
        ->and($split->theme)->toHaveCount(3)
        ->and($split->theme[0]->wrappers)->toBe([])
        ->and($split->theme[0]->declarations)->toBe(['--x' => '1'])
        ->and($split->theme[1]->wrappers)->toBe(['@supports (color:color-mix(in lab, red, red))'])
        ->and($split->theme[1]->selector)->toBe(':root')
        ->and($split->theme[1]->css())->toBe('@supports (color:color-mix(in lab, red, red)){:root{--x:color-mix(in oklab, red, blue)}}')
        ->and($split->theme[2]->wrappers)->toBe(['@media print', '@supports (a:b)'])
        ->and($split->theme[2]->selector)->toBe('.dark')
        ->and($split->theme[2]->css(['--y' => '2']))->toBe('@media print{@supports (a:b){.dark{--y:2}}}')
        ->and($split->root)->toBe('@layer theme;@layer utilities;')
        ->and($split->reassemble())->toBe($css);
});

it('leaves the support layers whole when an at-rule in the theme layer holds more than declarations', function (): void {
    $css = '@layer theme{@media print{:root{color:red}}}@layer utilities{.a{x:y}}';

    $split = (new StylesheetSplitter)->split($css);

    expect($split->supportShaken)->toBeFalse()
        ->and($split->unshakenReason)->toBe('non-custom declaration in @layer theme')
        ->and($split->root)->toBe('@layer theme{@media print{:root{color:red}}}@layer utilities;')
        ->and($split->reassemble())->toBe($css);
});

it('leaves a comment before a theme block out of the block\'s selector', function (): void {
    $split = (new StylesheetSplitter)->split('@layer theme{/*! x */:root{--a:1}}@layer utilities{.a{x:y}}');

    // Every page file carrying `--a` would otherwise repeat the comment along with the selector.
    expect($split->theme[0]->selector)->toBe(':root')
        ->and($split->theme[0]->css())->toBe(':root{--a:1}')
        ->and($split->reassemble())->toBe('@layer theme{:root{--a:1}}@layer utilities{.a{x:y}}');
});

it('leaves the support layers whole when a theme block nests another block', function (): void {
    $css = '@layer theme{:root{.a{--x:1}}}@layer utilities{.a{x:y}}';

    $split = (new StylesheetSplitter)->split($css);

    expect($split->supportShaken)->toBeFalse()
        ->and($split->unshakenReason)->toBe('nested rule in @layer theme')
        ->and($split->root)->toBe('@layer theme{:root{.a{--x:1}}}@layer utilities;')
        ->and($split->reassemble())->toBe($css);
});

it('leaves the support layers whole when the properties layer holds more than one block', function (): void {
    $css = '@layer properties{*{--tw-a:0}:before{--tw-b:0}}@layer utilities{.a{x:y}}';

    $split = (new StylesheetSplitter)->split($css);

    expect($split->supportShaken)->toBeFalse()
        ->and($split->properties)->toBeNull()
        ->and($split->unshakenReason)->toBe('more than one block in @layer properties')
        ->and($split->root)->toBe('@layer properties{*{--tw-a:0}:before{--tw-b:0}}@layer utilities;')
        ->and($split->reassemble())->toBe($css);
});

it('leaves the support layers whole when a layer is opened twice at the top level', function (): void {
    $css = '@layer theme{:root{--x:1}}@layer theme{.dark{--x:2}}@layer utilities{.a{x:y}}';

    $split = (new StylesheetSplitter)->split($css);

    expect($split->supportShaken)->toBeFalse()
        ->and($split->theme)->toBe([])
        ->and($split->unshakenReason)->toBe('second top-level @layer theme')
        ->and($split->root)->toBe('@layer theme{:root{--x:1}}@layer theme{.dark{--x:2}}@layer utilities;')
        ->and($split->reassemble())->toBe($css);
});

it('reassembles by the offsets it recorded, not by searching for the statement text', function (): void {
    $css = '/* @layer theme; and @layer utilities; below */@layer properties{*{--tw-a:0}}@layer theme{:root{--x:1}}@layer utilities{.a{x:y}}';

    $split = (new StylesheetSplitter)->split($css);

    expect($split->supportShaken)->toBeTrue()
        ->and($split->root)->toBe('/* @layer theme; and @layer utilities; below */@layer properties;@layer theme;@layer utilities;')
        ->and($split->statements)->toBe(['properties' => 47, 'theme' => 65, 'utilities' => 78])
        ->and(substr($split->root, 65, 13))->toBe('@layer theme;')
        ->and($split->rootWithSupport())->toBe('/* @layer theme; and @layer utilities; below */@layer properties{*{--tw-a:0}}@layer theme{:root{--x:1}}@layer utilities;')
        ->and($split->reassemble())->toBe($css);
});

it('records the utilities statement offset even when the support layers stay whole', function (): void {
    $css = '/* @layer utilities; */@layer theme{:root{color:red}}@layer utilities{.a{x:y}}';

    $split = (new StylesheetSplitter)->split($css);

    expect($split->supportShaken)->toBeFalse()
        ->and($split->statements)->toBe(['utilities' => 53])
        ->and($split->reassemble())->toBe($css);
});

it('keys a quoted keyframe name by the name the animation property writes', function (): void {
    $css = '@layer utilities{.a{animation:spin 1s}}@keyframes "spin"{to{transform:rotate(1turn)}}';

    $split = (new StylesheetSplitter)->split($css);

    expect($split->supportShaken)->toBeTrue()
        ->and($split->keyframes)->toBe(['spin' => '@keyframes "spin"{to{transform:rotate(1turn)}}'])
        ->and($split->reassemble())->toBe($css);
});

it('keeps the last value of a name a theme block declares twice, in the first one\'s place', function (): void {
    $css = '@layer theme{:root{--x:1;--y:2;--x:3}}@layer utilities{.a{x:y}}';

    $split = (new StylesheetSplitter)->split($css);

    // The cascade resolves `--x` to 3 wherever the declaration sits, so collapsing the pair into the
    // first position is equivalent CSS — the round trip is semantic here rather than byte for byte.
    expect($split->theme[0]->declarations)->toBe(['--x' => '3', '--y' => '2'])
        ->and($split->reassemble())->toBe('@layer theme{:root{--x:3;--y:2}}@layer utilities{.a{x:y}}');
});

it('emits nothing at all for an empty subset of a theme block or the properties layer', function (): void {
    $theme = new ThemeBlock(':root', ['--x' => '1']);
    $properties = new PropertiesLayer(['@supports (a:b)'], '*,:before', ['--tw-a' => '0']);

    expect($theme->css([]))->toBe('')
        ->and($theme->css())->toBe(':root{--x:1}')
        ->and($properties->css([]))->toBe('')
        ->and($properties->css())->toBe('@supports (a:b){*,:before{--tw-a:0}}');
});

it('reassembles a support block that declared nothing without its selector or wrapper', function (): void {
    $split = (new StylesheetSplitter)->split('@layer properties{@supports (x:y){*,:before{}}}@layer theme{:root{}}@layer utilities{.a{x:y}}');

    // An empty declaration block styles nothing, so dropping it and the `@supports` wrapper around it
    // is the same stylesheet — the one round trip that is a semantic no-op rather than byte for byte.
    expect($split->supportShaken)->toBeTrue()
        ->and($split->theme[0]->declarations)->toBe([])
        ->and($split->properties?->declarations)->toBe([])
        ->and($split->reassemble())->toBe('@layer properties{}@layer theme{}@layer utilities{.a{x:y}}');
});

it('shakes support parts that hold values with commas, quotes and var() references', function (): void {
    $css = '@layer theme{:root{--font-sans:ui-sans-serif, "Segoe UI", sans-serif;--default-font-family:var(--font-sans)}}@layer utilities{.a{x:y}}';

    $split = (new StylesheetSplitter)->split($css);

    expect($split->supportShaken)->toBeTrue()
        ->and($split->theme[0]->declarations)->toBe([
            '--font-sans' => 'ui-sans-serif, "Segoe UI", sans-serif',
            '--default-font-family' => 'var(--font-sans)',
        ])
        ->and($split->reassemble())->toBe($css);
});

it('reports no shaking for a stylesheet without a utilities layer', function (): void {
    $split = (new StylesheetSplitter)->split('@layer theme{:root{--x:1}}');

    expect($split->found)->toBeFalse()
        ->and($split->supportShaken)->toBeFalse()
        ->and($split->theme)->toBe([])
        ->and($split->root)->toBe('@layer theme{:root{--x:1}}');
});
