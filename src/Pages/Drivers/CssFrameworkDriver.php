<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Pages\Drivers;

use Daikazu\BladeWind\Pages\SplitStylesheet;

/**
 * Describes one CSS framework's compiled output to the page-styles pipeline: how to split a
 * stylesheet into the root every page shares and the utilities a page is built from, which class
 * tokens each utility rule is indexed under, and how a page's rules are wrapped so they keep their
 * position in the cascade.
 *
 * The rest of the pipeline is framework-neutral. Class tokens are opaque strings matched against
 * class selectors, and the variable graph, support-layer subsets and generated-file store work on
 * whatever the split returns. A driver with nothing to shake returns a split with
 * `supportShaken = true` and every support part empty.
 *
 * Register your own drivers in `bladewind.drivers`; they are tried before the built-in ones (tagged
 * `bladewind.css-drivers`). {@see DriverSelector} picks one per stylesheet from `bladewind.framework`.
 * For a framework without cascade layers, extend {@see FlatDriver}.
 */
interface CssFrameworkDriver
{
    /**
     * The value `bladewind.framework` selects this driver by, also shown as `framework=` in the
     * debug header: `tailwind4`, `tailwind3`, ...
     */
    public function name(): string;

    /**
     * Whether $css looks like this framework's output. Called under `bladewind.framework => 'auto'`
     * before any split, so keep it cheap. A false positive costs a split that finds nothing (BW6002);
     * a false negative costs the full-stylesheet fallback.
     */
    public function detect(string $css): bool;

    /**
     * Splits $css into root, utilities and support parts. Return `found = false` when the
     * stylesheet has none of what {@see self::expects()} describes.
     */
    public function split(string $css): SplitStylesheet;

    /**
     * The class tokens a utility rule is indexed under, given its selector list. Return the classes
     * the rule is *about*, not every class it mentions, so a marker class a variant only tests for
     * (`group`, `dark`) does not pull every variant rule into every page that uses it.
     *
     * @return list<string>
     */
    public function indexTokens(string $selectorList): array;

    /**
     * Wraps a page's selected rules the way this framework's cascade needs: inside the layer the
     * stylesheet keeps its utilities in, or bare when there is none. An empty string stays empty.
     */
    public function wrapUtilities(string $rules): string;

    /**
     * What {@see self::split()} looks for, as a noun phrase completing "Stylesheet x has no ..."
     * in BW6002: e.g. `top-level @layer utilities block`.
     */
    public function expects(): string;

    /**
     * Class tokens the framework's own JavaScript adds to elements it creates or toggles, which
     * view analysis cannot see (Bootstrap's `modal-backdrop`, `collapsing`, `tooltip`, `show`).
     * They join every page's class set (and its hash), so the rules they select are in every page
     * file. Return an empty list for a framework with no JavaScript of its own.
     *
     * @return list<string>
     */
    public function runtimeTokens(): array;
}
