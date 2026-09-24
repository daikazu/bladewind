<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Pages;

use Daikazu\BladeWind\Stylesheets\TopLevelBlocks;

/**
 * Splits a stylesheet that keeps its utilities in no cascade layer (Tailwind 3, Tachyons, any flat
 * utility stylesheet) by cutting it in two rather than sorting it.
 *
 * The root is everything before the first top-level block with a class selector (reset, defaults,
 * base rules); the utilities are everything from that block on, in source order. The root precedes
 * the page file in the document, so every pair of rules keeps its original relative order and the
 * cascade matches the full stylesheet without a layer to pin it. Class-less rules after the cut
 * (`[x-cloak]`, a late `:root{--brand}`, Tachyons' `img{max-width}`) stay where they are and reach
 * every page as unconditional rules; moving them into the root would put them ahead of utilities of
 * equal specificity.
 *
 * Only `@keyframes` and `@property` rules are lifted out, since they are position-independent. As
 * under Tailwind 4, the root still carries every keyframe rule, because an animation can be named by
 * markup or script the server never sees. With no theme layer, the split reports
 * `supportShaken = true` with every support part empty: there was nothing to shake, which differs
 * from a shape that could not be shaken.
 */
final class FlatStylesheetSplitter
{
    public function split(string $css): SplitStylesheet
    {
        $length = strlen($css);
        ['blocks' => $blocks] = TopLevelBlocks::scan($css);

        /** @var int|null $boundary the byte the first class-bearing top-level block starts at */
        $boundary = null;
        /** @var list<array{kind: string, name: string, start: int, close: int}> top-level `@property` and `@keyframes` rules */
        $detached = [];

        foreach ($blocks as $block) {
            $rule = TopLevelBlocks::detachableRule($block['prelude']);

            if ($rule !== null) {
                $detached[] = ['kind' => $rule[0], 'name' => $rule[1], 'start' => $block['start'], 'close' => $block['close']];

                continue;
            }

            if ($boundary === null && self::bearsClass($css, $block)) {
                $boundary = $block['start'];
            }
        }

        if ($boundary === null) {
            return new SplitStylesheet($css, '', false);
        }

        // A zero-width range at the boundary: nothing is removed there, and its offset in the
        // rebuilt text is where the root ends once the detached rules ahead of it have gone.
        $ranges = [['start' => $boundary, 'close' => $boundary - 1, 'replacement' => '', 'label' => 'cut']];
        /** @var array<string, string> $propertyRules */
        $propertyRules = [];
        /** @var array<string, string> $keyframes */
        $keyframes = [];
        $starts = array_column($detached, 'start');

        foreach ($detached as $rule) {
            $close = TopLevelBlocks::withSeparator($css, $rule['close'], $length, $starts);
            $ranges[] = ['start' => $rule['start'], 'close' => $close, 'replacement' => '', 'label' => null];
            $text = substr($css, $rule['start'], $close - $rule['start'] + 1);

            // A name can be registered twice (`@-webkit-keyframes spin` beside `@keyframes spin`), and
            // both rules have to travel together: whichever page needs the name needs all of them.
            if ($rule['kind'] === 'property') {
                $propertyRules[$rule['name']] = ($propertyRules[$rule['name']] ?? '').$text;
            } else {
                $keyframes[$rule['name']] = ($keyframes[$rule['name']] ?? '').$text;
            }
        }

        [$joined, $statements] = TopLevelBlocks::applyRanges($css, $ranges);
        $cut = $statements['cut'];

        return new SplitStylesheet(
            substr($joined, 0, $cut),
            substr($joined, $cut),
            true,
            propertyRules: $propertyRules,
            keyframes: $keyframes,
            supportShaken: true,
        );
    }

    /**
     * Whether a top-level block is one a class token can select: a style rule whose selector list
     * names a class, or a wrapper at-rule (`@media`, `@supports`, ...) holding such a rule at any
     * depth. A wrapper's body is parsed with the rule index rather than searched for a `.`, since
     * `0.5rem` in a declaration value would look like a class selector.
     *
     * @param  array{prelude: string, start: int, open: int, close: int}  $block
     */
    private static function bearsClass(string $css, array $block): bool
    {
        if (! str_starts_with($block['prelude'], '@')) {
            return SelectorTokens::tokens($block['prelude']) !== [];
        }

        if (! UtilityRuleIndex::isWrapperAtRule($block['prelude'])) {
            return false;
        }

        return UtilityRuleIndex::build(substr($css, $block['open'] + 1, $block['close'] - $block['open'] - 1))->tokens() !== [];
    }
}
