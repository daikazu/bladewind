<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Parsing\Nodes;

final readonly class ComponentTag extends Node
{
    /**
     * @param  list<Attribute>  $attributes
     * @param  list<Node>  $children
     */
    public function __construct(
        Position $position,
        public string $prefix,
        public string $name,
        public bool $isSlot,
        public array $attributes,
        public array $children,
    ) {
        parent::__construct($position);
    }

    public function children(): array
    {
        return $this->children;
    }

    public function attribute(string $name): ?Attribute
    {
        foreach ($this->attributes as $attribute) {
            if ($attribute->name === $name) {
                return $attribute;
            }
        }

        return null;
    }
}
