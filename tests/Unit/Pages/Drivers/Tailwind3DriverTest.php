<?php

declare(strict_types=1);

use Daikazu\BladeWind\Pages\Drivers\Tailwind3Driver;
use Daikazu\BladeWind\Pages\FlatStylesheetSplitter;
use Daikazu\BladeWind\Pages\UtilityRuleIndex;

it('recognises a stylesheet by the --tw- namespace and describes itself', function (): void {
    $driver = new Tailwind3Driver(new FlatStylesheetSplitter);

    expect($driver->name())->toBe('tailwind3')
        ->and($driver->detect(tailwind3FixtureCss()))->toBeTrue()
        ->and($driver->detect('.a{x:y}'))->toBeFalse()
        ->and($driver->wrapUtilities('.a{x:y}'))->toBe('.a{x:y}')
        ->and($driver->wrapUtilities(''))->toBe('')
        ->and($driver->expects())->toBe('rule with a class selector')
        // Indexed by the class on the element the rule matches, never by the variant's marker.
        ->and($driver->indexTokens('.group:hover .group-hover\:flex'))->toBe(['group-hover:flex'])
        ->and($driver->indexTokens('.dark\:bg-black:is(.dark *)'))->toBe(['dark:bg-black'])
        ->and($driver->indexTokens('.prose h1'))->toBe(['prose'])
        ->and($driver->indexTokens('[x-cloak]'))->toBe([]);
});

it('cuts real Tailwind 3 output at its first class rule and lifts the keyframes out', function (): void {
    $css = tailwind3FixtureCss();
    $split = (new Tailwind3Driver(new FlatStylesheetSplitter))->split($css);

    // Preflight, the `--tw-*` defaults rule and the user's base layer are the root; the components
    // layer's `.prose h1` is the first rule a class token can select, so the utilities start there.
    expect($split->found)->toBeTrue()
        ->and($split->root)->toStartWith('/*! tailwindcss v3.4')
        ->and($split->root)->toContain('*,:before,:after,::backdrop{--tw-border-spacing-x:0;')
        ->and($split->root)->toEndWith(':root{--brand:#0af}')
        ->and($split->root)->not->toContain('.prose')
        ->and($split->utilities)->toStartWith('.prose h1{font-weight:700}')
        ->and($split->utilities)->toContain('.flex{display:flex}')
        ->and($split->utilities)->toContain('[x-cloak]{display:none!important}')
        ->and($split->utilities)->not->toContain('@keyframes')
        ->and($split->keyframes)->toBe(['spin' => '@keyframes spin{to{transform:rotate(360deg)}}'])
        // Nothing to shake: no theme layer, no properties layer, no statements to expand.
        ->and($split->supportShaken)->toBeTrue()
        ->and($split->theme)->toBe([])
        ->and($split->properties)->toBeNull()
        ->and($split->propertyRules)->toBe([])
        ->and($split->statements)->toBe([])
        ->and($split->unshakenReason)->toBeNull()
        // The two halves and the lifted rules are the whole stylesheet: no byte is lost or moved.
        ->and($split->root.$split->utilities)->toBe(str_replace('@keyframes spin{to{transform:rotate(360deg)}}', '', $css))
        ->and(strlen($split->root) + strlen($split->utilities) + strlen($split->keyframes['spin']))->toBe(strlen($css));
});

it('keeps every rule after the cut in place, class-less ones included, and reports none when there is no class rule', function (): void {
    $split = (new Tailwind3Driver(new FlatStylesheetSplitter))->split('a{x:y}.b{x:1}c{x:2}@keyframes k{to{x:1}}.d{x:3}[e]{x:4}');
    $index = UtilityRuleIndex::build($split->utilities);

    expect($split->root)->toBe('a{x:y}')
        ->and($split->utilities)->toBe('.b{x:1}c{x:2}.d{x:3}[e]{x:4}')
        ->and($split->keyframes)->toBe(['k' => '@keyframes k{to{x:1}}'])
        // A class-less rule written after the first utility is not sorted into the root, which would
        // move it ahead of same-specificity utilities: it stays put and reaches every page.
        ->and(array_map(static fn ($rule): string => $rule->selector, $index->unconditional()))->toBe(['c', '[e]'])
        ->and((new Tailwind3Driver(new FlatStylesheetSplitter))->split('a{x:y}[b]{x:1}@font-face{font-family:x}')->found)->toBeFalse()
        ->and((new Tailwind3Driver(new FlatStylesheetSplitter))->split('')->found)->toBeFalse();
});

it('cuts at a wrapper at-rule when that is where the first class rule is, and not at a class-less one', function (): void {
    $atWrapper = (new Tailwind3Driver(new FlatStylesheetSplitter))->split('a{x:y}@media (x){.b{x:1}}.c{x:2}');
    $pastWrapper = (new Tailwind3Driver(new FlatStylesheetSplitter))->split('@media print{a{x:y}}@font-face{font-family:x}@supports (a:b){@media (x){[e]{x:1}}}.b{x:1}');
    $valueDot = (new Tailwind3Driver(new FlatStylesheetSplitter))->split('@media (x){a{margin:0.5rem}}.b{x:1}');

    expect($atWrapper->root)->toBe('a{x:y}')
        ->and($atWrapper->utilities)->toBe('@media (x){.b{x:1}}.c{x:2}')
        ->and($pastWrapper->root)->toBe('@media print{a{x:y}}@font-face{font-family:x}@supports (a:b){@media (x){[e]{x:1}}}')
        ->and($pastWrapper->utilities)->toBe('.b{x:1}')
        // `0.5rem` in a value is not a class selector.
        ->and($valueDot->root)->toBe('@media (x){a{margin:0.5rem}}');
});

it('lifts a keyframe rule out of the root as well as out of the utilities, with its separator', function (): void {
    $split = (new Tailwind3Driver(new FlatStylesheetSplitter))->split("@keyframes k{to{x:1}}\na{x:y}\n.b{x:1}\n@-webkit-keyframes k{to{x:2}}\n@keyframes m{to{x:3}}\n");

    // Whitespace after a lifted rule travels with it only when it runs into another lifted rule or
    // off the end; the newline leading into `a{x:y}` stays in the root, as it does under Tailwind 4.
    expect($split->root)->toBe("\na{x:y}\n")
        ->and($split->utilities)->toBe(".b{x:1}\n")
        ->and($split->keyframes)->toBe(['k' => "@keyframes k{to{x:1}}@-webkit-keyframes k{to{x:2}}\n", 'm' => "@keyframes m{to{x:3}}\n"]);
});
