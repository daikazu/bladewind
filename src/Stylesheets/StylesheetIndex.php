<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Stylesheets;

use Daikazu\BladeWind\Diagnostics\Codes;
use Daikazu\BladeWind\Diagnostics\Diagnostic;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use JsonException;
use Throwable;

/**
 * Resolves the configured Vite stylesheet entries through the build manifest and holds their contents.
 */
final class StylesheetIndex
{
    /**
     * @var list<Stylesheet>|null
     */
    private ?array $stylesheets = null;

    /**
     * @var array<string, string>
     */
    private array $contents = [];

    /**
     * @var list<Diagnostic>
     */
    private array $diagnostics = [];

    /**
     * @var list<string> every path the last resolve() looked at, found or not
     */
    private array $resolvedPaths = [];

    /**
     * @var array<string, array{0: int, 1: int}|null>|null [filemtime, filesize] of the manifest and each resolved path when the memo was built
     */
    private ?array $statKey = null;

    /**
     * @param  list<string>  $names
     * @param  string  $buildDirectory  Vite's build directory under the public path (`bladewind.build_directory`);
     *                                  configured explicitly because Laravel has no getter for `Vite::useBuildDirectory()`
     */
    public function __construct(
        private array $names,
        private string $publicPath,
        private Filesystem $files,
        private string $buildDirectory = 'build',
    ) {
        $this->buildDirectory = trim($buildDirectory, '/') ?: 'build';
    }

    public static function fromConfig(Repository $config, string $publicPath, Filesystem $files): self
    {
        $configured = $config->get('bladewind.stylesheets', []);
        $names = [];

        if (is_array($configured)) {
            foreach ($configured as $name) {
                if (is_string($name) && $name !== '') {
                    $names[] = $name;
                }
            }
        }

        $build = $config->get('bladewind.build_directory', 'build');

        return new self(array_values(array_unique($names)), rtrim($publicPath, '/'), $files, is_string($build) ? $build : 'build');
    }

    /**
     * @return list<Stylesheet>
     */
    public function stylesheets(): array
    {
        $this->resolve();

        return $this->stylesheets ?? [];
    }

    public function hasStylesheet(): bool
    {
        foreach ($this->stylesheets() as $stylesheet) {
            if ($stylesheet->found) {
                return true;
            }
        }

        return false;
    }

    /**
     * The hash of the first stylesheet that resolved, or an empty string when none did.
     */
    public function hash(): string
    {
        foreach ($this->stylesheets() as $stylesheet) {
            if ($stylesheet->found && $stylesheet->hash !== null) {
                return $stylesheet->hash;
            }
        }

        return '';
    }

    /**
     * @return list<Diagnostic>
     */
    public function diagnostics(): array
    {
        $this->resolve();

        return $this->diagnostics;
    }

    /**
     * @return array<string, string> stylesheet name => contents, found stylesheets only
     */
    public function contents(): array
    {
        $this->resolve();

        return $this->contents;
    }

    /**
     * Memoised per process, keyed on the [filemtime, filesize] of the manifest and every stylesheet
     * it named, so a long-running worker (Octane, a queue) picks up a Vite rebuild without a
     * restart. A stale memo would pin {@see self::hash()} to the old stylesheet and make the
     * runtime staleness check refuse permanently.
     */
    private function resolve(): void
    {
        $manifestPath = $this->publicPath.'/'.$this->buildDirectory.'/manifest.json';

        if ($this->stylesheets !== null && $this->statKey === $this->currentStatKey($manifestPath)) {
            return;
        }

        $this->stylesheets = [];
        $this->contents = [];
        $this->diagnostics = [];
        $this->resolvedPaths = [];
        $manifest = $this->readManifest($manifestPath);

        foreach ($this->names as $name) {
            $entry = $manifest[$name] ?? null;
            $relative = null;

            if (is_array($entry) && isset($entry['file']) && is_string($entry['file'])) {
                $relative = $entry['file'];
            }

            if ($relative !== null && ! self::isCss($relative)) {
                // A script entry (`resources/js/app.js`) resolves to a `.js` file even when it
                // imports CSS; linking that as a stylesheet is worse than linking nothing.
                $this->diagnostics[] = Codes::make(Codes::STYLESHEET_NOT_CSS, ['name' => $name, 'file' => $relative]);
                $this->stylesheets[] = new Stylesheet($name, $relative, null, 0, false);

                continue;
            }

            $path = $relative === null ? null : $this->publicPath.'/'.$this->buildDirectory.'/'.$relative;

            if ($path !== null) {
                $this->resolvedPaths[] = $path;
            }

            if ($path === null || ! $this->files->isFile($path)) {
                $this->diagnostics[] = Codes::make(Codes::STYLESHEET_NOT_FOUND, ['name' => $name, 'path' => $path ?? $manifestPath]);
                $this->stylesheets[] = new Stylesheet($name, $relative, null, 0, false);

                continue;
            }

            try {
                $css = $this->files->get($path);
            } catch (Throwable) {
                $this->diagnostics[] = Codes::make(Codes::STYLESHEET_NOT_FOUND, ['name' => $name, 'path' => $path]);
                $this->stylesheets[] = new Stylesheet($name, $relative, null, 0, false);

                continue;
            }

            $this->contents[$name] = $css;
            $this->stylesheets[] = new Stylesheet($name, $relative, hash('xxh128', $css), strlen($css), true);
        }

        $this->statKey = $this->currentStatKey($manifestPath);
    }

    /**
     * Whether a manifest file path is a stylesheet, read the way Laravel's Vite reads it: by the
     * extensions its `isCssPath()` accepts.
     */
    private static function isCss(string $file): bool
    {
        return preg_match('~\.(css|less|sass|scss|styl|stylus|pcss|postcss)(\?.*)?$~i', $file) === 1;
    }

    /**
     * @return array<string, array{0: int, 1: int}|null>
     */
    private function currentStatKey(string $manifestPath): array
    {
        $key = [$manifestPath => $this->stat($manifestPath)];

        foreach ($this->resolvedPaths as $path) {
            $key[$path] = $this->stat($path);
        }

        return $key;
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

    /**
     * @return array<string, mixed>
     */
    private function readManifest(string $path): array
    {
        if (! $this->files->isFile($path)) {
            return [];
        }

        try {
            $data = json_decode($this->files->get($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        if (! is_array($data)) {
            return [];
        }

        $manifest = [];

        foreach ($data as $key => $value) {
            $manifest[(string) $key] = $value;
        }

        return $manifest;
    }
}
