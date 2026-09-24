<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Graph;

final readonly class GraphSummary
{
    /**
     * @param  array{static: int, declared: int}  $edges
     * @param  list<array{id: string, dependents: int}>  $mostDepended
     */
    public function __construct(
        public int $nodes,
        public array $edges,
        public int $unresolved,
        public int $cycles,
        public array $mostDepended,
    ) {}

    /**
     * @return array{nodes: int, edges: array{static: int, declared: int}, unresolved: int, cycles: int, most_depended: list<array{id: string, dependents: int}>}
     */
    public function toArray(): array
    {
        return [
            'nodes' => $this->nodes,
            'edges' => $this->edges,
            'unresolved' => $this->unresolved,
            'cycles' => $this->cycles,
            'most_depended' => $this->mostDepended,
        ];
    }

    public static function empty(): self
    {
        return new self(0, ['static' => 0, 'declared' => 0], 0, 0, []);
    }
}
