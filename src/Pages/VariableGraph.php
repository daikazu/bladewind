<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Pages;

/**
 * Which custom properties a stylesheet's support layers declare, and what else has to travel with
 * each of them.
 *
 * Only the values are kept, one per place the name is declared: a name declared in `:root,:host`
 * and again in `.dark` has two, and every `--tw-*` has two (the `@layer properties` default and the
 * `@property` `initial-value`). Emitters subset {@see ThemeBlock} and {@see PropertiesLayer}
 * directly, so where each value came from is not recorded here.
 *
 * Built once per stylesheet from the parts {@see StylesheetSplitter} lifted out, it answers which
 * declared names a piece of CSS reaches, transitively ({@see self::closure()}).
 *
 * Everything here errs towards including a name: an unused variable costs a few dozen bytes, while
 * a missing one changes how the page looks.
 */
final readonly class VariableGraph
{
    /**
     * `var(` (case-insensitive, any whitespace) then the name it reads. A fallback's own reference
     * in `var(--a, var(--b))` is found by the same pass.
     *
     * The name also stops at structural bytes, so an unclosed `var(--x` reads as `--x` rather than
     * swallowing the rest of the block.
     */
    private const REFERENCE = '~var\(\s*(--[^\s,;{})]+)~i';

    /**
     * A declaration that can name a keyframe: the `animation` shorthand or `animation-name` (with any
     * vendor prefix), or any custom property, since Tailwind reaches its keyframes through theme
     * values such as `--animate-spin:spin 1s linear infinite`. The lookbehind stops a longer
     * identifier, class or id from matching.
     */
    private const KEYFRAME_SITE = '~(?<![\w.#-])(?:--[\w-]+|(?:-[a-z]+-)?animation(?:-name)?)\s*:\s*([^;{}]*)~i';

    /**
     * The `initial-value` a `@property` registration declares, which is that name's value everywhere
     * the property has not been set.
     */
    private const INITIAL_VALUE = '~(?<![\w-])initial-value\s*:\s*([^;}]*)~i';

    /**
     * @param  array<string, list<string>>  $declarations  variable name to the value of every place it is declared, in stylesheet order
     */
    public function __construct(
        public array $declarations,
    ) {}

    /**
     * The graph for a split stylesheet. A split whose support layers could not be taken apart
     * ({@see SplitStylesheet::$supportShaken}) declares nothing, since its support stays whole in
     * the root.
     */
    public static function fromSplit(SplitStylesheet $split): self
    {
        /** @var array<string, list<string>> $declarations */
        $declarations = [];

        foreach ($split->theme as $block) {
            foreach ($block->declarations as $name => $value) {
                $declarations[$name][] = $value;
            }
        }

        if ($split->properties !== null) {
            foreach ($split->properties->declarations as $name => $value) {
                $declarations[$name][] = $value;
            }
        }

        foreach ($split->propertyRules as $name => $rule) {
            $declarations[$name][] = self::initialValue($rule);
        }

        return new self($declarations);
    }

    /**
     * Every name the support layers declare, once each, in stylesheet order.
     *
     * @return list<string>
     */
    public function declared(): array
    {
        return array_keys($this->declarations);
    }

    public function has(string $name): bool
    {
        return isset($this->declarations[$name]);
    }

    /**
     * Every variable $css reads, unique and in the order it first reads them. A name read only inside
     * a `var()` fallback (`var(--a, var(--b))`) counts as read: the browser uses it whenever the
     * first name resolves to nothing.
     *
     * @return list<string>
     */
    public static function refs(string $css): array
    {
        if ($css === '' || stripos($css, 'var(') === false) {
            return [];
        }

        preg_match_all(self::REFERENCE, $css, $matches);

        /** @var array<string, true> $seen */
        $seen = [];
        /** @var list<string> $names */
        $names = [];

        foreach ($matches[1] as $name) {
            if (isset($seen[$name])) {
                continue;
            }

            $seen[$name] = true;
            $names[] = $name;
        }

        return $names;
    }

    /**
     * The declared names $seeds reaches, transitively through their values. Sorted, and limited to
     * names the support layers declare: a name defined elsewhere (a page's inline CSS, say) has
     * nothing to emit here.
     *
     * @param  list<string>  $seeds
     * @return list<string>
     */
    public function closure(array $seeds): array
    {
        /** @var array<string, true> $resolved */
        $resolved = [];
        $queue = $seeds;

        while ($queue !== []) {
            $name = array_pop($queue);

            if (isset($resolved[$name]) || ! isset($this->declarations[$name])) {
                continue;
            }

            $resolved[$name] = true;

            foreach ($this->declarations[$name] as $value) {
                foreach (self::refs($value) as $reference) {
                    $queue[] = $reference;
                }
            }
        }

        $names = array_keys($resolved);
        sort($names, SORT_STRING);

        return $names;
    }

    /**
     * Which of $availableNames $css animates, in the order they were given.
     *
     * A keyframe is named as a whole word in an `animation` or `animation-name` value, or in a theme
     * value a utility reaches through `var()` (`--animate-ping:ping 1s`). Callers therefore pass the
     * variable values their file carries alongside the CSS, written as declarations.
     *
     * A name is never read out of a selector (`.spin`) or the middle of a longer identifier
     * (`--animate-spin`).
     *
     * Test-facing: the request path does not route keyframes per page (the root carries them all),
     * but tests use this to check every keyframe either file names is defined.
     *
     * @param  list<string>  $availableNames  the keyframe names the stylesheet defines
     * @return list<string>
     */
    public static function keyframesReferenced(string $css, array $availableNames): array
    {
        if ($css === '' || $availableNames === []) {
            return [];
        }

        preg_match_all(self::KEYFRAME_SITE, $css, $matches);

        if ($matches[1] === []) {
            return [];
        }

        // One space between values so a name can never be read across the join of two of them.
        $values = implode(' ', $matches[1]);
        /** @var list<string> $referenced */
        $referenced = [];

        foreach ($availableNames as $name) {
            if ($name !== '' && preg_match('~(?<![\w-])'.preg_quote($name, '~').'(?![\w-])~', $values) === 1) {
                $referenced[] = $name;
            }
        }

        return $referenced;
    }

    /**
     * The declared names $patterns selects, sorted. A pattern is either an exact name
     * (`--color-brand`) or a prefix ending in `*` (`--color-*`); a pattern that selects nothing
     * contributes nothing, so a configured name the stylesheet dropped is not an error.
     *
     * Every pattern has to start with `--`, so a bare `*` in `keep_variables` selects nothing rather
     * than putting every declared variable into every page file.
     *
     * @param  list<string>  $patterns
     * @return list<string>
     */
    public function match(array $patterns): array
    {
        /** @var array<string, true> $selected */
        $selected = [];

        foreach ($patterns as $pattern) {
            if (! str_starts_with($pattern, '--')) {
                continue;
            }

            if (! str_ends_with($pattern, '*')) {
                if (isset($this->declarations[$pattern])) {
                    $selected[$pattern] = true;
                }

                continue;
            }

            $prefix = substr($pattern, 0, -1);

            foreach ($this->declared() as $name) {
                if (str_starts_with($name, $prefix)) {
                    $selected[$name] = true;
                }
            }
        }

        $names = array_keys($selected);
        sort($names, SORT_STRING);

        return $names;
    }

    /**
     * The `initial-value` declared by a whole `@property --x{...}` rule, or `''` when it declares
     * none (the property is then guaranteed-invalid and reads nothing).
     */
    private static function initialValue(string $rule): string
    {
        return preg_match(self::INITIAL_VALUE, $rule, $matches) === 1 ? trim($matches[1]) : '';
    }
}
