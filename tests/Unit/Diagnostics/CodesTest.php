<?php

declare(strict_types=1);

use Daikazu\BladeWind\Diagnostics\Codes;
use Daikazu\BladeWind\Diagnostics\Severity;

it('lists the analysis, class, stylesheet, and page codes in order', function (): void {
    expect(Codes::all())->toBe([
        'BW1001', 'BW1002', 'BW1003', 'BW1004', 'BW1005', 'BW1006', 'BW1008',
        'BW2001', 'BW2002', 'BW2003', 'BW2004', 'BW2005', 'BW2006', 'BW2007',
        'BW3001',
        'BW3002',
        'BW6001', 'BW6002', 'BW6003', 'BW6004', 'BW6005',
    ]);
});

it('renders the class-analysis and graph diagnostic templates', function (): void {
    expect(Codes::make(Codes::ALPINE_BINDING_UNRESOLVED, ['attribute' => ':class', 'tag' => 'div', 'expression' => 'themeClasses[current]', 'candidates' => 'none'])->message)
        ->toBe('Alpine binding :class on <div> is not statically enumerable: themeClasses[current]. Recorded candidates: none. Declare the classes it can produce if they are not among them.')
        ->and(Codes::make(Codes::DEPENDENCY_CYCLE, ['cycle' => 'a -> b -> a'])->severity)->toBe(Severity::Info)
        ->and(Codes::make(Codes::HELPER_ELEMENT_UNREADABLE, ['helper' => '@class', 'expression' => '$x'])->severity)->toBe(Severity::Warning)
        ->and(Codes::make(Codes::SAFELIST_ENTRY_INVALID, ['entry' => 'a b'])->severity)->toBe(Severity::Warning);
});

it('builds a diagnostic with substituted placeholders and position', function (): void {
    $diagnostic = Codes::make(Codes::VIEW_NOT_FOUND, ['target' => 'partials.nope', 'directive' => 'include'], 12, 5);

    expect($diagnostic->code)->toBe('BW1002')
        ->and($diagnostic->severity)->toBe(Severity::Warning)
        ->and($diagnostic->message)->toBe('View [partials.nope] referenced by @include was not found in any view path.')
        ->and($diagnostic->line)->toBe(12)
        ->and($diagnostic->column)->toBe(5);
});

it('assigns each code its documented severity', function (): void {
    expect(Codes::make(Codes::UNRESOLVED_DIRECTIVE_TARGET, ['directive' => 'include'])->severity)->toBe(Severity::Warning)
        ->and(Codes::make(Codes::COMPONENT_NOT_FOUND, ['target' => 'x'])->severity)->toBe(Severity::Warning)
        ->and(Codes::make(Codes::DYNAMIC_COMPONENT_TARGET, ['detail' => ''])->severity)->toBe(Severity::Info)
        ->and(Codes::make(Codes::CLASS_COMPONENT_VIEW_UNKNOWN, ['class' => 'A'])->severity)->toBe(Severity::Info)
        ->and(Codes::make(Codes::PATH_SKIPPED, ['detail' => 'x'])->severity)->toBe(Severity::Info)
        ->and(Codes::make(Codes::ANALYSIS_FAILED, ['message' => 'boom'])->severity)->toBe(Severity::Error);
});

it('rejects unknown codes', function (): void {
    Codes::make('BW9999');
})->throws(InvalidArgumentException::class);
