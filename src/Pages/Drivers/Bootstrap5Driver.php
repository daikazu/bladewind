<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Pages\Drivers;

/**
 * Bootstrap 5: one flat stylesheet, no cascade layers, design tokens as `--bs-*` custom properties on
 * `:root` (and on `[data-bs-theme=...]`), components that set their own `--bs-btn-*` variables in
 * the rule that reads them, and `@keyframes` for spinners and stripes.
 *
 * The split is {@see FlatStylesheetSplitter}'s cut. The `:root` variables, box-sizing, `body` and
 * `hr` come before the first class rule (`.h1,.h2,...,h1,h2,...`) and form the root; the rest of
 * reboot follows and reaches every page as unconditional element rules. Every variable a component
 * reads is declared in the root or in the component's own rule, so there is nothing to shake.
 *
 * Bootstrap's JavaScript creates elements (`modal-backdrop`, `tooltip`, `popover`) and toggles
 * classes (`show`, `collapsing`, `active`) that no Blade view contains, so
 * {@see self::runtimeTokens()} names them and every page carries their rules.
 */
final class Bootstrap5Driver extends FlatDriver
{
    /**
     * Classes Bootstrap's JavaScript adds to elements it creates or toggles, taken from the
     * `ClassName` constants of its components. Classes the markup itself carries (`dropdown-menu`,
     * `modal`, `toast`) are left out; the view analysis sees those.
     */
    private const RUNTIME_TOKENS = [
        'active', 'show', 'showing', 'hiding', 'hide', 'fade', 'disabled', 'pointer-event',
        'collapse', 'collapsing',
        'dropup', 'dropend', 'dropstart',
        'modal-open', 'modal-backdrop', 'modal-static',
        'offcanvas-backdrop',
        'carousel-item-next', 'carousel-item-prev', 'carousel-item-start', 'carousel-item-end',
        'tooltip', 'tooltip-arrow', 'tooltip-inner', 'bs-tooltip-auto', 'bs-tooltip-top', 'bs-tooltip-bottom', 'bs-tooltip-start', 'bs-tooltip-end',
        'popover', 'popover-arrow', 'popover-header', 'popover-body', 'bs-popover-auto', 'bs-popover-top', 'bs-popover-bottom', 'bs-popover-start', 'bs-popover-end',
    ];

    public function name(): string
    {
        return 'bootstrap5';
    }

    /**
     * The `--bs-*` namespace, which every Bootstrap 5 build declares on `:root` (Bootstrap 4 has no
     * custom properties). Tailwind's drivers are tried first and the namespaces do not overlap.
     */
    public function detect(string $css): bool
    {
        return str_contains($css, '--bs-');
    }

    public function runtimeTokens(): array
    {
        return self::RUNTIME_TOKENS;
    }
}
