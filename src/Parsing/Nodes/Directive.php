<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Parsing\Nodes;

final readonly class Directive extends Node
{
    /**
     * @param  list<Node>  $children
     */
    public function __construct(
        Position $position,
        public string $name,
        public ?string $arguments,
        public DirectiveRole $role,
        public array $children,
    ) {
        parent::__construct($position);
    }

    public function children(): array
    {
        return $this->children;
    }
}
