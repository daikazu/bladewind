<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Manifest;

use Daikazu\BladeWind\Integration\CompatHash;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;
use Throwable;

final class ManifestStore
{
    public const REPORT_FILE = 'manifest.json';

    /**
     * @var array<string, array{0: array{0: int, 1: int}, 1: ?ViewEntry}> get()'s memo per entry file: the [filemtime, filesize] it was read at, and the ViewEntry (or null) it resolved to
     */
    private array $memo = [];

    public function __construct(
        private Filesystem $files,
        private string $root,
        private CompatHash $compat,
    ) {}

    public function root(): string
    {
        return $this->root;
    }

    public function entryPath(string $sourcePath): string
    {
        return $this->root.'/views/'.hash('xxh128', $sourcePath).'.json';
    }

    public function put(ViewEntry $entry): void
    {
        $path = $this->entryPath($entry->path);
        $this->writeAtomically($path, $entry->toJson());

        $key = $this->stat($path);

        if ($key === null) {
            unset($this->memo[$path]);

            return;
        }

        $this->memo[$path] = [$key, $entry];
    }

    /**
     * The entry for $sourcePath, memoised per process and keyed on the entry file's
     * [filemtime, filesize], so a long-running worker (Octane, a queue) picks up an entry written
     * by a separate `bladewind:analyze` process without restarting.
     */
    public function get(string $sourcePath): ?ViewEntry
    {
        $path = $this->entryPath($sourcePath);
        $key = $this->stat($path);

        if ($key === null) {
            unset($this->memo[$path]);

            return null;
        }

        if (isset($this->memo[$path]) && $this->memo[$path][0] === $key) {
            return $this->memo[$path][1];
        }

        $entry = $this->read($path);
        $this->memo[$path] = [$key, $entry];

        return $entry;
    }

    public function forget(string $sourcePath): void
    {
        $path = $this->entryPath($sourcePath);
        $this->files->delete($path);
        unset($this->memo[$path]);
    }

    /**
     * @return list<ViewEntry>
     */
    public function all(): array
    {
        $directory = $this->root.'/views';

        if (! $this->files->isDirectory($directory)) {
            return [];
        }

        $entries = [];

        foreach ($this->files->files($directory) as $file) {
            if ($file->getExtension() !== 'json') {
                continue;
            }

            $entry = $this->read($file->getPathname());

            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        usort($entries, static fn (ViewEntry $a, ViewEntry $b): int => strcmp($a->relativePath, $b->relativePath));

        return $entries;
    }

    public function putReport(Report $report): void
    {
        $this->writeAtomically($this->root.'/'.self::REPORT_FILE, $report->toJson());
    }

    public function clear(): bool
    {
        if (! $this->files->isDirectory($this->root)) {
            return false;
        }

        return $this->files->deleteDirectory($this->root);
    }

    private function read(string $file): ?ViewEntry
    {
        if (! $this->files->isFile($file)) {
            return null;
        }

        try {
            /** @var array<string, mixed> $data */
            $data = json_decode($this->files->get($file), true, 512, JSON_THROW_ON_ERROR);

            if (($data['schema'] ?? null) !== ViewEntry::SCHEMA || ($data['compat_hash'] ?? null) !== $this->compat->current()) {
                return null;
            }

            return ViewEntry::fromArray($data);
        } catch (Throwable) {
            return null;
        }
    }

    public function writeAtomically(string $target, string $contents): void
    {
        $this->files->ensureDirectoryExists(dirname($target));

        $temporary = $target.'.'.bin2hex(random_bytes(6)).'.tmp';

        $this->files->put($temporary, $contents);

        if (! @rename($temporary, $target)) {
            $this->files->delete($temporary);

            throw new RuntimeException("Unable to write manifest file [{$target}].");
        }
    }

    /**
     * Delete entries whose source path is under one of $roots but not in $keep, and entries
     * (under any root or none) whose source file no longer exists.
     *
     * @param  list<string>  $roots
     * @param  list<string>  $keep
     * @return list<string> deleted source paths, sorted
     */
    public function prune(array $roots, array $keep): array
    {
        $directory = $this->root.'/views';

        if (! $this->files->isDirectory($directory)) {
            return [];
        }

        $keepSet = array_fill_keys($keep, true);
        $deleted = [];

        foreach ($this->files->files($directory) as $file) {
            if ($file->getExtension() !== 'json') {
                continue;
            }

            try {
                /** @var array<string, mixed> $data */
                $data = json_decode($this->files->get($file->getPathname()), true, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                continue;
            }

            $path = $data['path'] ?? null;

            if (! is_string($path) || isset($keepSet[$path])) {
                continue;
            }

            // An entry whose source is gone is stale wherever it lived: a deleted view, or the
            // compiled file Livewire regenerates for a single-file component after every edit.
            if (! $this->underAny($path, $roots) && $this->files->isFile($path)) {
                continue;
            }

            $this->files->delete($file->getPathname());
            $deleted[] = $path;
        }

        sort($deleted, SORT_STRING);

        return $deleted;
    }

    /**
     * @param  list<string>  $roots
     */
    private function underAny(string $path, array $roots): bool
    {
        foreach ($roots as $root) {
            if (str_starts_with($path, rtrim($root, '/').'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{0: int, 1: int}|null [filemtime, filesize], or null when the file is absent
     */
    private function stat(string $path): ?array
    {
        clearstatcache(true, $path);

        if (! $this->files->isFile($path)) {
            return null;
        }

        $mtime = filemtime($path);
        $size = filesize($path);

        if ($mtime === false || $size === false) {
            return null;
        }

        return [$mtime, $size];
    }
}
