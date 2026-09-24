<?php

declare(strict_types=1);

use Daikazu\BladeWind\Stylesheets\CssEscape;

it('escapes tokens the way CSS.escape does', function (string $token, string $expected): void {
    expect(CssEscape::escape($token))->toBe($expected);
})->with([
    ['flex', 'flex'],
    ['md:flex', 'md\:flex'],
    ['w-1/2', 'w-1\/2'],
    ["content-['(']", "content-\\[\\'\\(\\'\\]"],
    ['2xl:flex', '\32 xl\:flex'],
    ['-mt-2', '-mt-2'],
    ['-2xl', '-\32 xl'],
    ['-', '\-'],
    ['a b', 'a\ b'],
    ['bg-[#0a0a0a]', 'bg-\[\#0a0a0a\]'],
    ['ünicode', 'ünicode'],
    ["tab\tx", 'tab\9 x'],
]);

it('unescapes what escape produces', function (string $token): void {
    expect(CssEscape::unescape(CssEscape::escape($token)))->toBe($token);
})->with(['flex', 'sm:flex', 'hover:bg-gray-100', 'w-1/2', 'p-[3px]', '2xl:p-4', '-mt-2', '!mt-2', 'bg-[#fff]', 'content-[\'{\']', 'w-[calc(100%-1rem)]', 'text-red-500/50', '*:p-2', 'après']);

it('unescapes hex escapes a hand-written stylesheet may spell differently', function (string $identifier, string $expected): void {
    expect(CssEscape::unescape($identifier))->toBe($expected);
})->with([
    'code point above ASCII' => ['\\e9 t\\e9', 'été'],
    'six digits, no terminating space' => ['\\01F600', "\u{1F600}"],
    'space after the escape is consumed, the next one is not' => ['a\\20  b', 'a  b'],
]);
