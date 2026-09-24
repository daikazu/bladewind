<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Pages;

/**
 * A compiled stylesheet separated into its root and the parts a page can be built from.
 *
 * `root` is every byte outside the extracted parts. The first top-level occurrence of each extracted
 * layer is collapsed to a statement that keeps its cascade position (`@layer utilities;`,
 * `@layer theme;`, `@layer properties;`), and later utilities occurrences are removed. `utilities` is
 * the inner CSS of every top-level utilities block, concatenated in stylesheet order.
 *
 * When `supportShaken` is true, the theme and properties layers and the top-level `@property` and
 * `@keyframes` rules have also been lifted out of `root`, so an emitter can put back only what a page
 * references. When it is false, only the utilities layer was extracted, every support field is empty,
 * and `unshakenReason` names the shape that prevented it (reported as BW6005).
 */
final readonly class SplitStylesheet
{
    public const UTILITIES_PRELUDE = '@layer utilities';

    public const THEME_PRELUDE = '@layer theme';

    public const PROPERTIES_PRELUDE = '@layer properties';

    public const UTILITIES_STATEMENT = self::UTILITIES_PRELUDE.';';

    public const THEME_STATEMENT = self::THEME_PRELUDE.';';

    public const PROPERTIES_STATEMENT = self::PROPERTIES_PRELUDE.';';

    /**
     * The statement text each `$statements` key stands for, so a caller holding an offset knows how
     * many bytes the statement there occupies.
     */
    public const STATEMENTS = [
        'properties' => self::PROPERTIES_STATEMENT,
        'theme' => self::THEME_STATEMENT,
        'utilities' => self::UTILITIES_STATEMENT,
    ];

    /**
     * @param  list<ThemeBlock>  $theme  the theme layer's blocks, in stylesheet order
     * @param  array<string, string>  $propertyRules  custom property name to its whole `@property --x{...}` text
     * @param  array<string, string>  $keyframes  keyframe name to its whole `@keyframes x{...}` text
     * @param  array<string, int>  $statements  byte offset into `root` of each statement the splitter wrote there, keyed by {@see self::STATEMENTS}
     * @param  string|null  $unshakenReason  the shape that stopped the support layers being taken apart (`nested rule in @layer theme`, `non-custom declaration in @layer properties`, `second top-level @layer theme`, ...), or null when they were
     */
    public function __construct(
        public string $root,
        public string $utilities,
        public bool $found,
        public array $theme = [],
        public ?PropertiesLayer $properties = null,
        public array $propertyRules = [],
        public array $keyframes = [],
        public bool $supportShaken = false,
        public array $statements = [],
        public ?string $unshakenReason = null,
    ) {}

    /**
     * `root` with every support part put back: the complete stylesheet minus its utilities layer.
     *
     * Used by tests as the round-trip baseline. The request path does not call it; a root file
     * carries only the support declarations its own content references ({@see SupportCssBuilder::root()}).
     */
    public function rootWithSupport(): string
    {
        if (! $this->supportShaken) {
            return $this->root;
        }

        // `@property` and `@keyframes` are position-independent, so they go back as a trailing run in
        // the order Tailwind emits them rather than at their original offsets.
        return $this->withStatements($this->supportExpansions()).$this->detachedRules();
    }

    /**
     * The whole stylesheet again: `rootWithSupport()` with the utilities layer expanded back in.
     *
     * Byte-identical to the input for Vite's minified output. Otherwise the result is semantically
     * identical but may differ in bytes:
     *
     * - insignificant whitespace in support declarations and trailing semicolons are dropped;
     * - `@property`/`@keyframes` rules come back as a trailing run;
     * - a name declared twice in one block keeps its last value, in the first occurrence's position;
     * - a conditional group holding several theme blocks is reopened around each one, since a block
     *   carries its wrapper chain rather than a position in the group;
     * - comments before or within a theme block's selector are dropped;
     * - an empty support block is dropped with its wrapper (`@layer theme{:root{}}` becomes
     *   `@layer theme{}`), the same rule {@see ThemeBlock::css()} and {@see PropertiesLayer::css()}
     *   follow.
     */
    public function reassemble(): string
    {
        $expansions = ['utilities' => self::UTILITIES_PRELUDE.'{'.$this->utilities.'}'];

        if (! $this->supportShaken) {
            return $this->withStatements($expansions);
        }

        return $this->withStatements($expansions + $this->supportExpansions()).$this->detachedRules();
    }

    /**
     * The `@property` registrations and `@keyframes` rules the split lifted out of `root`, in the
     * order the stylesheet declared them.
     */
    private function detachedRules(): string
    {
        return implode('', $this->propertyRules).implode('', $this->keyframes);
    }

    /**
     * The CSS that replaces the theme and properties statements when the support layers are put back
     * whole. A layer the stylesheet did not hold has no entry, so its statement stays a statement.
     *
     * @return array<string, string>
     */
    private function supportExpansions(): array
    {
        $expansions = [];

        if ($this->properties !== null) {
            $expansions['properties'] = self::PROPERTIES_PRELUDE.'{'.$this->properties->css().'}';
        }

        if ($this->theme !== []) {
            $blocks = implode('', array_map(static fn (ThemeBlock $block): string => $block->css(), $this->theme));
            $expansions['theme'] = self::THEME_PRELUDE.'{'.$blocks.'}';
        }

        return $expansions;
    }

    /**
     * `root` with each named statement replaced by the given CSS.
     *
     * Statements are located by the byte offset the splitter recorded, not by text search, because a
     * comment or author CSS can contain a literal `@layer theme;` earlier in the file. Replacements
     * run from the highest offset down so the remaining offsets stay valid. A statement with no
     * replacement stays as is, keeping the layer's cascade position ({@see SupportCssBuilder::root()}).
     *
     * @param  array<string, string>  $replacements  statement key ({@see self::STATEMENTS}) to the CSS that takes its place
     */
    public function withStatements(array $replacements): string
    {
        /** @var list<array{0: int, 1: string, 2: string}> $ordered */
        $ordered = [];

        foreach ($replacements as $key => $expansion) {
            if (isset($this->statements[$key], self::STATEMENTS[$key])) {
                $ordered[] = [$this->statements[$key], self::STATEMENTS[$key], $expansion];
            }
        }

        usort($ordered, static fn (array $a, array $b): int => $b[0] <=> $a[0]);

        $css = $this->root;

        foreach ($ordered as [$offset, $statement, $expansion]) {
            $css = substr_replace($css, $expansion, $offset, strlen($statement));
        }

        return $css;
    }
}
