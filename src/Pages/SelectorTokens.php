<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Pages;

use Daikazu\BladeWind\Stylesheets\CssEscape;
use Daikazu\BladeWind\Stylesheets\CssScanner;

/**
 * The classes a selector list names, unescaped back to the token an author wrote, in three
 * readings: {@see self::tokens()} names every class the list mentions at all, {@see
 * self::subjects()} only the classes outside any functional pseudo-class, and {@see self::targets()}
 * only the classes of each selector's rightmost compound (the element the rule matches).
 *
 * A `.` only counts when it is really a class selector: the text inside an unescaped `[...]`
 * attribute selector and inside quoted strings is removed before matching, so `a[href$=".pdf"]` is
 * not a rule about a `pdf` class. Escapes are honoured throughout, which is what keeps the brackets
 * of an arbitrary-variant token such as `.has-\[\.child\]\:flex` inside the token.
 */
final class SelectorTokens
{
    /**
     * A class selector: a `.` followed by identifier bytes, where a backslash always takes the byte
     * after it (so `\:` stays inside the identifier) and a hex escape takes its digits plus the space
     * that terminates them (so `\32 xl` is one identifier, not `\32` followed by ` xl`).
     */
    private const CLASS_SELECTOR = '~\.((?:\\\\[0-9a-fA-F]{1,6} ?|\\\\.|[\w-]|[\x80-\xff])+)~';

    /**
     * @return list<string> unique, in order of appearance
     */
    public static function tokens(string $selectorList): array
    {
        if (! str_contains($selectorList, '.')) {
            return [];
        }

        return self::match(self::readable($selectorList, subjectsOnly: false));
    }

    /**
     * The classes of the subject compounds: those written outside every functional pseudo-class, so
     * `.dark\:bg-black:where(.dark, .dark *)` is about `dark:bg-black` and not about `dark`. This
     * keeps every `dark:` rule out of a page that merely carries `class="dark"` on `<html>`.
     * Empty when the list names no class outside a functional pseudo-class
     * (`:where(.space-y-6>:not(:last-child))`); callers then fall back to {@see self::tokens()}.
     *
     * @return list<string> unique, in order of appearance
     */
    public static function subjects(string $selectorList): array
    {
        if (! str_contains($selectorList, '.')) {
            return [];
        }

        return self::match(self::readable($selectorList, subjectsOnly: true));
    }

    /**
     * The classes on the element each selector matches: per complex selector, the classes of its
     * rightmost compound outside any functional pseudo-class, so `.group:hover .group-hover\:flex`
     * is about `group-hover:flex`, not the marker class. A compound that is one `:is()`/`:where()`
     * around a selector list is read inside; a compound with no class of its own (`.prose h1`)
     * falls back to every class its selector mentions. Unique, in order of appearance; empty only
     * when the list names no class at all.
     *
     * Tailwind 3 compiles its variants this way. Tailwind 4 puts the marker inside `:where()` on the
     * utility's own compound, which {@see self::subjects()} reads.
     *
     * @return list<string>
     */
    public static function targets(string $selectorList): array
    {
        if (! str_contains($selectorList, '.')) {
            return [];
        }

        $tokens = [];
        $seen = [];

        foreach (self::splitTopLevel($selectorList, ',') as $complex) {
            foreach (self::targetsOf($complex) as $token) {
                if (! isset($seen[$token])) {
                    $seen[$token] = true;
                    $tokens[] = $token;
                }
            }
        }

        return $tokens;
    }

    /**
     * Whether any selector in the list names no class that owns it, so the rule can match whatever
     * classes a page uses (`code,.code{...}`, or a minifier's merge of a utility into a reset's
     * element list).
     *
     * A class inside `:not()` never owns the rule (`p:not(.lead)`), nor does a class that is one
     * alternative of an `:is()`/`:where()` beside a class-less one (`:is(.a, p)`).
     */
    public static function hasSelectorWithoutClass(string $selectorList): bool
    {
        foreach (self::splitTopLevel($selectorList, ',') as $complex) {
            if (self::isClassless($complex)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether one complex selector can match an element on a page that carries none of the classes
     * it names: it names none once its `:not()` groups are gone, or the only classes it names sit
     * inside an `:is()`/`:where()` one of whose alternatives names none.
     */
    private static function isClassless(string $complex): bool
    {
        $complex = self::withoutNegations($complex);

        if (self::tokens($complex) === []) {
            return true;
        }

        if (self::subjects($complex) !== []) {
            // A class outside every functional pseudo-class is on a compound of the selector itself,
            // so no element matches without it.
            return false;
        }

        foreach (self::wrappedLists($complex) as $list) {
            foreach (self::splitTopLevel($list, ',') as $alternative) {
                if (self::tokens($alternative) === []) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * $complex with every `:not(...)` group removed, escapes and strings honoured.
     */
    private static function withoutNegations(string $complex): string
    {
        while (preg_match('~:not\(~i', $complex, $match, PREG_OFFSET_CAPTURE) === 1) {
            $open = $match[0][1] + strlen($match[0][0]) - 1;
            $close = self::matchingParenthesis($complex, $open);

            if ($close === null) {
                return substr($complex, 0, $match[0][1]);
            }

            $complex = substr($complex, 0, $match[0][1]).substr($complex, $close + 1);
        }

        return $complex;
    }

    /**
     * The selector lists inside every `:is(...)` and `:where(...)` of $complex, outermost first.
     *
     * @return list<string>
     */
    private static function wrappedLists(string $complex): array
    {
        $lists = [];
        $offset = 0;

        while (preg_match('~:(?:is|where)\(~i', $complex, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $open = $match[0][1] + strlen($match[0][0]) - 1;
            $close = self::matchingParenthesis($complex, $open);

            if ($close === null) {
                break;
            }

            $lists[] = substr($complex, $open + 1, $close - $open - 1);
            $offset = $open + 1;
        }

        return $lists;
    }

    /**
     * {@see self::targets()} for one complex selector.
     *
     * @return list<string>
     */
    private static function targetsOf(string $complex): array
    {
        $compounds = self::splitTopLevel($complex, " \t\n\r\f>~+");
        $compound = $compounds === [] ? '' : $compounds[count($compounds) - 1];
        $wrapped = self::unwrap($compound);

        if ($wrapped !== null) {
            $inner = self::targets($wrapped);

            if ($inner !== []) {
                return $inner;
            }
        }

        $classes = self::subjects($compound);

        return $classes === [] ? self::tokens($complex) : $classes;
    }

    /**
     * The selector list inside $compound when the compound opens with `:is(...)` or `:where(...)`
     * and whatever follows the wrapper names no class of its own (`:is(.dark .x):hover`); null
     * otherwise (`.b:is(.a)`, `:is(.a).b`, a plain compound).
     */
    private static function unwrap(string $compound): ?string
    {
        if (preg_match('~^:(?:is|where)\(~i', $compound, $matches) !== 1) {
            return null;
        }

        $open = strlen($matches[0]) - 1;
        $close = self::matchingParenthesis($compound, $open);

        if ($close === null || self::subjects(substr($compound, $close + 1)) !== []) {
            return null;
        }

        return substr($compound, $open + 1, $close - $open - 1);
    }

    /**
     * The offset of the `)` matching the `(` at $open, or null when the text ends first. Escapes and
     * strings are honoured, so a parenthesis inside either never counts.
     */
    private static function matchingParenthesis(string $selector, int $open): ?int
    {
        $length = strlen($selector);
        $depth = 0;

        for ($i = $open; $i < $length; $i++) {
            $char = $selector[$i];

            if ($char === '\\') {
                $i = self::escapeEnd($selector, $i, $length);

                continue;
            }

            if ($char === '"' || $char === "'") {
                $i = CssScanner::skipString($selector, $i, $length) - 1;

                continue;
            }

            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;

                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * $selector cut at every byte in $separators that sits at the top level (outside every
     * parenthesis, attribute selector and string, and not part of an escape), with empty pieces
     * dropped and the rest trimmed. Cutting on the combinator characters gives a complex selector's
     * compounds; cutting on `,` gives a list's complex selectors.
     *
     * @return list<string>
     */
    private static function splitTopLevel(string $selector, string $separators): array
    {
        $length = strlen($selector);
        $parentheses = 0;
        $brackets = 0;
        $pieces = [];
        $current = '';

        for ($i = 0; $i < $length; $i++) {
            $char = $selector[$i];

            if ($char === '\\') {
                $end = self::escapeEnd($selector, $i, $length);
                $current .= substr($selector, $i, $end - $i + 1);
                $i = $end;

                continue;
            }

            if ($char === '"' || $char === "'") {
                $end = CssScanner::skipString($selector, $i, $length);
                $current .= substr($selector, $i, $end - $i);
                $i = $end - 1;

                continue;
            }

            if ($char === '(') {
                $parentheses++;
            } elseif ($char === ')') {
                $parentheses = max(0, $parentheses - 1);
            } elseif ($char === '[') {
                $brackets++;
            } elseif ($char === ']') {
                $brackets = max(0, $brackets - 1);
            } elseif ($parentheses === 0 && $brackets === 0 && str_contains($separators, $char)) {
                if (trim($current) !== '') {
                    $pieces[] = trim($current);
                }

                $current = '';

                continue;
            }

            $current .= $char;
        }

        if (trim($current) !== '') {
            $pieces[] = trim($current);
        }

        return $pieces;
    }

    /**
     * The last byte of the escape starting with the backslash at $start: a hex escape takes its
     * digits and the single whitespace that terminates them (`\32 xl` is one identifier, and that
     * space is no descendant combinator); any other escape takes the byte after the backslash.
     */
    private static function escapeEnd(string $selector, int $start, int $length): int
    {
        $i = $start + 1;
        $digits = 0;

        while ($i < $length && $digits < 6 && ctype_xdigit($selector[$i])) {
            $i++;
            $digits++;
        }

        if ($digits === 0) {
            return min($start + 1, $length - 1);
        }

        return $i < $length && ctype_space($selector[$i]) ? $i : $i - 1;
    }

    /**
     * $selectorList reduced to the text a class selector can be read from: every unescaped `[...]`
     * attribute selector and every quoted string removed and, when $subjectsOnly, everything inside
     * `(...)` too. A backslash always takes the byte after it, so an escaped bracket or parenthesis
     * stays part of the identifier it belongs to and never opens a group.
     */
    private static function readable(string $selectorList, bool $subjectsOnly): string
    {
        $length = strlen($selectorList);
        $brackets = 0;
        $parentheses = 0;
        $result = '';

        for ($i = 0; $i < $length; $i++) {
            $char = $selectorList[$i];
            $inside = $brackets > 0 || ($subjectsOnly && $parentheses > 0);

            if ($char === '\\') {
                if (! $inside) {
                    $result .= $char.($selectorList[$i + 1] ?? '');
                }

                $i++;

                continue;
            }

            if ($char === '"' || $char === "'") {
                $i = CssScanner::skipString($selectorList, $i, $length) - 1;

                continue;
            }

            if ($char === '[') {
                $brackets++;

                continue;
            }

            if ($char === ']') {
                $brackets = max(0, $brackets - 1);

                continue;
            }

            if ($char === '(') {
                $parentheses++;
            } elseif ($char === ')') {
                $parentheses = max(0, $parentheses - 1);
            }

            if (! $inside) {
                $result .= $char;
            }
        }

        return $result;
    }

    /**
     * @return list<string> unique, in order of appearance
     */
    private static function match(string $selectorList): array
    {
        if (! str_contains($selectorList, '.')) {
            return [];
        }

        if (preg_match_all(self::CLASS_SELECTOR, $selectorList, $matches) === false) {
            return [];
        }

        $tokens = [];
        $seen = [];

        foreach ($matches[1] as $escaped) {
            $token = CssEscape::unescape($escaped);

            if (! isset($seen[$token])) {
                $seen[$token] = true;
                $tokens[] = $token;
            }
        }

        return $tokens;
    }
}
