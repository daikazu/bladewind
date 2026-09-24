<?php

declare(strict_types=1);

use Daikazu\BladeWind\Pages\HtmlVariableScanner;

it('reads the variables a style attribute names, however it is quoted', function (): void {
    expect(HtmlVariableScanner::names('<div style="color:var(--color-red-500)">x</div>'))->toBe(['--color-red-500'])
        ->and(HtmlVariableScanner::names("<div style='--gap:1rem;padding:var(--gap)'>x</div>"))->toBe(['--gap'])
        ->and(HtmlVariableScanner::names('<div STYLE = "--a:1" ><span style="--b:2"></span></div>'))->toBe(['--a', '--b']);
});

it('reads an unquoted style attribute, which HTML allows and the class scanner already reads', function (): void {
    expect(HtmlVariableScanner::names('<div style=--x:1>x</div>'))->toBe(['--x'])
        ->and(HtmlVariableScanner::names('<div style=color:var(--color-red-500) class=flex>x</div>'))->toBe(['--color-red-500'])
        ->and(HtmlVariableScanner::names('<div style=--a:1><span style="--b:2"></span></div>'))->toBe(['--a', '--b']);
});

it('reads a variable read only through a var() fallback', function (): void {
    expect(HtmlVariableScanner::names('<div style="color:var(--x, var(--y, red))">x</div>'))->toBe(['--x', '--y']);
});

it('reads the variables a style block declares and reads', function (): void {
    $html = '<html><head><style type="text/css">:root{--brand:red}.a{color:var(--brand-dark)}</style></head><body></body></html>';

    expect(HtmlVariableScanner::names($html))->toBe(['--brand', '--brand-dark']);
});

it('returns every name once, sorted, however many places name it', function (): void {
    $html = '<style>.a{--gap:1rem}</style><div style="margin:var(--gap)"></div><div style="--a:1;--gap:2rem"></div>';

    expect(HtmlVariableScanner::names($html))->toBe(['--a', '--gap']);
});

it('reads the variables an Alpine or Livewire style binding names', function (): void {
    // In a Livewire application these are at least as common as a literal `style` attribute, and
    // they read exactly the same variables; the class scanner's asymmetry here is covered by the
    // analysed view inventory, and nothing else covers variables.
    expect(HtmlVariableScanner::names('<div class="flex" :style="{ color: \'var(--color-red-500)\' }">x</div>'))->toBe(['--color-red-500'])
        ->and(HtmlVariableScanner::names('<div x-bind:style="`--gap: ${n}px`">x</div>'))->toBe(['--gap'])
        ->and(HtmlVariableScanner::names('<div wire:style="--w:1"></div><div data-style="--z"></div>'))->toBe(['--w', '--z']);
});

it('reads nothing from a class attribute or from anywhere else in the document', function (): void {
    $html = '<div class="--x md:--y" data-var="var(--w)" aria-describedby="--v">--loose<!-- --commented --></div>';

    expect(HtmlVariableScanner::names($html))->toBe([]);
});

it('decodes entities in a style attribute but not inside a style block', function (): void {
    expect(HtmlVariableScanner::names('<div style="color:var(&#45;&#45;color-x)">x</div>'))->toBe(['--color-x'])
        ->and(HtmlVariableScanner::names('<style>.a{color:var(&#45;&#45;color-x)}</style>'))->toBe([]);
});

it('reads nothing from a document with no CSS of its own', function (): void {
    expect(HtmlVariableScanner::names(''))->toBe([])
        ->and(HtmlVariableScanner::names('<div class="flex gap-4">x</div>'))->toBe([])
        ->and(HtmlVariableScanner::names('<style>.a{color:red}</style><div style="color:red"><!-- --></div>'))->toBe([]);
});
