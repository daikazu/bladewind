<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Discovery;

use Closure;
use Daikazu\BladeWind\Integration\PathFilter;
use Illuminate\Filesystem\Filesystem;

final class ViewLocator
{
    public const EXTENSION = '.blade.php';

    /**
     * The name given to a compiled Livewire view, whose only stable identity is the hash Livewire
     * derived from the component's source path (`Livewire\Compiler\CacheManager::getHash()`).
     */
    public const LIVEWIRE_COMPILED_PREFIX = 'livewire-compiled.';

    /**
     * @param  Closure(): list<string>  $anonymousComponentPaths
     * @param  Closure(): ?string  $livewireCompiledViewPath  Livewire's compiled views directory, or null when Livewire is absent
     */
    public function __construct(
        private Filesystem $files,
        private string $basePath,
        private PathFilter $filter,
        private Closure $anonymousComponentPaths,
        private Closure $livewireCompiledViewPath,
    ) {
        $this->basePath = realpath($basePath) ?: $basePath;
    }

    /**
     * @param  list<string>|null  $roots  Defaults to every configured root.
     * @return list<SourceFile>
     */
    public function locate(?array $roots = null): array
    {
        $roots = $roots === null ? $this->filter->roots() : (new PathFilter($roots))->roots();

        $found = [];

        foreach ($roots as $root) {
            foreach ($this->files->allFiles($root) as $file) {
                $path = $file->getRealPath();

                if ($path === false || ! str_ends_with($path, self::EXTENSION)) {
                    continue;
                }

                $found[] = $this->make($path, $root);
            }
        }

        usort($found, static fn (SourceFile $a, SourceFile $b): int => strcmp($a->relativePath, $b->relativePath));

        return $found;
    }

    public function describe(string $path): ?SourceFile
    {
        $real = realpath($path);

        if ($real === false || ! str_ends_with($real, self::EXTENSION)) {
            return null;
        }

        $root = $this->filter->rootFor($path);

        if ($root === null) {
            return $this->describeLivewireCompiled($path, $real);
        }

        // Published package views under a root's `vendor/` directory are analysed like any other.
        //
        // The entry is keyed by $path as asked, not its `realpath()`: Blade reports the finder's
        // path to the compile observer and the view composer, and a root behind a symlink (a
        // Docker bind mount, macOS's `/var`) would otherwise store and look up entries under
        // different paths, re-parsing every rendered view on every request. The real path decides
        // the root, the name and the kind.
        return $this->make($real, $root, $path);
    }

    /**
     * A Blade file Livewire compiled from a single-file or multi-file component: what a
     * `<livewire:x />` actually renders, and so the path everything downstream keys on. It lives
     * under `config('view.compiled')/livewire/views` rather than `bladewind.paths`, so it is
     * described here instead of being rejected. `ManifestStore::prune()` only deletes entries
     * under a configured root, so these entries survive an analyze run that never saw them.
     *
     * The entry keeps $path as Livewire wrote it rather than its `realpath()`, since that is the
     * string the compile observer and view composer see; the manifest entry and the lookup then
     * agree even where `config('view.compiled')` sits behind a symlink (`/var` on macOS).
     */
    private function describeLivewireCompiled(string $path, string $real): ?SourceFile
    {
        $directory = ($this->livewireCompiledViewPath)();
        $directory = $directory === null ? false : realpath($directory);

        if ($directory === false || ! str_starts_with($real, $directory.DIRECTORY_SEPARATOR)) {
            return null;
        }

        return new SourceFile(
            $path,
            basename($real),
            self::LIVEWIRE_COMPILED_PREFIX.basename($real, self::EXTENSION),
            ViewKind::LivewireCompiled,
        );
    }

    /**
     * @param  string  $real  the file's canonical path, under $root, which the name and the kind are read from
     * @param  string|null  $path  the path the entry is keyed by, when it differs from $real
     */
    private function make(string $real, string $root, ?string $path = null): SourceFile
    {
        $path ??= $real;
        $relativeToRoot = $this->relative($real, $root);
        $name = str_replace('/', '.', substr($relativeToRoot, 0, -strlen(self::EXTENSION)));

        return new SourceFile(
            $path,
            $this->relative($real, $this->basePath),
            $name,
            $this->kind($real, $relativeToRoot),
        );
    }

    private function kind(string $path, string $relativeToRoot): ViewKind
    {
        if (str_starts_with($relativeToRoot, 'components/')) {
            return ViewKind::Component;
        }

        foreach (($this->anonymousComponentPaths)() as $componentPath) {
            $real = realpath($componentPath);

            if ($real !== false && str_starts_with($path, $real.DIRECTORY_SEPARATOR)) {
                return ViewKind::Component;
            }
        }

        return ViewKind::View;
    }

    private function relative(string $path, string $base): string
    {
        $base = rtrim(str_replace('\\', '/', $base), '/');
        $path = str_replace('\\', '/', $path);

        if (str_starts_with($path, $base.'/')) {
            return substr($path, strlen($base) + 1);
        }

        return ltrim($path, '/');
    }
}
