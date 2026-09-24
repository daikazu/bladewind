<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Pages\Drivers;

/**
 * Tachyons: one flat stylesheet with no cascade layers or custom properties. Normalize and element
 * defaults come first; the first class rule is `.border-box` (listed with the form elements
 * normalize sizes), and everything after it is utilities in module order, with responsive variants
 * in `@media screen and (min-width: ...)` blocks. State variants are pseudo-classes on the
 * utility's own class (`.hover-white:hover`); the two-element helpers put the marker on the parent
 * (`.hide-child:hover .child`).
 *
 * The split is {@see FlatStylesheetSplitter}'s cut. Class-less rules after the cut
 * (`img{max-width:100%}`, `code,.code{...}`) stay where they fall and reach every page, which keeps
 * normalize's later rules in their cascade position.
 */
final class TachyonsDriver extends FlatDriver
{
    public function name(): string
    {
        return 'tachyons';
    }

    /**
     * The banner every Tachyons build starts with (`/*! TACHYONS v4.12.0 | http://tachyons.io *\/`,
     * a legal comment minifiers keep), or a class only Tachyons names for builds with comments
     * stripped anyway.
     */
    public function detect(string $css): bool
    {
        return preg_match('~/\*!?\s*TACHYONS\s+v\d~i', $css) === 1 || str_contains($css, '.aspect-ratio--16x9');
    }
}
