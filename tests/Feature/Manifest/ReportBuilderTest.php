<?php

declare(strict_types=1);

use Daikazu\BladeWind\Analysis\ViewAnalyzer;
use Daikazu\BladeWind\Diagnostics\Codes;
use Daikazu\BladeWind\Discovery\ViewLocator;
use Daikazu\BladeWind\Manifest\ReportBuilder;

it('aggregates totals, diagnostics, and entries with keys in a stable order', function (): void {
    $entries = [];
    foreach (['views/pages/about.blade.php', 'views/pages/home.blade.php', 'views/components/card.blade.php'] as $relative) {
        $path = $this->fixturePath($relative);
        $entries[] = app(ViewAnalyzer::class)->analyze(app(ViewLocator::class)->describe($path), file_get_contents($path));
    }

    $report = app(ReportBuilder::class)->build(
        [$this->fixturePath('views')],
        $entries,
        [Codes::make(Codes::PATH_SKIPPED, ['detail' => 'Skipped /missing'])],
    );

    $array = $report->toArray();

    expect(array_keys($array))->toBe([
        'schema', 'generated_at', 'bladewind_version', 'compat_hash', 'paths', 'totals', 'graph', 'classes', 'diagnostics', 'report_diagnostics', 'entries',
    ])
        ->and($array['totals']['files'])->toBe(3)
        ->and($array['totals']['views'])->toBe(2)
        ->and($array['totals']['components'])->toBe(1)
        ->and(array_keys($array['totals']['dependencies']))->toBe([
            'component', 'include', 'include-first', 'each', 'extends', 'legacy-component', 'livewire', 'dynamic-component',
        ])
        ->and($array['totals']['dependencies']['component'])->toBe(8)
        ->and($array['totals']['dependencies']['livewire'])->toBe(2)
        ->and(array_keys($array['totals']['resolution']))->toBe(['static', 'declared', 'unresolved'])
        ->and($array['totals']['resolution']['unresolved'])->toBe(4)
        ->and(array_keys($array['diagnostics']))->toBe(Codes::all())
        ->and($array['diagnostics']['BW1002'])->toBe(1)
        ->and($array['report_diagnostics'][0]['code'])->toBe('BW1006')
        ->and(array_column($array['entries'], 'name'))->toBe(['components.card', 'pages.about', 'pages.home'])
        ->and($report->toJson())->toEndWith("\n")
        ->and(array_keys($array['graph']))->toBe(['nodes', 'edges', 'unresolved', 'cycles', 'most_depended'])
        ->and(array_keys($array['classes']))->toBe(['tokens', 'static', 'enumerable', 'runtime', 'candidate', 'declared', 'safelisted', 'unresolved'])
        ->and($array['classes']['tokens'])->toBeGreaterThan(0)
        ->and($array['graph']['nodes'])->toBe(0);
});
