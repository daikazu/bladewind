<?php

declare(strict_types=1);

use Daikazu\BladeWind\Analysis\ViewAnalyzer;
use Daikazu\BladeWind\Discovery\ViewKind;
use Daikazu\BladeWind\Discovery\ViewLocator;
use Daikazu\BladeWind\Graph\GraphBuilder;
use Daikazu\BladeWind\Graph\GraphNode;
use Daikazu\BladeWind\Manifest\ClassesBlock;
use Daikazu\BladeWind\Manifest\Dependency;
use Daikazu\BladeWind\Manifest\DependencyType;
use Daikazu\BladeWind\Manifest\Fingerprint;
use Daikazu\BladeWind\Manifest\Resolution;
use Daikazu\BladeWind\Manifest\ViewEntry;

function entriesFor(array $relatives): array
{
    return array_map(function (string $relative) {
        $path = test()->fixturePath($relative);

        return app(ViewAnalyzer::class)->analyze(app(ViewLocator::class)->describe($path), file_get_contents($path));
    }, $relatives);
}

/**
 * A ViewEntry whose only dependency is a static, resolved edge to $target. $path must exist on
 * disk (GraphBuilder ignores entries whose file is gone); the fixture's actual content is
 * irrelevant since dependencies are supplied directly.
 */
function cycleEntry(string $path, string $target): ViewEntry
{
    return new ViewEntry(
        path: $path,
        relativePath: basename($path),
        name: basename($path, '.blade.php'),
        kind: ViewKind::View,
        fingerprint: new Fingerprint('deadbeef', 10, 0),
        compatHash: 'hash',
        dependencies: [new Dependency(DependencyType::Include, null, Resolution::Static, $target, null, 1, 1)],
        classes: ClassesBlock::empty(),
        diagnostics: [],
    );
}

it('builds nodes for files, class components, and livewire components with reverse dependents', function (): void {
    $graph = app(GraphBuilder::class)->build(entriesFor(['views/pages/home.blade.php', 'views/pages/about.blade.php', 'views/components/card.blade.php']));

    $home = $this->fixturePath('views/pages/home.blade.php');
    $card = $this->fixturePath('views/components/card.blade.php');
    $footer = $this->fixturePath('views/partials/footer.blade.php');

    expect($graph->node($card)?->kind)->toBe(GraphNode::KIND_COMPONENT)
        ->and($graph->node($card)?->dependents)->toBe([$home])
        ->and($graph->node($footer)?->kind)->toBe(GraphNode::KIND_VIEW)
        ->and($graph->node($footer)?->name)->toBeNull()
        ->and($graph->node('class:App\View\Components\Alert')?->kind)->toBe(GraphNode::KIND_CLASS_COMPONENT)
        ->and($graph->node('livewire:counter')?->dependents)->toBe([$this->fixturePath('views/pages/about.blade.php')])
        ->and($graph->node($this->fixturePath('views/pages/about.blade.php'))?->unresolved)->toBe(4)
        ->and($graph->summary()->edges['static'])->toBeGreaterThan(10);
});

it('detects include cycles and ignores entries whose file is gone', function (): void {
    $entries = entriesFor(['views/cycle/a.blade.php', 'views/cycle/b.blade.php']);
    $ghost = analyzeFixture('views/simple.blade.php');
    $ghost = new ViewEntry(
        path: '/definitely/missing.blade.php', relativePath: 'missing.blade.php', name: 'missing', kind: $ghost->kind,
        fingerprint: $ghost->fingerprint, compatHash: $ghost->compatHash, dependencies: [], classes: $ghost->classes, diagnostics: [],
    );

    $graph = app(GraphBuilder::class)->build([...$entries, $ghost]);

    expect($graph->cycles())->toBe([[$this->fixturePath('views/cycle/a.blade.php'), $this->fixturePath('views/cycle/b.blade.php')]])
        ->and($graph->node('/definitely/missing.blade.php'))->toBeNull()
        ->and($graph->summary()->cycles)->toBe(1);
});

it('traces a real cycle through a 3+ node component whose sorted member order is not a path', function (): void {
    $paths = [
        $this->fixturePath('views/simple.blade.php'),
        $this->fixturePath('views/partials/item.blade.php'),
        $this->fixturePath('views/partials/empty.blade.php'),
    ];
    sort($paths, SORT_STRING);
    [$a, $b, $c] = $paths;

    // a -> c, c -> b, b -> a: the sorted member order [a, b, c] is not itself a path.
    $graph = app(GraphBuilder::class)->build([
        cycleEntry($a, $c),
        cycleEntry($b, $a),
        cycleEntry($c, $b),
    ]);

    expect($graph->cycles())->toBe([[$a, $c, $b]]);
});
