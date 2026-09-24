<?php

declare(strict_types=1);

use Daikazu\BladeWind\Testing\PageExpectation;

it('defaults to a covered page with no diagnostics', function (): void {
    $expect = new PageExpectation;

    expect($expect->reaches)->toBe([])
        ->and($expect->absent)->toBe([])
        ->and($expect->diagnostics)->toBe([])
        ->and($expect->fallback)->toBeFalse()
        ->and($expect->unanalysed)->toBeNull()
        ->and($expect->framework)->toBeNull();
});

it('normalises diagnostics to a sorted unique list', function (): void {
    $expect = new PageExpectation(diagnostics: ['BW2002', 'BW2001', 'BW2002']);

    expect($expect->diagnostics)->toBe(['BW2001', 'BW2002']);
});

it('refuses a diagnostic that is not a BW code', function (): void {
    new PageExpectation(diagnostics: ['warning']);
})->throws(InvalidArgumentException::class, 'BW');

it('refuses fallback together with the checks it skips', function (): void {
    new PageExpectation(fallback: true, diagnostics: ['BW2001']);
})->throws(InvalidArgumentException::class, 'fallback: true');

it('accepts a bare fallback', function (): void {
    $expect = new PageExpectation(fallback: true);

    expect($expect->fallback)->toBeTrue();
});
