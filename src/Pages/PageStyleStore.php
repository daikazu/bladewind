<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Pages;

use Daikazu\BladeWind\BladeWind;
use Daikazu\BladeWind\BladeWindServiceProvider;
use Daikazu\BladeWind\Stylesheets\StylesheetIndex;
use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use RuntimeException;

/**
 * Generated stylesheets under the public path: a root stylesheet shared by every page built against
 * one compiled stylesheet, plus one page stylesheet per distinct {@see ClassSet}. Both are
 * content-addressed: by {@see self::sheetId()} (from {@see StylesheetIndex::hash()} and the package
 * version) and by a 12-hex prefix of {@see ClassSet::hash()}.
 */
final class PageStyleStore
{
    /**
     * How recently a page stylesheet must have been written or used to be safe from
     * {@see self::prune()}, and how stale a hit must be before {@see self::touchIfStale()} refreshes
     * it. This closes the race where one worker links a file another is pruning; ten minutes covers
     * an in-flight response, an HTTP-cached page and a bfcache restore.
     */
    public const GRACE_SECONDS = 600;

    /**
     * @param  string  $relativeDirectory  `bladewind.assets.path`: a directory under the public path, with
     *                                     surrounding slashes ignored. Empty (which would yield protocol-relative
     *                                     URLs) or a `..` segment is refused, at boot ({@see BladeWindServiceProvider::validateConfiguration()}).
     *
     * @throws InvalidArgumentException
     */
    public function __construct(
        private Filesystem $files,
        private string $publicPath,
        private string $relativeDirectory,
        private ?string $baseUrl = null,
    ) {
        $this->relativeDirectory = trim(str_replace('\\', '/', $relativeDirectory), '/');

        if ($this->relativeDirectory === '' || in_array('..', explode('/', $this->relativeDirectory), true)) {
            throw new InvalidArgumentException(sprintf(
                'bladewind.assets.path [%s] must name a directory under the public path: not empty, and without a ".." segment.',
                $relativeDirectory,
            ));
        }
    }

    public function directory(): string
    {
        return rtrim($this->publicPath, '/').'/'.$this->relativeDirectory;
    }

    /**
     * The 12-hex id both file names carry for one compiled stylesheet, from its hash and the package
     * version, since what the files hold also depends on this version's emission rules. An upgrade
     * names new files, and {@see self::prune()} removes the old ones.
     */
    public function sheetId(string $stylesheetHash): string
    {
        return substr(hash('xxh128', $stylesheetHash."\n".BladeWind::VERSION), 0, 12);
    }

    public function rootFile(string $stylesheetHash): string
    {
        return 'bw-root-'.$this->sheetId($stylesheetHash).'.css';
    }

    public function pageFile(string $stylesheetHash, string $setHash): string
    {
        return 'bw-page-'.$this->sheetId($stylesheetHash).'-'.substr($setHash, 0, 12).'.css';
    }

    /**
     * What {@see self::write()} puts in a file whose CSS is empty, so no generated file is ever zero
     * bytes: {@see self::has()} reads zero bytes as absent (a `touch()` racing a prune, a full disk),
     * so such a file is regenerated rather than linked for good.
     */
    private const EMPTY_CSS = '/* bladewind: no rules */';

    /**
     * Whether $file (a filename, not a path) exists under {@see self::directory()} and is not empty
     * (see {@see self::EMPTY_CSS}). Not memoised: a remembered positive could outlive another
     * worker's {@see self::prune()}.
     */
    public function has(string $file): bool
    {
        $path = $this->directory().'/'.$file;
        clearstatcache(true, $path);

        return $this->files->isFile($path) && (int) @filesize($path) > 0;
    }

    /**
     * Writes $css atomically (temp file plus rename). A no-op when $file already exists; empty CSS
     * is written as a comment.
     */
    public function write(string $file, string $css): void
    {
        if ($this->has($file)) {
            return;
        }

        if ($css === '') {
            $css = self::EMPTY_CSS;
        }

        $directory = $this->directory();
        $target = $directory.'/'.$file;

        $this->files->ensureDirectoryExists($directory);
        $temporary = $target.'.'.bin2hex(random_bytes(6)).'.tmp';

        // A short write (a full disk that `file_put_contents()` did not warn about) would otherwise
        // be renamed into place as a truncated stylesheet and cached for good.
        if ($this->files->put($temporary, $css) !== strlen($css)) {
            $this->files->delete($temporary);

            throw new RuntimeException("Unable to write generated stylesheet [{$target}].");
        }

        if (! @rename($temporary, $target)) {
            $this->files->delete($temporary);

            throw new RuntimeException("Unable to write generated stylesheet [{$target}].");
        }
    }

    /**
     * Marks $file as still in use when its mtime is older than {@see self::GRACE_SECONDS}, so the
     * cap prunes by last use rather than creation order, which would evict the busiest pages first.
     * At most one `utime` per hot page per interval.
     *
     * @return bool whether the file was touched
     */
    public function touchIfStale(string $file): bool
    {
        $path = $this->directory().'/'.$file;
        clearstatcache(true, $path);
        $mtime = @filemtime($path);

        if ($mtime === false || $mtime > time() - self::GRACE_SECONDS) {
            return false;
        }

        return @touch($path);
    }

    /**
     * The URL a page links this file by: the application's own origin unless `assets.url` names
     * another. Not `asset()`: `ASSET_URL` often points at a CDN bucket uploaded at build time, which
     * these runtime-written files never reach. `assets.url` is the opt-in for an origin-pull CDN.
     */
    public function url(string $file): string
    {
        $path = $this->relativeDirectory.'/'.$file;

        if ($this->baseUrl !== null && $this->baseUrl !== '') {
            return rtrim($this->baseUrl, '/').'/'.$path;
        }

        return url('/'.$path);
    }

    /**
     * Deletes every root or page stylesheet whose {@see self::sheetId()} differs from
     * $stylesheetHash's (a rebuild's or an upgrade's leftovers), then the least recently used
     * same-sheet page stylesheets beyond $maxFiles. Never touches other files in the directory, or
     * any file younger than {@see self::GRACE_SECONDS}.
     *
     * @return int the number of files deleted
     */
    public function prune(string $stylesheetHash, int $maxFiles): int
    {
        $sheet = $this->sheetId($stylesheetHash);
        $directory = $this->directory();
        $cutoff = time() - self::GRACE_SECONDS;
        $deleted = 0;

        /** @var list<string> $sameSheetPages */
        $sameSheetPages = [];

        $candidates = [
            ...(glob($directory.'/bw-root-*.css') ?: []),
            ...(glob($directory.'/bw-page-*.css') ?: []),
        ];

        foreach ($candidates as $existing) {
            $name = basename($existing);
            $isPage = str_starts_with($name, 'bw-page-');
            $prefix = substr($name, strlen($isPage ? 'bw-page-' : 'bw-root-'), 12);

            if ($prefix !== $sheet) {
                // A Vite rebuild leaves these behind, but an old worker or an HTTP-cached page may
                // still be asking for one; they go on the next prune past the window.
                if ($this->mtime($existing) > $cutoff) {
                    continue;
                }

                $this->files->delete($existing);
                $deleted++;

                continue;
            }

            if ($isPage) {
                $sameSheetPages[] = $existing;
            }
        }

        $excess = count($sameSheetPages) - $maxFiles;

        if ($excess > 0) {
            // Filename breaks an mtime tie: several page files written within the same second
            // (the resolution filemtime() reports) would otherwise be ordered by whatever glob
            // returned, so which of them survives the cap would differ between runs.
            usort($sameSheetPages, fn (string $a, string $b): int => [$this->mtime($a), basename($a)] <=> [$this->mtime($b), basename($b)]);

            foreach (array_slice($sameSheetPages, 0, $excess) as $stale) {
                if ($this->mtime($stale) > $cutoff) {
                    continue;
                }

                $this->files->delete($stale);
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * Deletes every generated root and page stylesheet.
     *
     * @return int the number of files deleted
     */
    public function clear(): int
    {
        $directory = $this->directory();
        $deleted = 0;

        $candidates = [
            ...(glob($directory.'/bw-root-*.css') ?: []),
            ...(glob($directory.'/bw-page-*.css') ?: []),
        ];

        foreach ($candidates as $existing) {
            $this->files->delete($existing);
            $deleted++;
        }

        return $deleted;
    }

    /**
     * The file's modification time, read past PHP's stat cache: a file written or touched earlier
     * in the same process (a page stylesheet this request just wrote, or a test's touch()) would
     * otherwise report the timestamp it had when it was first stat'ed.
     */
    private function mtime(string $path): int
    {
        clearstatcache(true, $path);
        $mtime = filemtime($path);

        return $mtime === false ? 0 : $mtime;
    }
}
