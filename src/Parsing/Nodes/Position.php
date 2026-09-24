<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Parsing\Nodes;

final readonly class Position
{
    public function __construct(
        public int $startLine,
        public int $startColumn,
        public int $endLine,
        public int $endColumn,
        public int $startOffset,
        public int $endOffset,
    ) {}
}
