<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Pages;

use Closure;
use Daikazu\BladeWind\Analysis\ViewAnalyzer;
use Daikazu\BladeWind\Diagnostics\Codes;
use Daikazu\BladeWind\Discovery\ViewLocator;
use Daikazu\BladeWind\Integration\CompileObserver;
use Daikazu\BladeWind\Manifest\ManifestStore;
use Daikazu\BladeWind\Manifest\ViewEntry;
use Illuminate\Filesystem\Filesystem;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Derives a page's {@see ClassSet}: the HTML's own class tokens, unioned with the
 * analysed inventories of the views that rendered and their static dependency closure.
 */
final class ClassSetBuilder
{
    /**
     * The analyser and the logger arrive as closures because the common request never needs either:
     * every rendered view has an entry, and building `ViewAnalyzer` means constructing the Forte
     * parser, the class analyser and the compatibility hash for nothing.
     *
     * @var Closure(): ViewAnalyzer
     */
    private Closure $analyzer;

    /**
     * @var Closure(): LoggerInterface
     */
    private Closure $logger;

    /**
     * How many computed-but-unstored entries {@see self::$computed} keeps before the oldest is
     * dropped (and re-parsed on its next request): a manifest directory that stays unwritable for a
     * worker's whole life must not turn into one entry per view rendered.
     */
    private const COMPUTED_LIMIT = 256;

    /**
     * Paths this process has stopped asking about: ones the locator will not describe (a vendor
     * view, anything outside `bladewind.paths`) and ones whose analysis threw. Neither answer
     * changes within a process, and both are expensive to reach.
     *
     * @var array<string, true>
     */
    private array $unusable = [];

    /**
     * Entries this process computed but could not store (a read-only manifest directory, a failed
     * rename). Dropping one would shrink the page's class set on the next request, so each is kept
     * and offered to the store again on its next use until that succeeds.
     *
     * @var array<string, ViewEntry>
     */
    private array $computed = [];

    /**
     * @param  Closure(): ViewAnalyzer  $analyzer
     * @param  Closure(): LoggerInterface  $logger
     */
    public function __construct(
        private ManifestStore $entries,
        private ViewLocator $views,
        Closure $analyzer,
        private Filesystem $files,
        Closure $logger,
    ) {
        $this->analyzer = $analyzer;
        $this->logger = $logger;
    }

    /**
     * @param  list<string>  $renderedPaths  the views (and Livewire components) that rendered this page
     * @param  list<string>  $extraTokens  pseudo-tokens in the page's identity that are not classes (custom property names the document reads or `pages.keep_variables` selects): they change the set's hash, and a `--`-prefixed name matches nothing in the utility index
     */
    public function build(string $html, array $renderedPaths, array $extraTokens = []): ClassSet
    {
        $tokens = array_fill_keys([...HtmlClassScanner::tokens($html), ...$extraTokens], true);
        $rendered = array_fill_keys($renderedPaths, true);
        $seen = [];
        $queue = array_values(array_unique($renderedPaths));
        $unanalysed = 0;
        $unanalysedPaths = [];
        $analysed = 0;

        while (($path = array_shift($queue)) !== null) {
            if (isset($seen[$path])) {
                continue;
            }

            $seen[$path] = true;
            $entry = $this->current($path) ?? $this->remembered($path) ?? $this->analyseNow($path);

            if ($entry === null) {
                if (isset($rendered[$path])) {
                    $unanalysed++;
                    $unanalysedPaths[] = $path;
                }

                continue;
            }

            $analysed++;

            foreach ($entry->classes->index as $token => $row) {
                $tokens[(string) $token] = true;
            }

            foreach ($entry->dependencies as $dependency) {
                if ($dependency->resolvedPath === null || isset($seen[$dependency->resolvedPath])) {
                    continue;
                }

                if (! is_file($dependency->resolvedPath)) {
                    // The child was renamed or moved since this entry was written, and Blade does
                    // not recompile a parent for a change in what its tag resolves to: the entry
                    // the old path may still hold would stand in for a child that now lives
                    // elsewhere. Unknown, so the page is told it has an unanalysed source.
                    $seen[$dependency->resolvedPath] = true;
                    $unanalysed++;
                    $unanalysedPaths[] = $dependency->resolvedPath;

                    continue;
                }

                $queue[] = $dependency->resolvedPath;
            }
        }

        $list = array_keys($tokens);
        sort($list, SORT_STRING);

        return new ClassSet(array_map(strval(...), $list), $unanalysed, $analysed, $unanalysedPaths);
    }

    /**
     * The stored entry for $path, provided it still describes the file on disk. Blade can compile a
     * view without {@see CompileObserver} seeing its own source (an earlier
     * `prepareStringsForCompilation` callback such as Livewire's island rewriter, or an entry shipped
     * from another checkout), so a size or mtime that differs from the entry's marks it stale.
     */
    private function current(string $path): ?ViewEntry
    {
        $entry = $this->entries->get($path);

        if ($entry === null) {
            return null;
        }

        clearstatcache(true, $path);
        $size = @filesize($path);
        $mtime = @filemtime($path);

        if ($size === false || $mtime === false) {
            // Not a file any more (or unreadable): the entry is all there is.
            return $entry;
        }

        return $size === $entry->fingerprint->size && $mtime === $entry->fingerprint->mtime ? $entry : null;
    }

    /**
     * An entry this process computed for $path but could not store, offered to the store once more
     * on the way: a directory that has become writable since gets it without another parse.
     */
    private function remembered(string $path): ?ViewEntry
    {
        $entry = $this->computed[$path] ?? null;

        if ($entry === null) {
            return null;
        }

        try {
            $this->entries->put($entry);
            unset($this->computed[$path]);
        } catch (Throwable) {
            // Still unwritable; reported when the entry was first computed.
        }

        return $entry;
    }

    /**
     * Analyses $path here and now, for a view the manifest has no usable entry for.
     *
     * {@see CompileObserver} only writes entries when Blade compiles. A package upgrade (which moves
     * the compatibility hash {@see ManifestStore::get()} checks) or a deploy without
     * `storage/framework/views/bladewind` leaves warm compiled views with no readable entries, so
     * they are produced here rather than waiting for `view:clear`. A path the locator will not
     * describe stays unanalysed.
     *
     * One analysis per view per process: failures are remembered in {@see self::$unusable}. Storing
     * is caught apart from analysing (`ViewAnalyzer` records a bad template rather than throwing, so
     * the first catch is a vanished file), and an entry that cannot be saved is still used, via
     * {@see self::$computed}.
     */
    private function analyseNow(string $path): ?ViewEntry
    {
        if (isset($this->unusable[$path])) {
            return null;
        }

        try {
            $file = $this->views->describe($path);

            if ($file === null) {
                $this->unusable[$path] = true;

                return null;
            }

            $entry = ($this->analyzer)()->analyze($file, $this->files->get($file->path));
        } catch (Throwable $exception) {
            $this->unusable[$path] = true;
            $this->report($path, $exception);

            return null;
        }

        try {
            $this->entries->put($entry);
        } catch (Throwable $exception) {
            $this->computed[$path] = $entry;

            while (count($this->computed) > self::COMPUTED_LIMIT) {
                unset($this->computed[array_key_first($this->computed)]);
            }

            $this->report($path, $exception);
        }

        return $entry;
    }

    /**
     * Reports a failed on-demand analysis as BW1008, once per path since that is how often it is
     * attempted. Without it an unwritable manifest directory would show up only as latency.
     */
    private function report(string $path, Throwable $exception): void
    {
        try {
            ($this->logger)()->warning(
                'BladeWind '.Codes::ANALYSIS_FAILED.': on-demand analysis of '.$path.' failed: '.$exception->getMessage(),
                ['path' => $path, 'message' => $exception->getMessage(), 'exception' => $exception::class],
            );
        } catch (Throwable) {
            // Logging must never break a response.
        }
    }
}
