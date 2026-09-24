<?php

declare(strict_types=1);

use Daikazu\BladeWind\Stylesheets\TopLevelBlocks;

it('records only the blocks opened at depth zero, with comments kept out of their preludes and ranges', function (): void {
    $css = '/* .x{ */ @layer  theme{:root{--a:"}"}} @media print{@layer utilities{.c{x:y}}}.d\{e{content:\'{\'}@layer components;';
    ['blocks' => $blocks, 'comments' => $comments] = TopLevelBlocks::scan($css);

    expect($comments)->toBe([[0, 9]])
        ->and(array_column($blocks, 'prelude'))->toBe(['@layer theme', '@media print', '.d\{e'])
        // The leading comment and the blank before the first block stay outside its range.
        ->and(substr($css, $blocks[0]['start'], $blocks[0]['close'] - $blocks[0]['start'] + 1))->toBe('@layer  theme{:root{--a:"}"}}')
        ->and(substr($css, $blocks[1]['start'], $blocks[1]['close'] - $blocks[1]['start'] + 1))->toBe('@media print{@layer utilities{.c{x:y}}}')
        ->and(substr($css, $blocks[2]['open'] + 1, $blocks[2]['close'] - $blocks[2]['open'] - 1))->toBe('content:\'{\'');
});

it('recognises the registrations a page can be given selectively and rebuilds around replaced ranges', function (): void {
    expect(TopLevelBlocks::detachableRule('@property --tw-a'))->toBe(['property', '--tw-a'])
        ->and(TopLevelBlocks::detachableRule('@-webkit-keyframes "spin"'))->toBe(['keyframes', 'spin'])
        ->and(TopLevelBlocks::detachableRule('@media print'))->toBeNull()
        ->and(TopLevelBlocks::detachableRule('.a'))->toBeNull();

    // A range is replaced in place; a labelled one reports where its replacement landed; a
    // zero-width one inserts without removing, which is how a flat split marks its boundary.
    [$css, $statements] = TopLevelBlocks::applyRanges('aaBBccDDee', [
        ['start' => 6, 'close' => 7, 'replacement' => '', 'label' => null],
        ['start' => 2, 'close' => 3, 'replacement' => 'X', 'label' => 'x'],
        ['start' => 4, 'close' => 3, 'replacement' => '|', 'label' => 'cut'],
    ]);

    expect($css)->toBe('aaX|ccee')
        ->and($statements)->toBe(['x' => 2, 'cut' => 3]);
});
