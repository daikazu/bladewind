<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Pages\Drivers;

/**
 * Bulma 1: one flat stylesheet, no cascade layers, design tokens as `--bulma-*` custom properties on
 * `:root` (with light and dark variants under `prefers-color-scheme` and
 * `.theme-light,[data-theme=light]` / `.theme-dark,[data-theme=dark]`), components that set their
 * own `--bulma-button-*` variables in the rule that reads them, a minireset, and `@keyframes` for
 * the loader and progress bar.
 *
 * The split is {@see FlatStylesheetSplitter}'s cut, and it falls early: the theme rules are the first
 * blocks with a class selector, so the root is just the `:root` variables and their colour-scheme
 * variants. Each theme rule's selector list includes a class-less attribute selector, which makes the
 * rule unconditional, so every page keeps it; minireset's element rules follow and are unconditional
 * too.
 *
 * Bulma ships no JavaScript. The `is-active` its navbar, dropdown, modal and tabs rely on is toggled
 * by the application through an Alpine binding the view analysis reads, so there are no runtime
 * tokens.
 */
final class BulmaDriver extends FlatDriver
{
    public function name(): string
    {
        return 'bulma';
    }

    /**
     * The `--bulma-*` namespace, which every Bulma 1 build declares on `:root`. Bulma 0.9 has no
     * custom properties and is not supported.
     */
    public function detect(string $css): bool
    {
        return str_contains($css, '--bulma-');
    }
}
