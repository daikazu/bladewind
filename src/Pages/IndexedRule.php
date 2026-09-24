<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Pages;

/**
 * One rule of the utilities layer as the index holds it: enough to emit the rule back verbatim,
 * and its byte position so a page's rules keep the layer's original order.
 *
 * A statement (`@layer a, b;`) is held the same way, with the text before the semicolon as its
 * selector and no body. It is unconditional and keeps its place, because a `@layer` order
 * statement decides the cascade for every layer block after it.
 */
final readonly class IndexedRule
{
    /**
     * @param  list<string>  $wrappers  enclosing at-rule preludes inside the utilities block, outermost first
     * @param  bool  $statement  whether this is an at-rule statement rather than a block, emitted as `selector;`
     */
    public function __construct(
        public array $wrappers,
        public string $selector,
        public string $body,
        public int $position,
        public bool $statement = false,
    ) {}

    /**
     * The rule's text, as it appeared in the layer.
     */
    public function css(): string
    {
        return $this->statement ? $this->selector.';' : $this->selector.'{'.$this->body.'}';
    }
}
