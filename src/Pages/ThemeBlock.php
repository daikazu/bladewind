<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Pages;

/**
 * One `selector{--x:1;--y:2}` block of the theme layer: Tailwind emits `:root,:host` with every
 * theme variable, and a `@custom-variant` or a user `@theme` override adds further blocks such as
 * `.dark{--color-...}`. A block holding anything but custom properties makes the whole layer
 * unshakeable ({@see SplitStylesheet::$supportShaken}).
 *
 * `$wrappers` records any conditional groups around the block: Lightning CSS compiles a
 * `color-mix()` theme value into a static fallback plus the real value inside a
 * `@supports (color:color-mix(...))` block, and a page needs both. A subset is re-emitted inside
 * the same wrapper chain.
 */
final readonly class ThemeBlock
{
    /**
     * @param  string  $selector  the block's selector list, as the stylesheet wrote it, less any comment in it
     * @param  array<string, string>  $declarations  custom property name (leading `--` included) to value, in source order
     * @param  list<string>  $wrappers  the at-rule preludes between `@layer theme` and this block, outermost first
     */
    public function __construct(
        public string $selector,
        public array $declarations,
        public array $wrappers = [],
    ) {}

    /**
     * The block as CSS, wrappers and all, or `''` when the subset asked for is empty.
     *
     * Byte-identical to the source for minified output (what a Vite build produces). Whitespace
     * around `:` and `;` is not preserved, a trailing semicolon is dropped, and a name declared
     * twice comes back once, holding the value the cascade resolved it to.
     *
     * @param  array<string, string>|null  $declarations  a subset to emit instead of all of them
     */
    public function css(?array $declarations = null): string
    {
        return Declarations::block($this->wrappers, $this->selector, $declarations ?? $this->declarations);
    }
}
