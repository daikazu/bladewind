<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Pages;

use Closure;
use Daikazu\BladeWind\Pages\Drivers\CssFrameworkDriver;
use Daikazu\BladeWind\Stylesheets\CssScanner;

/**
 * The utilities layer indexed by class token. One walk of the block records every rule under each
 * token its selector list is about, so building a page's stylesheet is a lookup per token rather
 * than a scan. Which classes a selector list is about is the CSS framework's reading
 * ({@see CssFrameworkDriver::indexTokens()}), or by default {@see self::indexedTokens()}.
 *
 * The walk is string-, comment- and escape-aware like `RuleExtractor::rules()`, so braces inside
 * a string, comment or escape never affect the stack.
 *
 * A rule is stored once however many tokens name it; `rulesFor()` returns the shared instances, so
 * a page that uses several tokens of one rule can deduplicate them by position.
 *
 * Tailwind 4's output is flat, but nested style rules are handled defensively: a nested rule is
 * folded into its enclosing rule, and the enclosing rule is also indexed under the nested
 * selector's tokens.
 *
 * Rules no class token can own are recorded as unconditional and carried by every page: a selector
 * list with a class-less selector in it (`[x-cloak]{...}`, `code,.code{...}`) and a non-wrapper
 * at-rule block (`@keyframes`, `@font-face`, `@property`, ...).
 */
final class UtilityRuleIndex
{
    /**
     * At-rules that wrap other rules: their contents are indexed individually and re-emitted under a
     * reopened wrapper. Every other at-rule is emitted whole and unconditionally.
     */
    private const WRAPPER_AT_RULES = ['media', 'supports', 'container', 'layer', 'starting-style', 'scope'];

    /**
     * @param  list<IndexedRule>  $rules  in ascending stylesheet position
     * @param  array<string, list<int>>  $offsets  class token to its rules' offsets in $rules
     * @param  list<int>  $unconditional  offsets in $rules of the rules every page must carry
     */
    private function __construct(
        private array $rules,
        private array $offsets,
        private array $unconditional,
    ) {}

    /**
     * @param  Closure(string): list<string>|null  $indexTokens  the tokens a rule is indexed under, given its selector list; the default reading when null
     */
    public static function build(string $utilitiesCss, ?Closure $indexTokens = null): self
    {
        $indexTokens ??= self::indexedTokens(...);

        /** @var list<IndexedRule> $rules */
        $rules = [];
        /** @var array<string, list<int>> $offsets */
        $offsets = [];
        /** @var list<int> $unconditional */
        $unconditional = [];
        /** @var list<array{prelude: string, start: int, open: int, pendingTokens: list<string>}> $stack */
        $stack = [];
        /** @var list<array{0: int, 1: int}> comment byte ranges [start, end) found anywhere in the scan */
        $comments = [];
        $segmentStart = 0;
        $length = strlen($utilitiesCss);

        for ($i = 0; $i < $length; $i++) {
            $char = $utilitiesCss[$i];

            if ($char === '\\') {
                // Skip the escaped byte so an escaped brace or quote (`content-['{']`) is never
                // mistaken for block structure.
                $i++;

                continue;
            }

            if ($char === '"' || $char === "'") {
                $i = CssScanner::skipString($utilitiesCss, $i, $length) - 1;

                continue;
            }

            if ($char === '/' && $i + 1 < $length && $utilitiesCss[$i + 1] === '*') {
                $end = CssScanner::skipComment($utilitiesCss, $i, $length);
                $comments[] = [$i, $end];
                $i = $end - 1;

                continue;
            }

            if ($char === '{') {
                $prelude = trim(self::withoutComments($utilitiesCss, $segmentStart, $i, $comments));
                $stack[] = ['prelude' => $prelude, 'start' => $segmentStart, 'open' => $i, 'pendingTokens' => []];
                $segmentStart = $i + 1;
            } elseif ($char === '}') {
                $frame = array_pop($stack);
                $segmentStart = $i + 1;

                if ($frame === null) {
                    continue;
                }

                $isAtRule = str_starts_with($frame['prelude'], '@');

                if ($isAtRule && self::isWrapperAtRule($frame['prelude'])) {
                    // The rules inside a wrapper are recorded on their own, with this prelude in
                    // their wrapper chain.
                    continue;
                }

                if (self::insideNonWrapperAtRule($stack)) {
                    // A keyframe step or similar: already part of the at-rule's body, which is
                    // recorded whole when it closes.
                    continue;
                }

                if ($isAtRule) {
                    $unconditional[] = count($rules);
                    $rules[] = new IndexedRule(
                        self::wrappers($stack),
                        $frame['prelude'],
                        substr($utilitiesCss, $frame['open'] + 1, $i - $frame['open'] - 1),
                        $frame['start'],
                    );

                    continue;
                }

                // A selector list with a class-less selector in it applies whatever the page's
                // classes are, so its tokens do not own it: it is carried unconditionally instead.
                $tokens = SelectorTokens::hasSelectorWithoutClass($frame['prelude'])
                    ? []
                    : array_values(array_unique([...$indexTokens($frame['prelude']), ...$frame['pendingTokens']]));
                $ancestor = self::nearestStyleRuleAncestor($stack);

                if ($ancestor !== null) {
                    // A nested style rule is already part of the enclosing rule's body. Its tokens
                    // bubble up to the nearest style-rule ancestor and are indexed when it closes.
                    $ancestorFrame = $stack[$ancestor];
                    $ancestorFrame['pendingTokens'] = [...$ancestorFrame['pendingTokens'], ...$tokens];
                    $stack[$ancestor] = $ancestorFrame;

                    continue;
                }

                $offset = count($rules);
                $rules[] = new IndexedRule(
                    self::wrappers($stack),
                    $frame['prelude'],
                    substr($utilitiesCss, $frame['open'] + 1, $i - $frame['open'] - 1),
                    $frame['start'],
                );

                if ($tokens === []) {
                    // Nothing about this rule says which pages need it, so every page gets it.
                    $unconditional[] = $offset;
                }

                foreach ($tokens as $token) {
                    $offsets[$token][] = $offset;
                }
            } elseif ($char === ';') {
                // A semicolon ends a statement or a declaration, so it never leaks into the next
                // prelude. A top-level statement is kept unconditionally and in place: a `@layer`
                // order statement decides the cascade for the layer blocks after it.
                if (self::nearestStyleRuleAncestor($stack) === null && ! self::insideNonWrapperAtRule($stack)) {
                    $text = self::withoutComments($utilitiesCss, $segmentStart, $i, $comments);
                    $statement = trim($text);

                    if (str_starts_with($statement, '@')) {
                        $unconditional[] = count($rules);
                        $rules[] = new IndexedRule(self::wrappers($stack), $statement, '', $segmentStart + (strlen($text) - strlen(ltrim($text))), true);
                    }
                }

                $segmentStart = $i + 1;
            }
        }

        return self::sortedByPosition($rules, $offsets, $unconditional);
    }

    /**
     * @return list<IndexedRule> the rules naming $token, in ascending stylesheet position
     */
    public function rulesFor(string $token): array
    {
        return array_map(fn (int $offset): IndexedRule => $this->rules[$offset], $this->offsets[$token] ?? []);
    }

    /**
     * The rules no class token can own, in ascending stylesheet position. Every page stylesheet
     * carries them.
     *
     * @return list<IndexedRule>
     */
    public function unconditional(): array
    {
        return array_map(fn (int $offset): IndexedRule => $this->rules[$offset], $this->unconditional);
    }

    public function count(): int
    {
        return count($this->rules);
    }

    /**
     * Every class token the layer names, in first-seen order. Every recorded rule is reachable from
     * one of these tokens or is in {@see self::unconditional()}.
     *
     * @return list<string>
     */
    public function tokens(): array
    {
        return array_map(strval(...), array_keys($this->offsets));
    }

    /**
     * The at-rule preludes enclosing the frame being closed, outermost first. A prelude that is not
     * an at-rule is CSS nesting, which contributes nothing a wrapper block has to reproduce.
     *
     * @param  list<array{prelude: string, start: int, open: int, pendingTokens: list<string>}>  $stack
     * @return list<string>
     */
    private static function wrappers(array $stack): array
    {
        $wrappers = [];

        foreach ($stack as $frame) {
            if (str_starts_with($frame['prelude'], '@')) {
                $wrappers[] = $frame['prelude'];
            }
        }

        return $wrappers;
    }

    /**
     * The default reading: the classes of a rule's subject compounds, or every class it mentions
     * when it names none outside a functional pseudo-class (`:where(.space-y-6>:not(:last-child))`).
     * Marker classes such as `group` or `dark` are not indexed: otherwise every page using them
     * would receive every variant rule, though a rule only matches an element with its utility class.
     *
     * @return list<string>
     */
    private static function indexedTokens(string $prelude): array
    {
        $subjects = SelectorTokens::subjects($prelude);

        return $subjects === [] ? SelectorTokens::tokens($prelude) : $subjects;
    }

    /**
     * Whether $prelude opens an at-rule that groups other rules, as opposed to one that holds
     * declarations of its own. An unrecognised at-rule counts as a non-wrapper, the fail-safe
     * reading: its block is emitted whole into every page rather than taken apart.
     */
    public static function isWrapperAtRule(string $prelude): bool
    {
        preg_match('~^@([\w-]+)~', $prelude, $matches);

        return in_array(strtolower($matches[1] ?? ''), self::WRAPPER_AT_RULES, true);
    }

    /**
     * Whether any frame still open is a non-wrapper at-rule, i.e. whether the frame just closed is
     * part of that at-rule's own body rather than a rule in its own right.
     *
     * @param  list<array{prelude: string, start: int, open: int, pendingTokens: list<string>}>  $stack
     */
    private static function insideNonWrapperAtRule(array $stack): bool
    {
        foreach ($stack as $frame) {
            if (str_starts_with($frame['prelude'], '@') && ! self::isWrapperAtRule($frame['prelude'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * The index in $stack of the nearest still-open style rule, skipping at-rule wrappers; null
     * when the frame that just closed is a top-level rule.
     *
     * @param  list<array{prelude: string, start: int, open: int, pendingTokens: list<string>}>  $stack
     */
    private static function nearestStyleRuleAncestor(array $stack): ?int
    {
        for ($index = count($stack) - 1; $index >= 0; $index--) {
            if (! str_starts_with($stack[$index]['prelude'], '@')) {
                return $index;
            }
        }

        return null;
    }

    /**
     * $rules sorted into ascending stylesheet position, with $offsets remapped to match. Rules are
     * recorded as they close, so a nested rule's order can disagree with position order.
     *
     * @param  list<IndexedRule>  $rules
     * @param  array<string, list<int>>  $offsets
     * @param  list<int>  $unconditional
     */
    private static function sortedByPosition(array $rules, array $offsets, array $unconditional): self
    {
        $order = array_keys($rules);

        usort($order, static fn (int $a, int $b): int => $rules[$a]->position <=> $rules[$b]->position);

        $translate = array_flip($order);
        $sortedRules = array_map(static fn (int $oldOffset): IndexedRule => $rules[$oldOffset], $order);

        foreach ($offsets as $token => $oldOffsets) {
            $offsets[$token] = array_map(static fn (int $oldOffset): int => $translate[$oldOffset], $oldOffsets);
        }

        $unconditional = array_map(static fn (int $oldOffset): int => $translate[$oldOffset], $unconditional);
        sort($unconditional);

        return new self($sortedRules, $offsets, $unconditional);
    }

    /**
     * The text of $css between $start and $end with any recorded comment ranges that fall
     * entirely inside it removed, so a comment never leaks into a computed prelude.
     *
     * @param  list<array{0: int, 1: int}>  $comments
     */
    private static function withoutComments(string $css, int $start, int $end, array $comments): string
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
}
