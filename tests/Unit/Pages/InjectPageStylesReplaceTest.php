<?php

declare(strict_types=1);

use Daikazu\BladeWind\Http\InjectPageStyles;

it('replaces every run of marked links and counts the runs', function (): void {
    $run = '<link rel="stylesheet" href="/a.css" data-bladewind-styles><link rel="stylesheet" href="/b.css" data-bladewind-styles>';
    $content = '<head>'.$run.'</head><body>'.$run.'<link rel="stylesheet" href="/other.css"></body>';
    $count = 0;

    $replaced = InjectPageStyles::replaceMarked($content, '<link rel="stylesheet" href="/page.css">', $count);

    expect($count)->toBe(2)
        ->and($replaced)->toBe('<head><link rel="stylesheet" href="/page.css"></head><body><link rel="stylesheet" href="/page.css"><link rel="stylesheet" href="/other.css"></body>');
});

it('returns null instead of an empty body when the regex engine fails', function (): void {
    $previous = ini_set('pcre.backtrack_limit', '1');

    try {
        $content = '<head><link rel="stylesheet" href="/a.css" data-bladewind-styles></head>'.str_repeat('<p class="x">x</p>', 200);
        $count = 0;

        expect(InjectPageStyles::replaceMarked($content, '<link rel="stylesheet" href="/page.css">', $count))->toBeNull();
    } finally {
        ini_set('pcre.backtrack_limit', $previous === false ? '1000000' : $previous);
    }
});
