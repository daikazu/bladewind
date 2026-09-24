<?php

declare(strict_types=1);

use Daikazu\BladeWind\Analysis\ViewAnalyzer;
use Daikazu\BladeWind\Discovery\ViewLocator;
use Daikazu\BladeWind\Integration\CompatHash;
use Daikazu\BladeWind\Parsing\Nodes\Template;
use Daikazu\BladeWind\Parsing\SourceParser;

it('produces a complete entry for a fixture view', function (): void {
    $path = $this->fixturePath('views/pages/home.blade.php');
    $file = app(ViewLocator::class)->describe($path);
    $entry = app(ViewAnalyzer::class)->analyze($file, file_get_contents($path));

    expect($entry->name)->toBe('pages.home')
        ->and($entry->kind->value)->toBe('view')
        ->and($entry->fingerprint->content)->toBe(hash('xxh128', file_get_contents($path)))
        ->and($entry->fingerprint->mtime)->toBe(filemtime($path))
        ->and($entry->compatHash)->toBe(app(CompatHash::class)->current())
        ->and($entry->dependencies)->toHaveCount(11)
        ->and(array_map(fn ($d) => $d->code, $entry->diagnostics))->toBe([]);
});

it('records a BW1008 diagnostic instead of throwing when parsing fails', function (): void {
    app()->instance(SourceParser::class, new class implements SourceParser
    {
        public function parse(string $source): Template
        {
            throw new RuntimeException('parser exploded');
        }
    });
    app()->forgetInstance(ViewAnalyzer::class);

    $path = $this->fixturePath('views/pages/home.blade.php');
    $entry = app(ViewAnalyzer::class)->analyze(app(ViewLocator::class)->describe($path), file_get_contents($path));

    expect($entry->dependencies)->toBe([])
        ->and($entry->diagnostics)->toHaveCount(1)
        ->and($entry->diagnostics[0]->code)->toBe('BW1008')
        ->and($entry->diagnostics[0]->message)->toContain('parser exploded');
});

it('surfaces parser error messages as BW1008 diagnostics', function (): void {
    app()->instance(SourceParser::class, new class implements SourceParser
    {
        public function parse(string $source): Template
        {
            return new Template([], ['unterminated tag']);
        }
    });
    app()->forgetInstance(ViewAnalyzer::class);

    $path = $this->fixturePath('views/pages/home.blade.php');
    $entry = app(ViewAnalyzer::class)->analyze(app(ViewLocator::class)->describe($path), file_get_contents($path));

    expect($entry->diagnostics[0]->code)->toBe('BW1008')
        ->and($entry->diagnostics[0]->message)->toContain('unterminated tag');
});

it('analyses a Livewire single-file component view like any other view', function (): void {
    $entry = analyzeFixture('views/components/⚡zap.blade.php');

    expect($entry->diagnostics)->toBe([])
        ->and($entry->classes->groups[0]->tokens)->toBe(['flex', 'items-center', 'gap-2', 'rounded-lg', 'border', 'p-4']);
});
