<?php

declare(strict_types=1);

use Daikazu\BladeWind\Graph\DependencyGraph;
use Daikazu\BladeWind\Graph\GraphNode;

function graphOf(array $edges, array $cycles = []): DependencyGraph
{
    $nodes = [];
    foreach ($edges as $from => $to) {
        $nodes[$from] ??= ['deps' => [], 'dependents' => []];
        foreach ($to as $target) {
            $nodes[$target] ??= ['deps' => [], 'dependents' => []];
            $nodes[$from]['deps'][] = $target;
            $nodes[$target]['dependents'][] = $from;
        }
    }
    $built = [];
    foreach ($nodes as $id => $lists) {
        sort($lists['deps']);
        sort($lists['dependents']);
        $built[$id] = new GraphNode($id, GraphNode::KIND_VIEW, $id, null, $lists['deps'], $lists['dependents'], 0, 0);
    }

    return new DependencyGraph($built, $cycles, ['static' => 3, 'declared' => 0]);
}

it('answers direct and transitive queries with cycles handled', function (): void {
    $graph = graphOf(['/page' => ['/layout', '/card'], '/card' => ['/button'], '/a' => ['/b'], '/b' => ['/a']], [['/a', '/b']]);

    expect($graph->dependenciesOf('/page'))->toBe(['/card', '/layout'])
        ->and($graph->dependenciesOf('/page', true))->toBe(['/button', '/card', '/layout'])
        ->and($graph->dependentsOf('/button', true))->toBe(['/card', '/page'])
        ->and($graph->dependenciesOf('/a', true))->toBe(['/b'])
        ->and($graph->dependentsOf('/missing'))->toBe([])
        ->and($graph->cycles())->toBe([['/a', '/b']]);
});

it('encodes an empty graph as an empty object, not an empty list', function (): void {
    $graph = new DependencyGraph([], [], ['static' => 0, 'declared' => 0]);

    expect(json_encode($graph->toArray(), JSON_THROW_ON_ERROR))->toContain('"nodes":{}');
});

it('summarises and round-trips through arrays', function (): void {
    $graph = graphOf(['/page' => ['/layout', '/card'], '/other' => ['/card']]);

    $summary = $graph->summary()->toArray();

    expect(array_keys($summary))->toBe(['nodes', 'edges', 'unresolved', 'cycles', 'most_depended'])
        ->and($summary['nodes'])->toBe(4)
        ->and($summary['most_depended'][0])->toBe(['id' => '/card', 'dependents' => 2])
        ->and(DependencyGraph::fromArray($graph->toArray())->toArray())->toBe($graph->toArray())
        ->and(array_keys($graph->toArray()))->toBe(['schema', 'edges', 'nodes', 'cycles']);
});
