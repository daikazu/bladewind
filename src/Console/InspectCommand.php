<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Console;

use Daikazu\BladeWind\Analysis\ViewAnalyzer;
use Daikazu\BladeWind\Classes\ConditionalTokens;
use Daikazu\BladeWind\Discovery\SourceFile;
use Daikazu\BladeWind\Discovery\ViewLocator;
use Daikazu\BladeWind\Graph\DependencyGraph;
use Daikazu\BladeWind\Graph\GraphStore;
use Daikazu\BladeWind\Manifest\ManifestStore;
use Daikazu\BladeWind\Manifest\ViewEntry;
use Daikazu\BladeWind\Resolution\ComponentResolver;
use Daikazu\BladeWind\Resolution\ResolutionKind;
use Daikazu\BladeWind\Resolution\ViewResolver;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Console\Output\OutputInterface;

final class InspectCommand extends Command
{
    protected $signature = 'bladewind:inspect
        {target : A component name, a logical view name, or a file path}
        {--json : Print the entry and its graph neighbourhood as JSON}';

    protected $description = 'Explain what BladeWind knows about one view.';

    public function handle(
        Repository $config,
        Filesystem $files,
        ViewLocator $locator,
        ComponentResolver $components,
        ViewResolver $views,
        ViewAnalyzer $analyzer,
        ManifestStore $store,
        GraphStore $graphs,
    ): int {
        /** @var string $target */
        $target = $this->argument('target');
        $path = $this->resolve($target, $components, $views);

        if ($path === null) {
            return self::FAILURE;
        }

        $file = $locator->describe($path);

        if ($file === null) {
            /** @var list<string> $paths */
            $paths = $config->get('bladewind.paths', []);
            $this->components->error("[{$path}] is outside the configured paths: ".implode(', ', $paths));

            return self::FAILURE;
        }

        $entry = $this->freshEntry($file, $files, $analyzer, $store);
        $graph = $graphs->graph();

        if ((bool) $this->option('json')) {
            $this->output->getOutput()->write(json_encode([
                'entry' => $entry->toArray(),
                'graph' => [
                    'dependencies' => $graph->dependenciesOf($entry->path),
                    'dependents' => $graph->dependentsOf($entry->path),
                    'transitive_dependencies' => count($graph->dependenciesOf($entry->path, true)),
                    'transitive_dependents' => count($graph->dependentsOf($entry->path, true)),
                ],
            ], ViewEntry::JSON_FLAGS)."\n", false, OutputInterface::OUTPUT_RAW);

            return self::SUCCESS;
        }

        $this->render($entry, $graph);

        return self::SUCCESS;
    }

    private function resolve(string $target, ComponentResolver $components, ViewResolver $views): ?string
    {
        foreach ([$target, $this->laravel->basePath($target)] as $candidate) {
            if (is_file($candidate)) {
                return realpath($candidate) ?: $candidate;
            }
        }

        $resolved = $components->resolve($target);

        if ($resolved->kind === ResolutionKind::View && $resolved->path !== null) {
            return $resolved->path;
        }

        if ($resolved->kind === ResolutionKind::ClassComponent) {
            $this->components->error("[{$target}] is the class component {$resolved->class}; class components have no statically known view.");

            return null;
        }

        $path = $views->path($target);

        if ($path === null) {
            $this->components->error("[{$target}] is not a file, a component, or a view.");
        }

        return $path;
    }

    private function freshEntry(SourceFile $file, Filesystem $files, ViewAnalyzer $analyzer, ManifestStore $store): ViewEntry
    {
        $source = $files->get($file->path);
        $entry = $store->get($file->path);

        if ($entry !== null && $entry->fingerprint->content === hash('xxh128', $source)) {
            return $entry;
        }

        $entry = $analyzer->analyze($file, $source);
        $store->put($entry);

        return $entry;
    }

    private function render(ViewEntry $entry, DependencyGraph $graph): void
    {
        $this->components->info("{$entry->name} ({$entry->kind->value})");
        $this->components->twoColumnDetail('Path', $entry->relativePath);
        $this->components->twoColumnDetail('Fingerprint', $entry->fingerprint->content);
        $this->components->twoColumnDetail('Compat hash', $entry->compatHash);
        $this->components->twoColumnDetail('Page tokens', (string) count($entry->classes->index));

        $this->section('Dependencies');
        foreach ($entry->dependencies as $dependency) {
            $this->line(sprintf('  %s %s [%s] %s', $dependency->type->value, $dependency->target ?? '-', $dependency->resolution->value, $dependency->resolvedPath ?? $dependency->resolvedClass ?? '-'));
        }

        $this->section('Dependents');
        $dependents = $graph->dependentsOf($entry->path);

        if ($dependents === []) {
            $this->line('  (none yet; dependents appear once the other views that reference this one have been analysed)');
        } else {
            foreach ($dependents as $id) {
                $this->line('  '.$id);
            }
        }

        $this->line('  transitive: '.count($graph->dependentsOf($entry->path, true)));

        $this->section('Class groups');
        foreach ($entry->classes->groups as $group) {
            $this->line(sprintf('  %d:%d %s <%s> %s %s%s', $group->line, $group->column, $group->on, $group->tag, $group->source, $group->classification->value, $group->bag ? ' bag' : ''));
            $this->tokens($group->tokens, $group->conditional, $group->unresolved);
        }

        $this->section('Runtime classes');
        foreach ($entry->classes->runtime as $runtime) {
            $this->line(sprintf('  %d:%d %s %s %s%s', $runtime->line, $runtime->column, $runtime->adapter, $runtime->attribute, $runtime->classification->value, $runtime->remove ? ' remove' : ''));
            $this->tokens($runtime->tokens, $runtime->conditional, $runtime->unresolved);
        }

        $this->section('Candidates');
        foreach ($entry->classes->candidates as $candidates) {
            $this->line(sprintf('  %d:%d %s: %s', $candidates->line, $candidates->column, $candidates->origin, implode(' ', $candidates->tokens)));
        }

        $this->section('Unresolved');
        foreach ($entry->classes->unresolved as $unresolved) {
            $this->line(sprintf('  %d:%d %s: %s', $unresolved->line, $unresolved->column, $unresolved->kind, $unresolved->expression));
        }

        $this->section('Diagnostics');
        foreach ($entry->diagnostics as $diagnostic) {
            $this->line(sprintf('  %s:%s %s %s', $diagnostic->line ?? 0, $diagnostic->column ?? 0, $diagnostic->code, $diagnostic->message));
        }
    }

    private function section(string $title): void
    {
        $this->newLine();
        $this->line("<comment>{$title}</comment>");
    }

    /**
     * @param  list<string>  $tokens
     * @param  list<ConditionalTokens>  $conditional
     * @param  list<string>  $unresolved
     */
    private function tokens(array $tokens, array $conditional, array $unresolved): void
    {
        if ($tokens !== []) {
            $this->line('      '.implode(' ', $tokens));
        }

        foreach ($conditional as $item) {
            $this->line('      ['.implode(' ', $item->tokens).'] when '.$item->condition);
        }

        foreach ($unresolved as $expression) {
            $this->line('      unresolved: '.$expression);
        }
    }
}
