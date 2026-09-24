<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Graph;

use Daikazu\BladeWind\Discovery\ViewKind;
use Daikazu\BladeWind\Manifest\DependencyType;
use Daikazu\BladeWind\Manifest\Resolution;
use Daikazu\BladeWind\Manifest\ViewEntry;

/**
 * Derives the dependency graph from per-view entries.
 */
final class GraphBuilder
{
    /**
     * @var array<string, array{kind: string, path: string|null, name: string|null, dependencies: array<string, true>, dependents: array<string, true>, unresolved: int, declared: int}>
     */
    private array $nodes = [];

    /**
     * @var array{static: int, declared: int}
     */
    private array $edgeTotals = ['static' => 0, 'declared' => 0];

    /**
     * @param  list<ViewEntry>  $entries
     */
    public function build(array $entries): DependencyGraph
    {
        $this->nodes = [];
        $this->edgeTotals = ['static' => 0, 'declared' => 0];

        $live = array_values(array_filter($entries, static fn (ViewEntry $entry): bool => is_file($entry->path)));

        foreach ($live as $entry) {
            $kind = $entry->kind === ViewKind::Component ? GraphNode::KIND_COMPONENT : GraphNode::KIND_VIEW;
            $this->ensure($entry->path, $kind, $entry->path, $entry->name, true);
        }

        foreach ($live as $entry) {
            foreach ($entry->dependencies as $dependency) {
                if ($dependency->resolution === Resolution::Unresolved) {
                    $this->nodes[$entry->path]['unresolved']++;

                    continue;
                }

                if ($dependency->resolution === Resolution::Declared) {
                    $this->nodes[$entry->path]['declared']++;
                }

                $target = $this->targetId($dependency->type, $dependency->target, $dependency->resolvedPath, $dependency->resolvedClass);

                if ($target === null) {
                    continue;
                }

                $this->edge($entry->path, $target, $dependency->resolution);
            }
        }

        $nodes = [];

        foreach ($this->nodes as $id => $node) {
            $dependencies = array_keys($node['dependencies']);
            $dependents = array_keys($node['dependents']);
            sort($dependencies, SORT_STRING);
            sort($dependents, SORT_STRING);

            $nodes[$id] = new GraphNode($id, $node['kind'], $node['path'], $node['name'], $dependencies, $dependents, $node['unresolved'], $node['declared']);
        }

        return new DependencyGraph($nodes, $this->cycles($nodes), $this->edgeTotals);
    }

    private function ensure(string $id, string $kind, ?string $path, ?string $name, bool $overwrite = false): void
    {
        if (isset($this->nodes[$id]) && ! $overwrite) {
            return;
        }

        $existing = $this->nodes[$id] ?? null;

        $this->nodes[$id] = [
            'kind' => $kind,
            'path' => $path,
            'name' => $name,
            'dependencies' => $existing['dependencies'] ?? [],
            'dependents' => $existing['dependents'] ?? [],
            'unresolved' => $existing['unresolved'] ?? 0,
            'declared' => $existing['declared'] ?? 0,
        ];
    }

    private function targetId(DependencyType $type, ?string $target, ?string $path, ?string $class): ?string
    {
        if ($path !== null) {
            $this->ensure($path, GraphNode::KIND_VIEW, $path, null);

            return $path;
        }

        if ($class !== null) {
            $id = 'class:'.$class;
            $this->ensure($id, GraphNode::KIND_CLASS_COMPONENT, null, $class);

            return $id;
        }

        if ($type === DependencyType::Livewire && $target !== null) {
            $id = 'livewire:'.$target;
            $this->ensure($id, GraphNode::KIND_LIVEWIRE, null, $target);

            return $id;
        }

        return null;
    }

    private function edge(string $from, string $to, Resolution $resolution): void
    {
        $source = $this->nodes[$from];
        $isNewEdge = ! isset($source['dependencies'][$to]);
        $source['dependencies'][$to] = true;
        $this->nodes[$from] = $source;

        $target = $this->nodes[$to];
        $target['dependents'][$from] = true;
        $this->nodes[$to] = $target;

        if ($isNewEdge) {
            $this->edgeTotals[$resolution === Resolution::Declared ? 'declared' : 'static']++;
        }
    }

    /**
     * Tarjan's strongly connected components over file nodes; each cycle is listed once,
     * as its member ids sorted so the lexicographically smallest comes first.
     *
     * @param  array<string, GraphNode>  $nodes
     * @return list<list<string>>
     */
    private function cycles(array $nodes): array
    {
        $index = 0;
        $indices = [];
        $low = [];
        $onStack = [];
        $stack = [];
        $cycles = [];

        $strong = function (string $id) use (&$strong, &$index, &$indices, &$low, &$onStack, &$stack, &$cycles, $nodes): void {
            $indices[$id] = $index;
            $low[$id] = $index;
            $index++;
            $stack[] = $id;
            $onStack[$id] = true;

            foreach ($nodes[$id]->dependencies as $next) {
                if (! isset($nodes[$next]) || $nodes[$next]->path === null) {
                    continue;
                }

                if (! isset($indices[$next])) {
                    $strong($next);
                    $low[$id] = min($low[$id], $low[$next]);
                } elseif ($onStack[$next] ?? false) {
                    $low[$id] = min($low[$id], $indices[$next]);
                }
            }

            if ($low[$id] === $indices[$id]) {
                $component = [];

                do {
                    $member = array_pop($stack);

                    if ($member === null) {
                        break;
                    }

                    $onStack[$member] = false;
                    $component[] = $member;
                } while ($member !== $id);

                $selfLoop = count($component) === 1 && in_array($id, $nodes[$id]->dependencies, true);

                if (count($component) > 1) {
                    sort($component, SORT_STRING);
                    $cycles[] = $this->tracePath($component[0], $component, $nodes);
                } elseif ($selfLoop) {
                    $cycles[] = $component;
                }
            }
        };

        foreach ($nodes as $id => $node) {
            if ($node->path !== null && ! isset($indices[$id])) {
                $strong($id);
            }
        }

        usort($cycles, static fn (array $a, array $b): int => strcmp($a[0], $b[0]));

        return $cycles;
    }

    /**
     * A real cycle through $start within the component's members, following the smallest
     * unvisited neighbour at each step and backtracking on dead ends. Members of a strongly
     * connected component are mutually reachable, so the member-list fallback is never reached.
     *
     * @param  list<string>  $members
     * @param  array<string, GraphNode>  $nodes
     * @return list<string>
     */
    private function tracePath(string $start, array $members, array $nodes): array
    {
        $membership = array_flip($members);
        $visited = [$start => true];
        $path = [$start];

        if ($this->walkCycle($start, $start, $membership, $nodes, $visited, $path)) {
            return $path;
        }

        return $members;
    }

    /**
     * @param  array<string, int>  $membership
     * @param  array<string, GraphNode>  $nodes
     * @param  array<string, true>  $visited
     * @param  list<string>  $path
     */
    private function walkCycle(string $start, string $current, array $membership, array $nodes, array &$visited, array &$path): bool
    {
        $neighbours = array_values(array_filter(
            $nodes[$current]->dependencies,
            static fn (string $next): bool => isset($membership[$next]),
        ));
        sort($neighbours, SORT_STRING);

        foreach ($neighbours as $next) {
            if ($next === $start) {
                return true;
            }

            if (isset($visited[$next])) {
                continue;
            }

            $visited[$next] = true;
            $path[] = $next;

            if ($this->walkCycle($start, $next, $membership, $nodes, $visited, $path)) {
                return true;
            }

            array_pop($path);
            unset($visited[$next]);
        }

        return false;
    }
}
