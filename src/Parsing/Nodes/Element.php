<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Parsing\Nodes;

final readonly class Element extends Node
{
    /**
     * @param  list<Attribute>  $attributes
     * @param  list<Node>  $children
     */
    public function __construct(
        Position $position,
        public string $tagName,
        public array $attributes,
        public array $children,
    ) {
        parent::__construct($position);
    }

    public function children(): array
    {
        return $this->children;
    }
}
