<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Pages;

use Daikazu\BladeWind\Stylesheets\CssScanner;
use Daikazu\BladeWind\Stylesheets\TopLevelBlocks;

/**
 * Splits a Tailwind 4 stylesheet into its root, its `@layer utilities` block, and the support parts a
 * page only needs some of: the theme layer, the properties layer, and the top-level `@property` and
 * `@keyframes` rules.
 *
 * Only blocks opened at depth 0 are considered ({@see TopLevelBlocks::scan()}), so a `@layer utilities`
 * nested inside `@media` stays in the root.
 *
 * Extracting the support layers is all-or-nothing. If the theme or properties layer holds anything
 * other than `selector{--x:1}` blocks (a non-custom declaration, a nested rule, a second top-level
 * occurrence of the layer), every support part stays in `root`, `supportShaken` is false and
 * {@see SplitStylesheet::$unshakenReason} says why. An unfamiliar shape is delivered whole rather than
 * guessed at. An at-rule in the theme layer that holds only such blocks is fine: it is kept as a
 * wrapper ({@see ThemeBlock::$wrappers}) and reopened around whatever subset is emitted.
 */
final class StylesheetSplitter
{
    public function split(string $css): SplitStylesheet
    {
        $length = strlen($css);
        ['blocks' => $blocks, 'comments' => $comments] = TopLevelBlocks::scan($css);

        /** @var array{0: int, 1: int}|null first top-level utilities block, as [start, close] */
        $first = null;
        /** @var list<array{0: int, 1: int}> later top-level utilities blocks, as [start, close] */
        $removed = [];
        $utilities = '';
        /** @var list<array{start: int, open: int, close: int}> top-level `@layer theme` blocks */
        $themeBlocks = [];
        /** @var list<array{start: int, open: int, close: int}> top-level `@layer properties` blocks */
        $propertiesBlocks = [];
        /** @var list<array{kind: string, name: string, start: int, close: int}> top-level `@property` and `@keyframes` rules */
        $detached = [];

        foreach ($blocks as $block) {
            if ($block['prelude'] === SplitStylesheet::UTILITIES_PRELUDE) {
                $utilities .= substr($css, $block['open'] + 1, $block['close'] - $block['open'] - 1);

                if ($first === null) {
                    $first = [$block['start'], $block['close']];
                } else {
                    $removed[] = [$block['start'], $block['close']];
                }

                continue;
            }

            if ($block['prelude'] === SplitStylesheet::THEME_PRELUDE) {
                $themeBlocks[] = ['start' => $block['start'], 'open' => $block['open'], 'close' => $block['close']];

                continue;
            }

            if ($block['prelude'] === SplitStylesheet::PROPERTIES_PRELUDE) {
                $propertiesBlocks[] = ['start' => $block['start'], 'open' => $block['open'], 'close' => $block['close']];

                continue;
            }

            $rule = TopLevelBlocks::detachableRule($block['prelude']);

            if ($rule !== null) {
                $detached[] = ['kind' => $rule[0], 'name' => $rule[1], 'start' => $block['start'], 'close' => $block['close']];
            }
        }

        if ($first === null) {
            return new SplitStylesheet($css, '', false);
        }

        /** @var list<array{start: int, close: int, replacement: string, label: string|null}> $utilityRanges */
        $utilityRanges = [['start' => $first[0], 'close' => $first[1], 'replacement' => SplitStylesheet::UTILITIES_STATEMENT, 'label' => 'utilities']];

        foreach ($removed as [$start, $close]) {
            $utilityRanges[] = ['start' => $start, 'close' => $close, 'replacement' => '', 'label' => null];
        }

        $theme = [];
        $properties = null;
        $reason = null;

        if (count($themeBlocks) > 1) {
            $reason = 'second top-level '.SplitStylesheet::THEME_PRELUDE;
        } elseif (count($propertiesBlocks) > 1) {
            $reason = 'second top-level '.SplitStylesheet::PROPERTIES_PRELUDE;
        }

        if ($reason === null && $themeBlocks !== []) {
            $theme = self::parseThemeBlocks($css, $themeBlocks[0]['open'] + 1, $themeBlocks[0]['close'], $comments, $reason) ?? [];
        }

        if ($reason === null && $propertiesBlocks !== []) {
            $properties = self::parsePropertiesLayer($css, $propertiesBlocks[0]['open'] + 1, $propertiesBlocks[0]['close'], $reason);
        }

        if ($reason !== null) {
            // Utilities-only split: the support layers stay in the root, which every page links, so
            // nothing a page needs can be missing. The reason is reported as BW6005.
            [$root, $statements] = TopLevelBlocks::applyRanges($css, $utilityRanges);

            return new SplitStylesheet($root, $utilities, true, statements: $statements, unshakenReason: $reason);
        }

        $ranges = $utilityRanges;

        foreach ($themeBlocks as $block) {
            $ranges[] = ['start' => $block['start'], 'close' => $block['close'], 'replacement' => SplitStylesheet::THEME_STATEMENT, 'label' => 'theme'];
        }

        foreach ($propertiesBlocks as $block) {
            $ranges[] = ['start' => $block['start'], 'close' => $block['close'], 'replacement' => SplitStylesheet::PROPERTIES_STATEMENT, 'label' => 'properties'];
        }

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

        [$root, $statements] = TopLevelBlocks::applyRanges($css, $ranges);

        return new SplitStylesheet(
            $root,
            $utilities,
            true,
            $theme,
            $properties,
            $propertyRules,
            $keyframes,
            true,
            $statements,
        );
    }

    /**
     * The theme layer's blocks, or null when its contents are anything other than
     * `selector{custom properties}` blocks and at-rules holding nothing but those.
     *
     * A conditional group inside the layer is followed rather than refused, because a subset
     * re-emitted inside the same group means the same thing. Tailwind emits one for every
     * `color-mix()` theme value (`@supports (color:color-mix(in lab, red, red))` around a second
     * `:root,:host` block), so any theme that derives one colour from another has one.
     *
     * @param  int  $start  first byte inside the enclosing braces
     * @param  int  $end  the enclosing closing brace
     * @param  list<array{0: int, 1: int}>  $comments
     * @param  string|null  $reason  set to what stopped the parse when it returns null
     * @param  list<string>  $wrappers  the at-rule preludes this level is already inside, outermost first
     * @return list<ThemeBlock>|null
     *
     * @param-out string|null $reason
     */
    private static function parseThemeBlocks(string $css, int $start, int $end, array $comments, ?string &$reason, array $wrappers = []): ?array
    {
        /** @var list<ThemeBlock> $blocks */
        $blocks = [];
        $position = TopLevelBlocks::skipWhitespace($css, $start, $end);

        while ($position < $end) {
            $open = self::findBlockOpen($css, $position, $end);
            $close = $open === null ? null : self::findBlockClose($css, $open, $end);
            // Comments are stripped from the selector; keeping one would repeat it in every page
            // file that carried one of the block's declarations.
            $selector = $open === null ? '' : trim(TopLevelBlocks::withoutComments($css, $position, $open, $comments));

            if ($open === null || $close === null || $selector === '') {
                $reason = 'unrecognised shape in '.SplitStylesheet::THEME_PRELUDE;

                return null;
            }

            if (str_starts_with($selector, '@')) {
                $nested = self::parseThemeBlocks($css, $open + 1, $close, $comments, $reason, [...$wrappers, $selector]);

                if ($nested === null) {
                    return null;
                }

                $blocks = [...$blocks, ...$nested];
            } else {
                $declarations = self::parseDeclarations($css, $open + 1, $close, SplitStylesheet::THEME_PRELUDE, $reason);

                if ($declarations === null) {
                    return null;
                }

                $blocks[] = new ThemeBlock($selector, $declarations, $wrappers);
            }

            $position = TopLevelBlocks::skipWhitespace($css, $close + 1, $end);
        }

        return $blocks;
    }

    /**
     * The properties layer: any number of at-rule wrappers, each holding nothing but the next one,
     * around a single `selector{custom properties}` block. Null when the layer holds anything else.
     *
     * @param  int  $start  first byte inside the layer's braces
     * @param  int  $end  the layer's closing brace
     * @param  string|null  $reason  set to what stopped the parse when it returns null
     *
     * @param-out string|null $reason
     */
    private static function parsePropertiesLayer(string $css, int $start, int $end, ?string &$reason): ?PropertiesLayer
    {
        /** @var list<string> $wrappers */
        $wrappers = [];

        while (true) {
            $position = TopLevelBlocks::skipWhitespace($css, $start, $end);
            $open = self::findBlockOpen($css, $position, $end);

            if ($open === null) {
                $reason = 'unrecognised shape in '.SplitStylesheet::PROPERTIES_PRELUDE;

                return null;
            }

            $prelude = trim(substr($css, $position, $open - $position));
            $close = self::findBlockClose($css, $open, $end);

            if ($prelude === '' || $close === null) {
                $reason = 'unrecognised shape in '.SplitStylesheet::PROPERTIES_PRELUDE;

                return null;
            }

            if (trim(substr($css, $close + 1, $end - $close - 1)) !== '') {
                $reason = 'more than one block in '.SplitStylesheet::PROPERTIES_PRELUDE;

                return null;
            }

            if (! str_starts_with($prelude, '@')) {
                $declarations = self::parseDeclarations($css, $open + 1, $close, SplitStylesheet::PROPERTIES_PRELUDE, $reason);

                return $declarations === null ? null : new PropertiesLayer($wrappers, $prelude, $declarations);
            }

            $wrappers[] = $prelude;
            $start = $open + 1;
            $end = $close;
        }
    }

    /**
     * The custom property declarations between $start and $end, in source order, or null when the
     * region holds a nested block or a declaration that is not a custom property.
     *
     * @param  string  $layer  the layer being parsed, for the reason a failure reports
     * @param  string|null  $reason  set to what stopped the parse when it returns null
     * @return array<string, string>|null
     *
     * @param-out string|null $reason
     */
    private static function parseDeclarations(string $css, int $start, int $end, string $layer, ?string &$reason): ?array
    {
        /** @var array<string, string> $declarations */
        $declarations = [];
        $segmentStart = $start;

        for ($i = $start; $i < $end; $i++) {
            $char = $css[$i];

            if ($char === '\\') {
                $i++;

                continue;
            }

            if ($char === '"' || $char === "'") {
                $i = CssScanner::skipString($css, $i, $end) - 1;

                continue;
            }

            if ($char === '/' && $i + 1 < $end && $css[$i + 1] === '*') {
                $i = CssScanner::skipComment($css, $i, $end) - 1;

                continue;
            }

            if ($char === '{' || $char === '}') {
                $reason = 'nested rule in '.$layer;

                return null;
            }

            if ($char === ';') {
                if (! self::addDeclaration($declarations, substr($css, $segmentStart, $i - $segmentStart))) {
                    $reason = 'non-custom declaration in '.$layer;

                    return null;
                }

                $segmentStart = $i + 1;
            }
        }

        if (! self::addDeclaration($declarations, substr($css, $segmentStart, $end - $segmentStart))) {
            $reason = 'non-custom declaration in '.$layer;

            return null;
        }

        return $declarations;
    }

    /**
     * Records one `--name:value` declaration and reports whether $text was one. Empty $text (after a
     * trailing semicolon) is accepted and records nothing.
     *
     * @param  array<string, string>  $declarations
     *
     * @param-out array<string, string> $declarations
     */
    private static function addDeclaration(array &$declarations, string $text): bool
    {
        $text = trim($text);

        if ($text === '') {
            return true;
        }

        $colon = strpos($text, ':');

        if ($colon === false) {
            return false;
        }

        $name = rtrim(substr($text, 0, $colon));

        if (preg_match('~^--[^\s:{}]*$~', $name) !== 1) {
            return false;
        }

        $declarations[$name] = trim(substr($text, $colon + 1));

        return true;
    }

    /**
     * The offset of the `{` that opens the block starting at $position, or null when a `}` or a `;`
     * comes first (a statement rather than a block) or the region ends without one.
     */
    private static function findBlockOpen(string $css, int $position, int $end): ?int
    {
        for ($i = $position; $i < $end; $i++) {
            $char = $css[$i];

            if ($char === '\\') {
                $i++;

                continue;
            }

            if ($char === '"' || $char === "'") {
                $i = CssScanner::skipString($css, $i, $end) - 1;

                continue;
            }

            if ($char === '/' && $i + 1 < $end && $css[$i + 1] === '*') {
                $i = CssScanner::skipComment($css, $i, $end) - 1;

                continue;
            }

            if ($char === '{') {
                return $i;
            }

            if ($char === '}' || $char === ';') {
                return null;
            }
        }

        return null;
    }

    /**
     * The offset of the `}` matching the `{` at $open, or null when the region ends first.
     */
    private static function findBlockClose(string $css, int $open, int $end): ?int
    {
        $depth = 0;

        for ($i = $open; $i < $end; $i++) {
            $char = $css[$i];

            if ($char === '\\') {
                $i++;

                continue;
            }

            if ($char === '"' || $char === "'") {
                $i = CssScanner::skipString($css, $i, $end) - 1;

                continue;
            }

            if ($char === '/' && $i + 1 < $end && $css[$i + 1] === '*') {
                $i = CssScanner::skipComment($css, $i, $end) - 1;

                continue;
            }

            if ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;

                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }
}
