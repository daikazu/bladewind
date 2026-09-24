<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Graph;

final class DependencyGraph
{
    public const SCHEMA = 1;

    /**
     * @param  array<string, GraphNode>  $nodes
     * @param  list<list<string>>  $cycles
     * @param  array{static: int, declared: int}  $edgeTotals
     */
    public function __construct(
        private array $nodes,
        private array $cycles,
        private array $edgeTotals,
    ) {
        ksort($this->nodes, SORT_STRING);
    }

    public function node(string $id): ?GraphNode
    {
        return $this->nodes[$id] ?? null;
    }

    /**
     * @return array<string, GraphNode>
     */
    public function nodes(): array
    {
        return $this->nodes;
    }

    /**
     * @return list<string>
     */
    public function dependenciesOf(string $id, bool $transitive = false): array
    {
        return $this->walk($id, $transitive, static fn (GraphNode $node): array => $node->dependencies);
    }

    /**
     * @return list<string>
     */
    public function dependentsOf(string $id, bool $transitive = false): array
    {
        return $this->walk($id, $transitive, static fn (GraphNode $node): array => $node->dependents);
    }

    /**
     * @return list<list<string>>
     */
    public function cycles(): array
    {
        return $this->cycles;
    }

    public function summary(): GraphSummary
    {
        $unresolved = 0;
        $ranked = [];

        foreach ($this->nodes as $id => $node) {
            $unresolved += $node->unresolved;
            $ranked[] = ['id' => $id, 'dependents' => count($node->dependents)];
        }

        usort($ranked, static fn (array $a, array $b): int => [$b['dependents'], $a['id']] <=> [$a['dependents'], $b['id']]);

        return new GraphSummary(count($this->nodes), $this->edgeTotals, $unresolved, count($this->cycles), array_slice($ranked, 0, 10));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $nodes = [];

        foreach ($this->nodes as $id => $node) {
            $nodes[$id] = $node->toArray();
        }

        return [
            'schema' => self::SCHEMA,
            'edges' => $this->edgeTotals,
            'nodes' => $nodes === [] ? new \stdClass : $nodes,
            'cycles' => $this->cycles,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        /** @var array{edges: array{static: int, declared: int}, nodes: array<string, array{kind: string, path: string|null, name: string|null, dependencies: list<string>, dependents: list<string>, unresolved: int, declared: int}>, cycles: list<list<string>>} $data */
        $nodes = [];

        foreach ($data['nodes'] as $id => $node) {
            $nodes[(string) $id] = GraphNode::fromArray((string) $id, $node);
        }

        return new self($nodes, $data['cycles'], $data['edges']);
    }

    /**
     * @param  callable(GraphNode): list<string>  $neighbours
     * @return list<string>
     */
    private function walk(string $origin, bool $transitive, callable $neighbours): array
    {
        $start = $this->nodes[$origin] ?? null;

        if ($start === null) {
            return [];
        }

        if (! $transitive) {
            return $neighbours($start);
        }

        $seen = [$origin => true];
        $queue = $neighbours($start);
        $found = [];

        while ($queue !== []) {
            $id = array_shift($queue);

            if (isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;
            $found[] = $id;

            $node = $this->nodes[$id] ?? null;

            if ($node !== null) {
                $queue = [...$queue, ...$neighbours($node)];
            }
        }

        sort($found, SORT_STRING);

        return $found;
    }
}
