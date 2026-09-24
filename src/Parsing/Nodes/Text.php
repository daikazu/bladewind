<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Parsing\Nodes;

final readonly class Text extends Node
{
    public function __construct(Position $position, public string $content)
    {
        parent::__construct($position);
    }
}
