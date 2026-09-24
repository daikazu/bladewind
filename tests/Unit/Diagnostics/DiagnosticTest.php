<?php

declare(strict_types=1);

use Daikazu\BladeWind\Diagnostics\Diagnostic;
use Daikazu\BladeWind\Diagnostics\Severity;

it('round-trips through arrays with keys in a fixed order', function (): void {
    $diagnostic = new Diagnostic('BW1008', Severity::Error, 'Analysis failed: boom', null, null);

    $array = $diagnostic->toArray();

    expect(array_keys($array))->toBe(['code', 'severity', 'message', 'line', 'column'])
        ->and($array['severity'])->toBe('error')
        ->and(Diagnostic::fromArray($array))->toEqual($diagnostic);
});
