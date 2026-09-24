<?php

declare(strict_types=1);

use Daikazu\BladeWind\Classes\CandidateScanner;

it('extracts tokens from every javascript string literal in order without duplicates', function (): void {
    $source = "open ? 'bg-blue-500 text-white' : \"bg-gray-100\" + `text-white` + themeClasses[current]";

    expect(CandidateScanner::scan($source, CandidateScanner::JS))->toBe(['bg-blue-500', 'text-white', 'bg-gray-100']);
});

it('extracts tokens from php string literals including the literal parts of interpolated strings', function (): void {
    $source = '$fallback = \'text-gray-400 italic\'; $x = "bg-{$color}-500 shadow"; $y = f($z);';

    expect(CandidateScanner::scan($source, CandidateScanner::PHP))->toBe(['text-gray-400', 'italic', 'bg-', 'shadow']);
});

it('keeps only tokens that look like classes', function (): void {
    $source = "'hover:bg-red-500/50 w-[32px] !mt-2 @md:flex 123 --- (x) content-[\\'a\\']'";

    expect(CandidateScanner::scan($source, CandidateScanner::JS))->toBe([
        'hover:bg-red-500/50', 'w-[32px]', '!mt-2', '@md:flex', '(x)', "content-['a']",
    ]);
});

it('returns nothing when there are no string literals', function (): void {
    expect(CandidateScanner::scan('themeClasses[current]', CandidateScanner::JS))->toBe([])
        ->and(CandidateScanner::scan('$classes', CandidateScanner::PHP))->toBe([]);
});

it('unescapes only quotes and backslashes in javascript strings', function (): void {
    $source = "'it\\'s-valid' and 'both\\'quotes'";

    $result = CandidateScanner::scan($source, CandidateScanner::JS);
    expect($result)->toContain("it's-valid");
    expect($result)->toContain("both'quotes");
});

it('unescapes literal parts of php interpolated strings', function (): void {
    $source = '$x = "say-\\"hi\\"-{$color} ok";';

    $result = CandidateScanner::scan($source, CandidateScanner::PHP);
    expect($result)->toContain('ok');
    foreach ($result as $token) {
        expect($token)->not->toContain('\\');
    }
});

it('preserves css escapes such as \\2014 inside arbitrary values', function (): void {
    $source = <<<'JS'
'content-[\'\2014\'] mt-1'
JS;

    expect(CandidateScanner::scan($source, CandidateScanner::JS))->toBe(["content-['\\2014']", 'mt-1']);
});

it('reads a template literal as its literal text plus every string inside its placeholders', function (): void {
    // Guards against `p-52` being lost (its closing brace failing the allow-list) and `'p-51'`
    // keeping its quotes.
    expect(CandidateScanner::scan('`p-50 ${open ? \'p-51\' : \'p-52\'}`', CandidateScanner::JS))->toBe(['p-50', 'p-51', 'p-52'])
        ->and(CandidateScanner::scan('`a-${size} b-${x ? \'c\' : "d"}`', CandidateScanner::JS))->toBe(['a-', 'b-', 'c', 'd']);
});
