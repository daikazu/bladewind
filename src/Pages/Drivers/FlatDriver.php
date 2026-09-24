<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Pages\Drivers;

use Daikazu\BladeWind\Pages\FlatStylesheetSplitter;
use Daikazu\BladeWind\Pages\SelectorTokens;
use Daikazu\BladeWind\Pages\SplitStylesheet;

/**
 * Base driver for any framework whose compiled stylesheet uses no cascade layers: Tailwind 3,
 * Tachyons, Bootstrap, Bulma, Foundation, or one an application adds.
 *
 * The split is {@see FlatStylesheetSplitter}'s cut at the first rule with a class selector. A rule is
 * indexed under the classes of the element it styles ({@see SelectorTokens::targets()}). A page's
 * rules are emitted bare, since wrapping them in a layer would rank them below every unlayered rule
 * in the root. A subclass supplies {@see self::name()} and {@see self::detect()}, and overrides
 * {@see self::runtimeTokens()} when the framework's JavaScript adds classes no view contains.
 *
 * Extend this to add your own driver (register it in `bladewind.drivers`).
 */
abstract class FlatDriver implements CssFrameworkDriver
{
    public function __construct(protected FlatStylesheetSplitter $splitter) {}

    abstract public function name(): string;

    abstract public function detect(string $css): bool;

    public function split(string $css): SplitStylesheet
    {
        return $this->splitter->split($css);
    }

    public function indexTokens(string $selectorList): array
    {
        return SelectorTokens::targets($selectorList);
    }

    public function wrapUtilities(string $rules): string
    {
        return $rules;
    }

    public function expects(): string
    {
        return 'rule with a class selector';
    }

    public function runtimeTokens(): array
    {
        return [];
    }
}
