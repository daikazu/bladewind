<?php

declare(strict_types=1);

use Daikazu\BladeWind\Analysis\ViewAnalyzer;
use Daikazu\BladeWind\Declarations\ComponentDeclarations;
use Daikazu\BladeWind\Declarations\DeclarationDiagnostics;
use Daikazu\BladeWind\Declarations\Safelist;
use Daikazu\BladeWind\Graph\GraphStore;
use Daikazu\BladeWind\Integration\CompatHash;
use Daikazu\BladeWind\Manifest\ManifestStore;
use Daikazu\BladeWind\Manifest\ReportBuilder;
use Daikazu\BladeWind\Stylesheets\StylesheetIndex;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

function reportWithoutTimestamp(string $json): array
{
    $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    unset($data['generated_at']);

    return $data;
}

it('scans configured paths, writes entries and the report, and prints a summary', function (): void {
    $this->artisan('bladewind:analyze')
        ->expectsOutputToContain('Files scanned')
        ->expectsOutputToContain('BW1002')
        ->assertSuccessful();

    $store = app(ManifestStore::class);

    expect($store->all())->not->toBe([])
        ->and(file_exists($store->root().'/manifest.json'))->toBeTrue();

    $report = reportWithoutTimestamp((string) file_get_contents($store->root().'/manifest.json'));

    expect($report['totals']['files'])->toBe(count($store->all()))
        ->and($report['paths'])->toBe([realpath($this->fixturePath('views')), realpath($this->fixturePath('ui'))]);
});

it('prints only JSON with --json', function (): void {
    expect(Artisan::call('bladewind:analyze', ['--json' => true]))->toBe(0);

    $output = trim(Artisan::output());

    expect($output)->toStartWith('{')
        ->and(reportWithoutTimestamp($output)['schema'])->toBe(4);
});

it('is deterministic across consecutive runs', function (): void {
    $store = app(ManifestStore::class);

    $this->artisan('bladewind:analyze')->assertSuccessful();
    $first = collect((new Filesystem)->files($store->root().'/views'))->mapWithKeys(fn ($f) => [$f->getFilename() => $f->getContents()])->all();
    $firstReport = reportWithoutTimestamp((string) file_get_contents($store->root().'/manifest.json'));

    $this->artisan('bladewind:analyze')->assertSuccessful();
    $second = collect((new Filesystem)->files($store->root().'/views'))->mapWithKeys(fn ($f) => [$f->getFilename() => $f->getContents()])->all();
    $secondReport = reportWithoutTimestamp((string) file_get_contents($store->root().'/manifest.json'));

    expect($second)->toBe($first)->and($secondReport)->toBe($firstReport);
});

it('restricts the scan to --path and reports missing paths', function (): void {
    $this->artisan('bladewind:analyze', ['--path' => [$this->fixturePath('ui'), '/definitely/missing']])
        ->expectsOutputToContain('BW1006')
        ->assertSuccessful();

    $entries = app(ManifestStore::class)->all();

    expect($entries)->toHaveCount(1)->and($entries[0]->name)->toBe('badge');
});

it('runs even when bladewind is disabled', function (): void {
    config()->set('bladewind.enabled', false);

    $this->artisan('bladewind:analyze')->assertSuccessful();

    expect(app(ManifestStore::class)->all())->not->toBe([]);
});

it('continues scanning and records BW1008 when a file cannot be read', function (): void {
    if (function_exists('posix_getuid') && posix_getuid() === 0) {
        $this->markTestSkipped('File permissions have no effect when running as root.');
    }

    $files = new Filesystem;
    $scratch = $this->fixturePath('views/unreadable-'.bin2hex(random_bytes(3)).'.blade.php');
    $files->put($scratch, '<p>x</p>');
    chmod($scratch, 0000);

    try {
        $this->artisan('bladewind:analyze')->assertSuccessful();

        $report = reportWithoutTimestamp((string) file_get_contents(app(ManifestStore::class)->root().'/manifest.json'));

        expect(array_column($report['report_diagnostics'], 'code'))->toContain('BW1008');
    } finally {
        chmod($scratch, 0644);
        $files->delete($scratch);
    }
});

it('writes graph and classes blocks, reports cycles, and refreshes graph.json', function (): void {
    $this->artisan('bladewind:analyze')->expectsOutputToContain('Graph nodes')->assertSuccessful();

    $store = app(ManifestStore::class);
    $report = reportWithoutTimestamp((string) file_get_contents($store->root().'/manifest.json'));

    expect($report['graph']['cycles'])->toBe(1)
        ->and($report['diagnostics']['BW2005'])->toBe(1)
        ->and($report['report_diagnostics'][0]['message'])->toContain('cycle/a.blade.php')
        ->and($report['classes']['static'])->toBeGreaterThan(20)
        ->and($report['classes']['runtime'])->toBeGreaterThan(3)
        ->and(file_exists($store->root().'/graph.json'))->toBeTrue()
        ->and(app(GraphStore::class)->get())->not->toBeNull();

    $firstGraph = reportWithoutTimestamp((string) file_get_contents($store->root().'/graph.json'));
    $this->artisan('bladewind:analyze')->assertSuccessful();
    $secondGraph = reportWithoutTimestamp((string) file_get_contents($store->root().'/graph.json'));

    expect($secondGraph)->toBe($firstGraph);
});

it('prunes entries for deleted files and drops them from the graph', function (): void {
    $scratch = $this->fixturePath('views/scratch-prune-'.bin2hex(random_bytes(3)).'.blade.php');
    file_put_contents($scratch, '<p class="x">gone</p>');

    try {
        $this->artisan('bladewind:analyze')->assertSuccessful();
        expect(app(ManifestStore::class)->get($scratch))->not->toBeNull();
    } finally {
        unlink($scratch);
    }

    $this->artisan('bladewind:analyze')->expectsOutputToContain('Pruned')->assertSuccessful();

    expect(app(ManifestStore::class)->get($scratch))->toBeNull()
        ->and(app(GraphStore::class)->graph()->node($scratch))->toBeNull();
});

it('surfaces declaration diagnostics in the report', function (): void {
    config()->set('bladewind.safelist', ['a b']);
    config()->set('bladewind.components', ['nope' => ['classes' => ['x']]]);
    foreach ([Safelist::class, ComponentDeclarations::class, DeclarationDiagnostics::class, CompatHash::class, ManifestStore::class, GraphStore::class, ViewAnalyzer::class] as $abstract) {
        app()->forgetInstance($abstract);
    }

    $this->artisan('bladewind:analyze')->expectsOutputToContain('BW2006')->expectsOutputToContain('BW2007')->assertSuccessful();

    $report = reportWithoutTimestamp((string) file_get_contents(app(ManifestStore::class)->root().'/manifest.json'));

    expect(array_column($report['report_diagnostics'], 'code'))->toContain('BW2006', 'BW2007');
});

it('reports the configured stylesheet as found and leaves optimization out of the report', function (): void {
    $this->artisan('bladewind:analyze')
        ->expectsOutputToContain('Stylesheet resources/css/app.css')
        ->assertSuccessful();

    $report = reportWithoutTimestamp((string) file_get_contents(app(ManifestStore::class)->root().'/manifest.json'));

    expect($report)->not->toHaveKey('optimization')
        ->and($report['diagnostics']['BW3001'])->toBe(0);
});

it('reports BW3001 when a configured stylesheet is missing', function (): void {
    config()->set('bladewind.stylesheets', ['resources/css/missing.css']);
    foreach ([StylesheetIndex::class, CompatHash::class, ManifestStore::class, GraphStore::class, ReportBuilder::class, ViewAnalyzer::class] as $abstract) {
        app()->forgetInstance($abstract);
    }

    $this->artisan('bladewind:analyze')
        ->expectsOutputToContain('BW3001')
        ->expectsOutputToContain('Stylesheet resources/css/missing.css')
        ->assertSuccessful();

    $report = reportWithoutTimestamp((string) file_get_contents(app(ManifestStore::class)->root().'/manifest.json'));

    expect(array_column($report['report_diagnostics'], 'code'))->toContain('BW3001')
        ->and($report['diagnostics']['BW3001'])->toBe(1);
});

it('writes the JSON report raw so console formatters never scan it', function (): void {
    $spy = new class extends BufferedOutput
    {
        /** @var list<int> */
        public array $types = [];

        public function write(string|iterable $messages, bool $newline = false, int $options = self::OUTPUT_NORMAL): void
        {
            $this->types[] = $options & (self::OUTPUT_NORMAL | self::OUTPUT_RAW | self::OUTPUT_PLAIN);
            parent::write($messages, $newline, $options);
        }
    };

    expect(Artisan::call('bladewind:analyze', ['--json' => true], $spy))->toBe(0)
        ->and($spy->types)->toBe([OutputInterface::OUTPUT_RAW])
        ->and(trim($spy->fetch()))->toStartWith('{');
});
