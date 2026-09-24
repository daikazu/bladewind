<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Manifest;

use Daikazu\BladeWind\BladeWind;
use Daikazu\BladeWind\Diagnostics\Codes;
use Daikazu\BladeWind\Diagnostics\Diagnostic;
use Daikazu\BladeWind\Discovery\ViewKind;
use Daikazu\BladeWind\Graph\GraphSummary;
use Daikazu\BladeWind\Integration\CompatHash;
use DateTimeImmutable;

final class ReportBuilder
{
    public function __construct(private CompatHash $compat) {}

    /**
     * @param  list<string>  $paths
     * @param  list<ViewEntry>  $entries
     * @param  list<Diagnostic>  $reportDiagnostics
     */
    public function build(array $paths, array $entries, array $reportDiagnostics, ?GraphSummary $graph = null): Report
    {
        usort($entries, static fn (ViewEntry $a, ViewEntry $b): int => strcmp($a->relativePath, $b->relativePath));

        $dependencyTotals = array_fill_keys(array_map(static fn (DependencyType $t): string => $t->value, DependencyType::cases()), 0);
        $resolutionTotals = array_fill_keys(array_map(static fn (Resolution $r): string => $r->value, Resolution::cases()), 0);
        $diagnosticTotals = array_fill_keys(Codes::all(), 0);
        $views = 0;
        $components = 0;

        $tokens = [];
        $classes = ['tokens' => 0, 'static' => 0, 'enumerable' => 0, 'runtime' => 0, 'candidate' => 0, 'declared' => 0, 'safelisted' => 0, 'unresolved' => 0];
        $declared = [];
        $safelisted = [];

        foreach ($entries as $entry) {
            if ($entry->kind === ViewKind::Component) {
                $components++;
            } else {
                $views++;
            }

            foreach ($entry->dependencies as $dependency) {
                $dependencyTotals[$dependency->type->value]++;
                $resolutionTotals[$dependency->resolution->value]++;
            }

            foreach ($entry->diagnostics as $diagnostic) {
                $diagnosticTotals[$diagnostic->code] = ($diagnosticTotals[$diagnostic->code] ?? 0) + 1;
            }

            foreach ($entry->classes->index as $token => $row) {
                $tokens[$token] = true;
                $classes['static'] += $row->static;
                $classes['enumerable'] += $row->enumerable;
                $classes['runtime'] += $row->runtime;
                $classes['candidate'] += $row->candidate;

                if ($row->declared) {
                    $declared[$token] = true;
                }

                if ($row->safelisted) {
                    $safelisted[$token] = true;
                }
            }

            $classes['unresolved'] += count($entry->classes->unresolved);
        }

        $classes['tokens'] = count($tokens);
        $classes['declared'] = count($declared);
        $classes['safelisted'] = count($safelisted);

        foreach ($reportDiagnostics as $diagnostic) {
            $diagnosticTotals[$diagnostic->code] = ($diagnosticTotals[$diagnostic->code] ?? 0) + 1;
        }

        return new Report(
            generatedAt: new DateTimeImmutable,
            bladewindVersion: BladeWind::VERSION,
            compatHash: $this->compat->current(),
            paths: $paths,
            files: count($entries),
            views: $views,
            components: $components,
            dependencyTotals: $dependencyTotals,
            resolutionTotals: $resolutionTotals,
            graph: $graph ?? GraphSummary::empty(),
            classes: $classes,
            diagnosticTotals: $diagnosticTotals,
            reportDiagnostics: $reportDiagnostics,
            entries: $entries,
        );
    }
}
