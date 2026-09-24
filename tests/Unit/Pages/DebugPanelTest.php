<?php

declare(strict_types=1);

use Daikazu\BladeWind\Pages\DebugPanel;

/**
 * @return array{set: string, tokens: int, analysed: int, unanalysed: int, support: string, delivery: string, framework: string, framework_detected: bool, cached: bool, root_file: string, page_file: string, root_bytes: int, root_gzip: int, page_bytes: int, page_gzip: int, full_bytes: int, full_gzip: int|null}
 */
function panelMetrics(array $overrides = []): array
{
    return array_merge([
        'set' => '7e76e4b84f69', 'tokens' => 272, 'analysed' => 9, 'unanalysed' => 0, 'support' => '93', 'delivery' => 'link', 'framework' => 'tailwind4', 'framework_detected' => true, 'cached' => true,
        'root_file' => 'root.css', 'page_file' => 'page.css', 'root_bytes' => 4737, 'root_gzip' => 1673, 'page_bytes' => 27215, 'page_gzip' => 4973,
        'full_bytes' => 90408, 'full_gzip' => 16109,
    ], $overrides);
}

it('renders nothing without metrics and never carries a class attribute or a script', function (): void {
    expect(DebugPanel::render([], 1.0))->toBe('');

    $html = DebugPanel::render(panelMetrics(), 21.2);

    expect($html)->toStartWith('<details data-bladewind-panel>')
        ->not->toContain('class=')
        ->not->toContain('<script')
        ->toContain('<style>');
});

it('puts the ring, the figures and the saving in the summary, and every per-response value in the scoped stylesheet', function (): void {
    $html = DebugPanel::render(panelMetrics(), 21.2);

    expect($html)->toContain('<b>6.5 KB</b><small>of 15.7 KB</small>')
        ->toContain('data-bw="delta">−59%<')
        ->toContain('[data-bladewind-panel] [data-bw=ring]{--bw-ring:41.3;--bw-tone:#34d399}')
        ->toContain('[data-bladewind-panel] [data-bw=bar]>span:first-child{width:10.4%}')
        ->toContain('[data-bladewind-panel] [data-bw=bar]>span:last-child{width:30.9%}')
        // The starting styles come after the per-response rules so they win the cascade at first paint.
        ->and(strpos($html, '@starting-style'))->toBeGreaterThan((int) strpos($html, '--bw-ring:41.3'))
        ->and(substr_count($html, 'style="'))->toBe(0);
});

it('keeps the build section to what the developer can act on', function (): void {
    $quiet = DebugPanel::render(panelMetrics(), 21.2);

    expect($quiet)->toContain('reused from disk · 21.2 ms')
        ->toContain('&lt;link&gt; to a cached file')
        ->toContain('Tailwind CSS 4')
        ->toContain('Recognised from the compiled stylesheet')
        ->not->toContain('Check')
        ->not->toContain('272')
        ->not->toContain('data-bw="hint"');

    $loud = DebugPanel::render(panelMetrics([
        'unanalysed' => 98, 'support' => 'full', 'rendered' => 150,
        'unanalysed_kinds' => ['strings' => 90, 'outside' => ['vendor/daikazu/social-links/resources/views', 'vendor/daikazu/laravel-glider/resources/views'], 'outside_count' => 6, 'outside_more' => false],
    ]), 1.0);

    expect($loud)->toContain('6 of 150 rendered views outside paths')
        ->toContain('Add to bladewind.paths: vendor/daikazu/social-links/resources/views, vendor/daikazu/laravel-glider/resources/views')
        ->toContain('theme kept whole in the root')
        ->toContain('BW6005')
        // String renders (Blade::render, inline SVG helpers) have nothing to configure, so no row.
        ->not->toContain('90')
        ->and(substr_count($loud, '>Check<'))->toBe(2);

    $stringsOnly = DebugPanel::render(panelMetrics(['unanalysed' => 90, 'rendered' => 150, 'unanalysed_kinds' => ['strings' => 90, 'outside' => [], 'outside_count' => 0, 'outside_more' => false]]), 1.0);

    expect($stringsOnly)->not->toContain('Check');
});

it('turns amber and says larger when the page loads more than the full stylesheet', function (): void {
    $html = DebugPanel::render(panelMetrics(['root_gzip' => 12000, 'page_gzip' => 6000]), 1.0);

    expect($html)->toContain('data-bw="delta">+12%<')
        ->toContain('--bw-tone:#fbbf24');
});

it('names the fallback reason in plain words with the next step, and carries the CSP nonce on its stylesheet', function (): void {
    $html = DebugPanel::render(['fallback' => 'vite hot'], 0.3, ' nonce="n0nce"');

    expect($html)->toContain('<style nonce="n0nce">')
        ->toContain('full stylesheet served instead')
        ->toContain('Vite dev server is running')
        ->toContain('Stop the dev server or run a build')
        ->and(DebugPanel::render(['fallback' => 'boom'], 0.3))->toContain('boom')->toContain('BW6001');
});

it('names the framework driver, and whether it was recognised or configured', function (): void {
    $configured = DebugPanel::render(panelMetrics(['framework' => 'tailwind3', 'framework_detected' => false]), 1.0);
    $custom = DebugPanel::render(panelMetrics(['framework' => 'unocss']), 1.0);
    $tachyons = DebugPanel::render(panelMetrics(['framework' => 'tachyons']), 1.0);

    expect($configured)->toContain('Tailwind CSS 3')
        ->toContain('bladewind.framework = tailwind3')
        ->not->toContain('Recognised from')
        ->and($tachyons)->toContain('>Tachyons<')
        ->and(DebugPanel::render(panelMetrics(['framework' => 'bootstrap5']), 1.0))->toContain('>Bootstrap 5<')
        ->and(DebugPanel::render(panelMetrics(['framework' => 'bulma']), 1.0))->toContain('>Bulma<')
        ->and(DebugPanel::render(panelMetrics(['framework' => 'foundation']), 1.0))->toContain('>Foundation for Sites<')
        ->and($custom)->toContain('>unocss<');
});
