<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Pages\Drivers;

/**
 * Foundation for Sites 6: one flat stylesheet with no cascade layers, custom properties, keyframes or
 * banner. The minified build opens with a media query holding `.reveal` rules before normalize, so
 * the cut falls at the very start: the root is just the `@charset` statement, and normalize's
 * element rules reach every page as unconditional rules.
 *
 * Foundation's jQuery plugins create elements and toggle classes no view contains (the reveal and
 * off-canvas overlays, tooltips, `is-open`, `is-active`, `is-stuck`), so {@see self::runtimeTokens()}
 * names them and every page carries their rules.
 */
final class FoundationDriver extends FlatDriver
{
    /**
     * Classes Foundation's plugins add to elements they create or toggle. Classes the markup itself
     * carries (`reveal`, `dropdown-pane`, `off-canvas`, `accordion`) are left out.
     */
    private const RUNTIME_TOKENS = [
        'is-open', 'is-closed', 'is-opening', 'is-closing', 'is-active', 'is-visible', 'is-closable',
        'reveal-overlay', 'is-reveal-open', 'without-overlay',
        'js-off-canvas-overlay', 'is-off-canvas-open', 'is-overlay-fixed', 'is-overlay-absolute', 'is-transition-push', 'is-transition-overlap', 'has-transition-push', 'has-transition-overlap',
        'js-dropdown-active', 'is-dropdown-submenu', 'is-dropdown-submenu-parent', 'opens-left', 'opens-right', 'opens-inner',
        'is-drilldown', 'is-drilldown-submenu', 'is-drilldown-submenu-parent', 'is-drilldown-submenu-item',
        'is-accordion-submenu', 'is-accordion-submenu-parent',
        'tooltip', 'has-tip', 'top', 'bottom', 'left', 'right', 'align-center', 'align-top', 'align-bottom', 'align-left', 'align-right',
        'sticky-container', 'is-stuck', 'is-anchored', 'is-at-top', 'is-at-bottom',
        'is-invalid-input', 'is-invalid-label', 'form-error',
    ];

    public function name(): string
    {
        return 'foundation';
    }

    /**
     * With no custom properties or banner to match, the signature is two classes only Foundation
     * names: the reveal overlay and the XY grid container.
     */
    public function detect(string $css): bool
    {
        return str_contains($css, '.reveal-overlay{') && str_contains($css, '.grid-x{');
    }

    public function runtimeTokens(): array
    {
        return self::RUNTIME_TOKENS;
    }
}
