<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Graph;

use Daikazu\BladeWind\Discovery\ViewKind;
use Daikazu\BladeWind\Discovery\ViewLocator;
use Daikazu\BladeWind\Integration\CompatHash;
use Daikazu\BladeWind\Manifest\ManifestStore;
use Daikazu\BladeWind\Manifest\ViewEntry;
use DateTimeImmutable;
use Illuminate\Filesystem\Filesystem;
use Throwable;

/**
 * graph.json: a cache of the derived graph, valid while the entry set is unchanged.
 */
final class GraphStore
{
    public const FILE = 'graph.json';

    public function __construct(
        private Filesystem $files,
        private ManifestStore $entries,
        private CompatHash $compat,
        private GraphBuilder $builder,
    ) {}

    public function path(): string
    {
        return $this->entries->root().'/'.self::FILE;
    }

    /**
     * $entries without Livewire's compiled component views ({@see ViewLocator::describe()}).
     * Those are derived copies of a source view, named after a cache hash, so graphing them would
     * double-count the same markup under a name no developer wrote.
     *
     * @param  list<ViewEntry>  $entries
     * @return list<ViewEntry>
     */
    public static function sourceViews(array $entries): array
    {
        return array_values(array_filter($entries, static fn (ViewEntry $entry): bool => $entry->kind !== ViewKind::LivewireCompiled));
    }

    /**
     * Hash of "<entry file>:<size>:xxh128(contents)" lines from a directory listing, without
     * parsing any JSON. Content rather than mtime, because a same-second rewrite can leave mtime
     * unchanged at whole-second resolution and serve a stale cached graph.
     */
    public function fingerprint(): string
    {
        $directory = $this->entries->root().'/views';
        $lines = [];

        if ($this->files->isDirectory($directory)) {
            foreach ($this->files->files($directory) as $file) {
                if ($file->getExtension() === 'json') {
                    $lines[] = $file->getFilename().':'.$file->getSize().':'.hash('xxh128', (string) $this->files->get($file->getPathname()));
                }
            }
        }

        sort($lines, SORT_STRING);

        return hash('xxh128', implode("\n", $lines));
    }

    public function get(): ?DependencyGraph
    {
        $path = $this->path();

        if (! $this->files->isFile($path)) {
            return null;
        }

        try {
            /** @var array<string, mixed> $data */
            $data = json_decode($this->files->get($path), true, 512, JSON_THROW_ON_ERROR);

            if (($data['schema'] ?? null) !== DependencyGraph::SCHEMA
                || ($data['compat_hash'] ?? null) !== $this->compat->current()
                || ($data['entries_fingerprint'] ?? null) !== $this->fingerprint()) {
                return null;
            }

            return DependencyGraph::fromArray($data);
        } catch (Throwable) {
            return null;
        }
    }

    public function refresh(): DependencyGraph
    {
        // Fingerprint before reading the entries, so a write landing in between makes the next
        // get() see the graph as stale.
        $fingerprint = $this->fingerprint();
        $graph = $this->builder->build(self::sourceViews($this->entries->all()));

        $payload = [
            'schema' => DependencyGraph::SCHEMA,
            'generated_at' => (new DateTimeImmutable)->format(DATE_ATOM),
            'compat_hash' => $this->compat->current(),
            'entries_fingerprint' => $fingerprint,
            ...array_diff_key($graph->toArray(), ['schema' => true]),
        ];

        $this->entries->writeAtomically($this->path(), json_encode($payload, ViewEntry::JSON_FLAGS)."\n");

        return $graph;
    }

    public function graph(): DependencyGraph
    {
        return $this->get() ?? $this->refresh();
    }
}
