<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Stylesheets;

/**
 * The blocks a stylesheet opens at depth 0, found by one string-, comment- and escape-aware walk:
 * a backslash consumes the next byte, and a quoted string or a `/* ... *\/` comment is skipped
 * whole, so a brace inside any of them never affects the nesting. Anything nested inside a
 * top-level block is part of its text.
 *
 * The walk is framework-neutral; each splitter decides what a block means. The range helpers are
 * the parts every splitter shares: recognising a detachable `@property`/`@keyframes` rule, carrying
 * its separator with it, and rebuilding the stylesheet with ranges replaced.
 */
final class TopLevelBlocks
{
    /**
     * Every top-level block of $css in stylesheet order, and every comment the walk crossed.
     *
     * A block's `prelude` is the text between the previous statement or block and its `{`, with
     * comments removed and whitespace collapsed; `start` is the offset of that prelude's first real
     * byte (so a leading comment or blank line stays outside the block's range), `open` the `{`
     * and `close` the matching `}`.
     *
     * @return array{blocks: list<array{prelude: string, start: int, open: int, close: int}>, comments: list<array{0: int, 1: int}>}
     */
    public static function scan(string $css): array
    {
        $length = strlen($css);
        /** @var list<array{start: int, open: int, topLevel: bool}> $stack */
        $stack = [];
        /** @var list<array{0: int, 1: int}> comment byte ranges [start, end) found anywhere in the scan */
        $comments = [];
        /** @var list<array{prelude: string, start: int, open: int, close: int}> $blocks */
        $blocks = [];
        $segmentStart = 0;

        for ($i = 0; $i < $length; $i++) {
            $char = $css[$i];

            if ($char === '\\') {
                // Skip the escaped byte so an escaped brace or quote is never read as structure.
                $i++;

                continue;
            }

            if ($char === '"' || $char === "'") {
                $i = CssScanner::skipString($css, $i, $length) - 1;

                continue;
            }

            if ($char === '/' && $i + 1 < $length && $css[$i + 1] === '*') {
                $end = CssScanner::skipComment($css, $i, $length);
                $comments[] = [$i, $end];
                $i = $end - 1;

                continue;
            }

            if ($char === '{') {
                $topLevel = $stack === [];
                $start = $topLevel ? self::preludeStart($css, $segmentStart, $i, $comments) : $segmentStart;
                $stack[] = ['start' => $start, 'open' => $i, 'topLevel' => $topLevel];
                $segmentStart = $i + 1;
            } elseif ($char === '}') {
                $frame = array_pop($stack);
                $segmentStart = $i + 1;

                if ($frame === null || ! $frame['topLevel']) {
                    continue;
                }

                $blocks[] = [
                    'prelude' => self::normalize(self::withoutComments($css, $frame['start'], $frame['open'], $comments)),
                    'start' => $frame['start'],
                    'open' => $frame['open'],
                    'close' => $i,
                ];
            } elseif ($char === ';') {
                // A semicolon ends a statement (`@layer components;`) or a declaration, so it never
                // leaks into the next prelude.
                $segmentStart = $i + 1;
            }
        }

        return ['blocks' => $blocks, 'comments' => $comments];
    }

    /**
     * The kind (`property` or `keyframes`) and the name of the rule $prelude opens, or null when it
     * opens something else. Vendor-prefixed `@keyframes` count too, since a page that needs the
     * animation needs the prefixed rule as much as the standard one.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function detachableRule(string $prelude): ?array
    {
        if (preg_match('~^@property\s+(--[^\s{]+)$~', $prelude, $matches) === 1) {
            return ['property', $matches[1]];
        }

        if (preg_match('~^@(?:-[a-z]+-)?keyframes\s+(\S.*)$~i', $prelude, $matches) === 1) {
            return ['keyframes', self::unquote($matches[1])];
        }

        return null;
    }

    /**
     * $close moved past the following whitespace, but only when that whitespace runs into another
     * detached rule or the end of the stylesheet. The rule then carries its own separator, so a
     * trailing run of registrations (as in every Tailwind build) round-trips byte for byte; whitespace
     * before anything else stays in the root, where it still separates the surrounding rules.
     *
     * @param  list<int>  $starts  the byte offset every detached rule begins at
     */
    public static function withSeparator(string $css, int $close, int $length, array $starts): int
    {
        $end = self::skipWhitespace($css, $close + 1, $length);

        return $end === $length || in_array($end, $starts, true) ? $end - 1 : $close;
    }

    /**
     * $css with each [start, close] range replaced by its replacement text, and the byte offset each
     * labelled replacement landed at in the result. Ranges are top-level siblings and never overlap.
     * The offsets are the only reliable way back to a statement a splitter wrote, since a comment or
     * hand-written `@layer theme;` earlier in the file has the same text.
     *
     * A zero-width range (`close` one less than `start`) inserts without removing anything; the flat
     * splitter uses one to mark where its root ends.
     *
     * @param  list<array{start: int, close: int, replacement: string, label: string|null}>  $ranges
     * @return array{0: string, 1: array<string, int>}
     */
    public static function applyRanges(string $css, array $ranges): array
    {
        usort($ranges, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);

        $result = '';
        $cursor = 0;
        /** @var array<string, int> $statements */
        $statements = [];

        foreach ($ranges as $range) {
            $result .= substr($css, $cursor, $range['start'] - $cursor);

            if ($range['label'] !== null) {
                $statements[$range['label']] = strlen($result);
            }

            $result .= $range['replacement'];
            $cursor = $range['close'] + 1;
        }

        return [$result.substr($css, $cursor), $statements];
    }

    /**
     * The offset of the next byte at or after $position that is not whitespace, capped at $end.
     */
    public static function skipWhitespace(string $css, int $position, int $end): int
    {
        while ($position < $end && ctype_space($css[$position])) {
            $position++;
        }

        return $position;
    }

    /**
     * The text of $css between $start and $end with any recorded comment ranges that fall
     * entirely inside it removed, so a comment never leaks into a computed prelude.
     *
     * @param  list<array{0: int, 1: int}>  $comments
     */
    public static function withoutComments(string $css, int $start, int $end, array $comments): string
    {
        $result = '';
        $cursor = $start;

        foreach ($comments as [$commentStart, $commentEnd]) {
            if ($commentStart < $start || $commentEnd > $end) {
                continue;
            }

            $result .= substr($css, $cursor, $commentStart - $cursor);
            $cursor = $commentEnd;
        }

        $result .= substr($css, $cursor, $end - $cursor);

        return $result;
    }

    /**
     * A `@keyframes` name with its quotes taken off. The grammar allows a string as well as an ident
     * (`@keyframes "spin"` animates whatever `animation-name:spin` names), so both spellings have to
     * key the same entry or a page's `animation:spin` would not find the rule.
     */
    private static function unquote(string $name): string
    {
        $quote = $name[0];

        return strlen($name) >= 2 && ($quote === '"' || $quote === "'") && str_ends_with($name, $quote)
            ? substr($name, 1, -1)
            : $name;
    }

    /**
     * A prelude reduced to the form a driver's comparisons expect: trimmed, with every run of
     * internal whitespace collapsed to one space, so `@layer  utilities{` and `@layer /*x*\/
     * utilities{` (whose comment `withoutComments()` has already removed) are both recognised.
     */
    private static function normalize(string $prelude): string
    {
        return trim((string) preg_replace('~\s+~', ' ', $prelude));
    }

    /**
     * The offset of the first real byte of a top-level block's prelude within [$segmentStart, $open):
     * $segmentStart skipped forward past any whitespace and whole comments, so a leading comment or
     * blank line before the block stays part of the root instead of being swallowed by the block's range.
     *
     * @param  list<array{0: int, 1: int}>  $comments
     */
    private static function preludeStart(string $css, int $segmentStart, int $open, array $comments): int
    {
        $pos = $segmentStart;

        while ($pos < $open) {
            if (ctype_space($css[$pos])) {
                $pos++;

                continue;
            }

            $commentEnd = self::commentEndingAt($pos, $comments);

            if ($commentEnd === null) {
                break;
            }

            $pos = $commentEnd;
        }

        return $pos;
    }

    /**
     * The end of the comment recorded as starting at $pos, if any.
     *
     * @param  list<array{0: int, 1: int}>  $comments
     */
    private static function commentEndingAt(int $pos, array $comments): ?int
    {
        foreach ($comments as [$commentStart, $commentEnd]) {
            if ($commentStart === $pos) {
                return $commentEnd;
            }
        }

        return null;
    }
}
