<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Pages\Drivers;

use Daikazu\BladeWind\Pages\SelectorTokens;
use Daikazu\BladeWind\Pages\SplitStylesheet;
use Daikazu\BladeWind\Pages\StylesheetSplitter;

/**
 * Tailwind CSS 4, built through Vite: top-level cascade layers (`@layer theme`, `base`,
 * `components`, `utilities`, plus the `properties` layer of `--tw-*` defaults), `@property`
 * registrations and `@keyframes` after them, and variants compiled into a functional pseudo-class
 * on the utility's own selector (`.dark\:bg-black:where(.dark,.dark *)`).
 *
 * The default driver. Its output is pinned byte for byte by golden digests in the test suite.
 */
final class Tailwind4Driver implements CssFrameworkDriver
{
    public function __construct(private StylesheetSplitter $splitter) {}

    public function name(): string
    {
        return 'tailwind4';
    }

    /**
     * A utilities layer opened as a block. This check does not verify the layer is top-level (the
     * splitter does); it only needs to be cheap and right for a real Tailwind 4 build, which always
     * opens the layer once.
     */
    public function detect(string $css): bool
    {
        return preg_match('~@layer\s+utilities\s*\{~', $css) === 1;
    }

    public function split(string $css): SplitStylesheet
    {
        return $this->splitter->split($css);
    }

    /**
     * The classes of the subject compounds, or, when the selector names no class outside a
     * functional pseudo-class at all (`:where(.space-y-6>:not(:last-child))`), every class it
     * mentions. Tailwind 4 puts a variant's marker inside `:where()`/`:is()`, which
     * {@see SelectorTokens::subjects()} leaves out.
     */
    public function indexTokens(string $selectorList): array
    {
        $subjects = SelectorTokens::subjects($selectorList);

        return $subjects === [] ? SelectorTokens::tokens($selectorList) : $subjects;
    }

    public function wrapUtilities(string $rules): string
    {
        return $rules === '' ? '' : SplitStylesheet::UTILITIES_PRELUDE.'{'.$rules.'}';
    }

    public function expects(): string
    {
        return 'top-level '.SplitStylesheet::UTILITIES_PRELUDE.' block';
    }

    public function runtimeTokens(): array
    {
        return [];
    }
}
