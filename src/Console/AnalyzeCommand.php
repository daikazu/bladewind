<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Console;

use Daikazu\BladeWind\Analysis\ViewAnalyzer;
use Daikazu\BladeWind\Declarations\DeclarationDiagnostics;
use Daikazu\BladeWind\Diagnostics\Codes;
use Daikazu\BladeWind\Diagnostics\Severity;
use Daikazu\BladeWind\Discovery\ViewLocator;
use Daikazu\BladeWind\Graph\GraphStore;
use Daikazu\BladeWind\Manifest\ManifestStore;
use Daikazu\BladeWind\Manifest\Report;
use Daikazu\BladeWind\Manifest\ReportBuilder;
use Daikazu\BladeWind\Manifest\ViewEntry;
use Daikazu\BladeWind\Stylesheets\StylesheetIndex;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

final class AnalyzeCommand extends Command
{
    protected $signature = 'bladewind:analyze
        {--json : Print the report as JSON instead of a summary}
        {--path=* : Only scan these directories}';

    protected $description = 'Scan Blade views and write the BladeWind manifest without changing compiled output.';

    public function handle(
        Repository $config,
        Filesystem $files,
        ViewLocator $locator,
        ViewAnalyzer $analyzer,
        ManifestStore $store,
        ReportBuilder $builder,
        GraphStore $graphs,
        DeclarationDiagnostics $declarations,
        StylesheetIndex $stylesheets,
    ): int {
        /** @var list<string> $requested */
        $requested = $this->option('path') !== [] ? $this->option('path') : $config->get('bladewind.paths', []);

        $reportDiagnostics = [];
        $paths = [];

        foreach ($requested as $path) {
            $real = realpath($path);

            if ($real === false || ! is_dir($real)) {
                $reportDiagnostics[] = Codes::make(Codes::PATH_SKIPPED, ['detail' => "Configured path [{$path}] does not exist and was skipped."]);

                continue;
            }

            $paths[] = $real;
        }

        $entries = [];

        foreach ($locator->locate($paths) as $file) {
            try {
                $entry = $analyzer->analyze($file, $files->get($file->path));
                $store->put($entry);
                $entries[] = $entry;
            } catch (Throwable $exception) {
                $reportDiagnostics[] = Codes::make(Codes::ANALYSIS_FAILED, ['message' => "{$file->relativePath}: {$exception->getMessage()}"]);
            }
        }

        $pruned = $store->prune($paths, array_map(static fn (ViewEntry $entry): string => $entry->path, $entries));
        $graph = $graphs->refresh();

        foreach ($graph->cycles() as $cycle) {
            $reportDiagnostics[] = Codes::make(Codes::DEPENDENCY_CYCLE, ['cycle' => implode(' -> ', [...$cycle, $cycle[0]])]);
        }

        $reportDiagnostics = [...$reportDiagnostics, ...$declarations->report()];

        $reportDiagnostics = [...$reportDiagnostics, ...$stylesheets->diagnostics()];

        $report = $builder->build($paths, $entries, $reportDiagnostics, $graph->summary());
        $store->putReport($report);

        if ((bool) $this->option('json')) {
            // Raw and straight to the underlying output: Symfony's formatter slices a message with a
            // grapheme-aware substring at every `<`, which is quadratic on a multi-megabyte report.
            $this->output->getOutput()->write($report->toJson(), false, OutputInterface::OUTPUT_RAW);

            return self::SUCCESS;
        }

        $this->summary($report, $pruned);

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $pruned
     */
    private function summary(Report $report, array $pruned): void
    {
        $this->components->info('BladeWind analysis');

        $this->components->twoColumnDetail('Files scanned', (string) $report->files);
        $this->components->twoColumnDetail('Views', (string) $report->views);
        $this->components->twoColumnDetail('Components', (string) $report->components);

        foreach ($report->dependencyTotals as $type => $count) {
            $this->components->twoColumnDetail("Edges: {$type}", (string) $count);
        }

        foreach ($report->resolutionTotals as $resolution => $count) {
            $this->components->twoColumnDetail("Resolution: {$resolution}", (string) $count);
        }

        $this->components->twoColumnDetail('Graph nodes', (string) $report->graph->nodes);
        $this->components->twoColumnDetail('Graph edges: static', (string) $report->graph->edges['static']);
        $this->components->twoColumnDetail('Graph edges: declared', (string) $report->graph->edges['declared']);
        $this->components->twoColumnDetail('Graph unresolved edges', (string) $report->graph->unresolved);
        $this->components->twoColumnDetail('Graph cycles', (string) $report->graph->cycles);

        foreach ($report->classes as $key => $count) {
            $this->components->twoColumnDetail("Classes: {$key}", (string) $count);
        }

        foreach ($this->laravel->make(StylesheetIndex::class)->stylesheets() as $stylesheet) {
            $this->components->twoColumnDetail("Stylesheet {$stylesheet->name}", $stylesheet->found ? 'found' : 'missing');
        }

        foreach ($report->diagnosticTotals as $code => $count) {
            $this->components->twoColumnDetail("Diagnostics: {$code}", (string) $count);
        }

        foreach ($report->reportDiagnostics as $diagnostic) {
            $this->line("  {$diagnostic->code} {$diagnostic->message}");
        }

        foreach ($report->entries as $entry) {
            $this->entryDiagnostics($entry);
        }

        $this->newLine();
        $this->components->twoColumnDetail('Pruned entries', (string) count($pruned));
        $this->components->info("Manifest written to {$this->laravel->make(ManifestStore::class)->root()}");
    }

    private function entryDiagnostics(ViewEntry $entry): void
    {
        foreach ($entry->diagnostics as $diagnostic) {
            if ($diagnostic->severity === Severity::Info) {
                continue;
            }

            $this->line(sprintf('  %s:%s:%s %s %s', $entry->relativePath, $diagnostic->line ?? 0, $diagnostic->column ?? 0, $diagnostic->code, $diagnostic->message));
        }
    }
}
