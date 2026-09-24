<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Pages;

/**
 * Every custom property a rendered document names in CSS of its own: `style` attributes, their
 * bound spellings (`:style`, `x-bind:style`, `wire:style`) and inline `<style>` blocks. None of
 * these leaves a class token for {@see HtmlClassScanner} to find, so the names are added to the
 * page's seeds.
 *
 * A `--x` anywhere else (a class name, a script's strings) is ignored. JavaScript that reads a
 * variable by name (`getPropertyValue('--color-red-500')`) is invisible here; use
 * `bladewind.pages.keep_variables` for those.
 */
final class HtmlVariableScanner
{
    private const STYLE_BLOCK = '~<style\b[^>]*>(.*?)</style\s*>~is';

    /**
     * An attribute whose name ends in `style`, quoted or unquoted (`style=--x:1`).
     *
     * The prefix lets Alpine and Livewire bindings (`:style`, `x-bind:style`, `wire:style`) count.
     * It also matches non-CSS attributes such as `data-style`, which is harmless: an undeclared name
     * is dropped before it reaches a page file ({@see PageStyles}).
     */
    private const STYLE_ATTRIBUTE = '~\s[\w.:-]*style\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+))~i';

    /**
     * A custom property name, whether declared (`--x:1`) or read (`var(--x, red)`). A comment's
     * closing `-->` does not match, since the name needs at least one character.
     */
    private const NAME = '~--[\w-]+~';

    /**
     * @return list<string> sorted, unique
     */
    public static function names(string $html): array
    {
        // Fast path: no `--` and no numeric entity that could decode to a hyphen means no names.
        // (Testing for `style` would never skip: every page carries `rel="stylesheet"`.)
        if ($html === '' || (! str_contains($html, '--') && ! str_contains($html, '&#'))) {
            return [];
        }

        $css = '';

        // Entities are decoded in attribute values (`&#45;&#45;x` is `--x`) but not in `<style>`
        // blocks, which are raw text.
        foreach (self::captures(self::STYLE_BLOCK, $html) as $block) {
            $css .= $block."\n";
        }

        foreach (self::captures(self::STYLE_ATTRIBUTE, $html) as $value) {
            $css .= html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8')."\n";
        }

        if ($css === '' || ! str_contains($css, '--')) {
            return [];
        }

        preg_match_all(self::NAME, $css, $matches);

        $names = array_values(array_unique($matches[0]));
        sort($names, SORT_STRING);

        return $names;
    }

    /**
     * Every non-empty capture $pattern makes in $html, from whichever of its alternatives matched.
     *
     * @return list<string>
     */
    private static function captures(string $pattern, string $html): array
    {
        preg_match_all($pattern, $html, $matches);

        /** @var list<string> $captures */
        $captures = [];

        foreach (array_slice($matches, 1) as $group) {
            foreach ($group as $capture) {
                if ($capture !== '') {
                    $captures[] = $capture;
                }
            }
        }

        return $captures;
    }
}
