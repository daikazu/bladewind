<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Pages\Drivers;

/**
 * Tailwind CSS 3: no cascade layers in the output, the `--tw-*` defaults in a plain
 * `*,:before,:after{...}` rule, `@keyframes` next to the utility that uses them, and variants
 * compiled into a class on the marker element (`.group:hover .group-hover\:flex`, `:is(.dark *)`).
 *
 * The split is {@see FlatStylesheetSplitter}'s cut: preflight, the defaults rule and user base
 * styles form the root, and everything from the first class rule on (`.container`, then the
 * utilities) is available to pages. The defaults rule declares every `--tw-*` in the root, so there
 * is no theme to shake and each page file holds only its utilities.
 */
final class Tailwind3Driver extends FlatDriver
{
    public function name(): string
    {
        return 'tailwind3';
    }

    /**
     * The `--tw-*` namespace, which every Tailwind 3 build declares in its defaults rule. Tailwind 4
     * uses it too but is tried first, so a stylesheet that reaches this check has no top-level
     * utilities layer.
     */
    public function detect(string $css): bool
    {
        return str_contains($css, '--tw-');
    }
}
