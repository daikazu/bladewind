<?php

declare(strict_types=1);

use Daikazu\BladeWind\Pages\HtmlClassScanner;

it('collects every class token from class attributes in the final HTML', function (): void {
    $html = <<<'HTML'
<html><head><style>.x{color:red}</style><script>var s = '<div class="from-script">';</script></head>
<body class="bg-gray-50  text-gray-900">
<div CLASS='flex   items-center
    gap-2'>a</div>
<p class="text-sm &amp; hover:bg-gray-100 sm:flex">b</p>
<span class="">c</span>
<i class=unquoted>d</i>
<div data-class="not-a-class" title="class=nope">e</div>
</body></html>
HTML;

    expect(HtmlClassScanner::tokens($html))->toBe(['&', 'bg-gray-50', 'flex', 'gap-2', 'hover:bg-gray-100', 'items-center', 'sm:flex', 'text-gray-900', 'text-sm', 'unquoted']);
});

it('scans template scripts but not scripts holding JavaScript', function (): void {
    $html = <<<'HTML'
<script type="text/template"><div class="in-template"></div></script>
<script type="text/x-template"><div class="in-x-template"></div></script>
<script type="application/ld+json">{"description":"<span class='in-json'></span>"}</script>
<script type="module">const s = '<div class="in-module">';</script>
<script type="importmap">{"imports":{"x":"/x.js"}}</script>
<script type="TEXT/JAVASCRIPT">var s = '<div class="in-js">';</script>
<script>var s = '<div class="in-plain-script">';</script>
<script type>var s = '<div class="in-empty-type">';</script>
<div class="real"></div>
HTML;

    // A template is markup a script clones into the page: its classes are as real as any other's,
    // and over-including one costs a rule while missing one mis-styles what the clone renders.
    expect(HtmlClassScanner::tokens($html))->toBe(['in-json', 'in-template', 'in-x-template', 'real']);
});

it('returns an empty list for HTML without class attributes', function (): void {
    expect(HtmlClassScanner::tokens('<p>plain</p>'))->toBe([])
        ->and(HtmlClassScanner::tokens(''))->toBe([]);
});

it('keeps a purely numeric class token a string instead of an integer', function (): void {
    $tokens = HtmlClassScanner::tokens('<div class="24 24 flex">a</div>');

    expect($tokens)->toBe(['24', 'flex'])
        ->and($tokens[0])->toBeString();
});
