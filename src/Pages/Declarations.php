<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Pages;

/**
 * Serialising a custom-property declaration map back to CSS, shared by the two support-layer value
 * objects ({@see ThemeBlock} and {@see PropertiesLayer}) and by whatever emits a subset of one.
 */
final class Declarations
{
    /**
     * `--x:1;--y:2` for the given name → value map, in the map's own order.
     *
     * @param  array<string, string>  $declarations
     */
    public static function css(array $declarations): string
    {
        $parts = [];

        foreach ($declarations as $name => $value) {
            $parts[] = $name.':'.$value;
        }

        return implode(';', $parts);
    }

    /**
     * `wrappers{selector{--x:1}}`: one declaration block inside the at-rules it was written in,
     * outermost first, or `''` when there is nothing to declare, so no empty block or wrapper is
     * emitted.
     *
     * @param  list<string>  $wrappers  at-rule preludes around the block, outermost first
     * @param  array<string, string>  $declarations
     */
    public static function block(array $wrappers, string $selector, array $declarations): string
    {
        if ($declarations === []) {
            return '';
        }

        $css = $selector.'{'.self::css($declarations).'}';

        foreach (array_reverse($wrappers) as $wrapper) {
            $css = $wrapper.'{'.$css.'}';
        }

        return $css;
    }
}
