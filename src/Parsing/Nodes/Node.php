<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Parsing\Nodes;

abstract readonly class Node
{
    public function __construct(public Position $position) {}

    /**
     * @return list<Node>
     */
    public function children(): array
    {
        return [];
    }
}
