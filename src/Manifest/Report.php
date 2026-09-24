<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Manifest;

use Daikazu\BladeWind\Diagnostics\Diagnostic;
use Daikazu\BladeWind\Graph\GraphSummary;
use DateTimeImmutable;

final readonly class Report
{
    public const SCHEMA = 4;

    /**
     * @param  list<string>  $paths
     * @param  array<string, int>  $dependencyTotals
     * @param  array<string, int>  $resolutionTotals
     * @param  array{tokens: int, static: int, enumerable: int, runtime: int, candidate: int, declared: int, safelisted: int, unresolved: int}  $classes
     * @param  array<string, int>  $diagnosticTotals
     * @param  list<Diagnostic>  $reportDiagnostics
     * @param  list<ViewEntry>  $entries
     */
    public function __construct(
        public DateTimeImmutable $generatedAt,
        public string $bladewindVersion,
        public string $compatHash,
        public array $paths,
        public int $files,
        public int $views,
        public int $components,
        public array $dependencyTotals,
        public array $resolutionTotals,
        public GraphSummary $graph,
        public array $classes,
        public array $diagnosticTotals,
        public array $reportDiagnostics,
        public array $entries,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'schema' => self::SCHEMA,
            'generated_at' => $this->generatedAt->format(DATE_ATOM),
            'bladewind_version' => $this->bladewindVersion,
            'compat_hash' => $this->compatHash,
            'paths' => $this->paths,
            'totals' => [
                'files' => $this->files,
                'views' => $this->views,
                'components' => $this->components,
                'dependencies' => $this->dependencyTotals,
                'resolution' => $this->resolutionTotals,
            ],
            'graph' => $this->graph->toArray(),
            'classes' => $this->classes,
            'diagnostics' => $this->diagnosticTotals,
            'report_diagnostics' => array_map(static fn (Diagnostic $d): array => $d->toArray(), $this->reportDiagnostics),
            'entries' => array_map(static fn (ViewEntry $e): array => $e->toArray(), $this->entries),
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), ViewEntry::JSON_FLAGS)."\n";
    }
}
