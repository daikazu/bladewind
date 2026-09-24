<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Pages;

/**
 * The contents of `@layer properties`: every `--tw-*` custom property's default value, in one
 * selector block inside the at-rules Tailwind wraps the layer in (`@supports (...)` on a real
 * build, none on a hand-written stylesheet).
 */
final readonly class PropertiesLayer
{
    /**
     * @param  list<string>  $wrappers  the at-rule preludes between `@layer properties` and the selector, outermost first
     * @param  string  $selector  the declaration block's selector list, exactly as the stylesheet wrote it
     * @param  array<string, string>  $declarations  custom property name (leading `--` included) to value, in source order
     */
    public function __construct(
        public array $wrappers,
        public string $selector,
        public array $declarations,
    ) {}

    /**
     * The layer's contents as CSS: the wrappers reopened around the selector block, without the
     * `@layer properties{}` itself. Empty when the subset asked for is empty, so no empty
     * `@supports` wrapper is emitted.
     *
     * Byte-identical to the source for minified output; a trailing semicolon is dropped and a name
     * the block declared twice comes back once, holding the value the cascade resolved it to.
     *
     * @param  array<string, string>|null  $declarations  a subset to emit instead of all of them
     */
    public function css(?array $declarations = null): string
    {
        return Declarations::block($this->wrappers, $this->selector, $declarations ?? $this->declarations);
    }
}
