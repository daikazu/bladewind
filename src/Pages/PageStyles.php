<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Pages;

use Daikazu\BladeWind\Declarations\Safelist;
use Daikazu\BladeWind\Diagnostics\Codes;
use Daikazu\BladeWind\Diagnostics\Diagnostic;
use Daikazu\BladeWind\Http\InjectPageStyles;
use Daikazu\BladeWind\Pages\Drivers\CssFrameworkDriver;
use Daikazu\BladeWind\Pages\Drivers\DriverSelector;
use Daikazu\BladeWind\Stylesheets\Stylesheet;
use Daikazu\BladeWind\Stylesheets\StylesheetIndex;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Vite;
use Illuminate\Foundation\ViteException;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Turns a rendered response into the stylesheet links it should carry: the root stylesheet for the
 * compiled build plus the page stylesheet for this page's class set, or, when that cannot be
 * established, the full stylesheet exactly as `@vite` would emit it.
 *
 * The driver, the split, the {@see VariableGraph} and the {@see UtilityRuleIndex} are memoised per
 * stylesheet hash, so a process rebuilds them only after a Vite build changes the stylesheet.
 *
 * Not final: the feature suite extends it with a throwing {@see self::forResponse()} to exercise
 * {@see InjectPageStyles}'s last-resort fallback.
 */
class PageStyles
{
    /**
     * How much of a diagnostic's message distinguishes it from another for {@see self::logOnce()}.
     */
    private const LOG_KEY_LENGTH = 120;

    /**
     * How many distinct diagnostics one process logs before falling silent.
     */
    private const LOG_KEY_LIMIT = 50;

    /**
     * How many page stylesheets {@see self::$inlined} holds before the oldest is evicted, so a
     * worker serving hundreds of distinct class sets does not keep them all in memory.
     */
    private const INLINE_MEMO_LIMIT = 64;

    /**
     * @var array<string, true> diagnostics already logged, keyed by code and message prefix
     */
    private array $logged = [];

    private bool $logCapReported = false;

    /**
     * @var array<string, string> inlined page stylesheet contents by file name, bounded by
     *                            {@see self::INLINE_MEMO_LIMIT}
     */
    private array $inlined = [];

    private ?string $splitHash = null;

    private ?SplitStylesheet $split = null;

    /**
     * The driver that produced {@see self::$split}, or null when no driver recognised the stylesheet
     * (the split is then an unfound placeholder).
     */
    private ?CssFrameworkDriver $driver = null;

    private ?VariableGraph $graph = null;

    /**
     * The runtime tokens for {@see self::$split}'s stylesheet ({@see DriverSelector::runtimeTokens()}),
     * memoised beside it.
     *
     * @var list<string>
     */
    private array $runtimeTokens = [];

    /**
     * The exception message a driver threw for {@see self::$splitHash}'s stylesheet, when that is
     * why the memoised split is the unfound placeholder.
     */
    private ?string $splitFailure = null;

    private ?string $indexHash = null;

    private ?UtilityRuleIndex $index = null;

    public function __construct(
        private Repository $config,
        private Vite $vite,
        private StylesheetIndex $stylesheets,
        private DriverSelector $drivers,
        private PageCssBuilder $builder,
        private ClassSetBuilder $sets,
        private PageStyleStore $store,
        private LoggerInterface $log,
        private SupportCssBuilder $support,
    ) {}

    /**
     * @param  list<string>  $renderedPaths  every view rendered for this response, from {@see RenderedViews}
     */
    public function forResponse(string $html, array $renderedPaths): PageLinks
    {
        // Checked here as well as at boot, so BladeWind turned off at runtime (a config change, a
        // test) serves the full stylesheet and logs nothing.
        if (! (bool) $this->config->get('bladewind.enabled', true) || ! (bool) $this->config->get('bladewind.pages.enabled', true)) {
            return $this->fallback('disabled', silent: true);
        }

        if ($this->vite->isRunningHot()) {
            // Vite serves the stylesheet from memory; nothing on disk can be split.
            return $this->fallback('vite hot', silent: true);
        }

        $stylesheet = $this->primaryStylesheet();

        if ($stylesheet === null || $stylesheet->hash === null) {
            return $this->fallback('stylesheet not found through the Vite manifest');
        }

        $split = $this->split($stylesheet);
        $driver = $this->driver;

        if ($driver === null && $this->splitFailure !== null) {
            // A driver threw on this build; BW6001 named it when it happened.
            return $this->fallback('stylesheet not recognised', silent: true);
        }

        if ($driver === null) {
            // Configuration can still name a driver for a build the signature check misses, so the
            // message says what each driver looked for.
            $this->logOnce(
                Codes::make(Codes::STYLESHEET_NOT_SPLITTABLE, [
                    'name' => $stylesheet->name,
                    'detail' => 'shape a CSS framework driver recognises (looked for: '.implode('; ', $this->drivers->expectations()).')',
                ]),
                ['name' => $stylesheet->name],
            );

            return $this->fallback('stylesheet not recognised', silent: true);
        }

        if (! $split->found) {
            $this->logOnce(Codes::make(Codes::STYLESHEET_NOT_SPLITTABLE, ['name' => $stylesheet->name, 'detail' => $driver->expects()]), ['name' => $stylesheet->name]);

            return $this->fallback('nothing to split', silent: true);
        }

        if (trim($split->root) === '' && trim($split->utilities) === '') {
            // Both halves empty would leave the page unstyled; a cheap check on custom drivers.
            $this->logOnce(
                Codes::make(Codes::STYLESHEET_NOT_SPLITTABLE, ['name' => $stylesheet->name, 'detail' => 'content in the split the '.$driver->name().' driver returned (the root and the utilities were both empty)']),
                ['name' => $stylesheet->name, 'driver' => $driver->name()],
            );

            return $this->fallback('nothing to split', silent: true);
        }

        if (! $split->supportShaken) {
            // Support-layer tree-shaking is off for this stylesheet, so the root carries every theme
            // variable. Otherwise the only sign is `support=full` in a debug-only header.
            $this->logOnce(
                Codes::make(Codes::STYLESHEET_SUPPORT_UNSHAKEN, [
                    'name' => $stylesheet->name,
                    'reason' => $split->unshakenReason ?? 'the layers are not a shape this version takes apart',
                ]),
                ['name' => $stylesheet->name, 'reason' => (string) $split->unshakenReason],
            );
        }

        $graph = $this->graph($split);

        // Custom properties the document reads and `keep_variables` selects seed the page's support
        // declarations and join the class set as pseudo-tokens, so pages with the same classes but
        // different inline `var()` get different files. Only names this stylesheet declares: any
        // other (`style="--progress:42%"`, Livewire's own) cannot change either file and would only
        // split the cache, once per record if built from user data.
        $documentNames = [
            ...array_values(array_filter(HtmlVariableScanner::names($html), $graph->has(...))),
            ...$graph->match($this->keepVariables()),
        ];
        // Runtime tokens (classes the framework's JavaScript adds, e.g. Bootstrap's `modal-backdrop`)
        // and the safelist join the set too. Safelist patterns go in as written so the hash changes
        // with them; what they select is resolved in {@see self::utilitiesCss()}.
        $safelist = $this->safelist();
        $set = $this->sets->build($html, $renderedPaths, [...$documentNames, ...$this->runtimeTokens, ...$safelist->exact(), ...$safelist->patterns()]);

        // An unanalysed view is one the locator will not describe (a package's views, anything
        // outside `bladewind.paths`). Its `@else` branches, `wire:loading.class` and Alpine bindings
        // are invisible, so a page built from its HTML alone breaks on the first Livewire update:
        // unknown means the full stylesheet unless `pages.unanalysed` is `html`. Views rendered from
        // a string (under `view.compiled`) have no other source and never trigger the fallback.
        $sources = $this->unanalysedSources($set->unanalysedPaths);

        if ($sources !== [] && $this->unanalysedPolicy() === 'fallback') {
            $this->logOnce(
                Codes::make(Codes::PAGE_VIEWS_UNANALYSED, [
                    'n' => count($sources) === 1 ? '1 rendered view has' : count($sources).' rendered views have',
                    'paths' => self::listed($sources),
                ]),
                ['paths' => $sources],
                Codes::PAGE_VIEWS_UNANALYSED,
            );

            return $this->fallback('rendered views have no analysis entry', silent: true);
        }

        if ($set->analysed === 0 && $sources !== []) {
            // Under the `html` policy, nothing analysed at all usually means `bladewind.paths` misses
            // where the views live, so it is logged once.
            $this->logOnce(
                Codes::make(Codes::PAGE_VIEWS_UNANALYSED, [
                    'n' => count($sources) === 1 ? '1 rendered view has' : count($sources).' rendered views have',
                    'paths' => self::listed($sources),
                ]),
                ['analysed' => '0', 'unanalysed' => (string) $set->unanalysed],
                Codes::PAGE_VIEWS_UNANALYSED,
            );
        }

        // The other configured stylesheets are linked whole, so the root must define what they read.
        $companions = $this->companionCss($stylesheet->name);
        // File names cover the driver and the companions as well as the primary hash: either can
        // change what the files hold, and write() never rewrites an existing file.
        $buildHash = self::buildHashFor($stylesheet->hash, $driver->name(), $companions);
        $setHash = $set->hash($buildHash);
        $root = $this->store->rootFile($buildHash);
        $page = $this->store->pageFile($buildHash, $setHash);
        /** @var list<string> $names the support declarations this page's file carries, for the header */
        $names = [];
        $cached = false;
        // Nanoseconds spent on what only debug mode computes, for the panel to leave out of its time.
        $debugOverhead = 0;

        try {
            if (! $this->store->has($root)) {
                // Only the support declarations the root's own layers, keyframes and companion
                // stylesheets reference; the rest travel in the page files that need them. Unshaken
                // support layers come back whole.
                $this->store->write($root, $this->support->root($split, $graph, $companions));
            }

            $cached = $this->store->has($page);

            if ($cached) {
                // A hit is a use: keep the cap pruning by last use rather than by creation order.
                $this->store->touchIfStale($page);
                // Only the debug header reads the support count; recomputing it needs the utilities
                // index a cache hit otherwise skips, so production reports zero.
                if ($this->debug()) {
                    $started = hrtime(true);
                    $names = $this->pageSupport($split, $graph, $this->utilitiesCss($stylesheet->hash, $driver, $split, $set), $documentNames, $companions);
                    $debugOverhead += hrtime(true) - $started;
                }
            } else {
                $utilities = $this->utilitiesCss($stylesheet->hash, $driver, $split, $set);
                $names = $this->pageSupport($split, $graph, $utilities, $documentNames, $companions);

                $this->store->write($page, $this->support->page($split, $graph, $names, $utilities));
                $this->store->prune($buildHash, $this->maxFiles());
            }
        } catch (RuntimeException $exception) {
            // BW6004 names the failure, so the fallback stays silent. Keyed on the directory: every
            // page has its own file name and would otherwise exhaust the log budget.
            $this->logOnce(
                Codes::make(Codes::PAGE_STYLES_WRITE_FAILED, ['path' => $this->store->directory(), 'message' => $exception->getMessage()]),
                ['directory' => $this->store->directory(), 'message' => $exception->getMessage()],
                Codes::PAGE_STYLES_WRITE_FAILED.'|'.$this->store->directory(),
            );

            return $this->fallback('page stylesheet could not be written', silent: true);
        }

        $setId = substr($setHash, 0, 12);
        // Inline delivery only changes how the page CSS reaches the browser: the root stays a
        // cacheable link and the page file stays on disk for prune(). An unreadable file is linked.
        $pageCss = $this->inlineDelivery() ? $this->pageCss($page) : null;

        // A per-response marker makes Livewire's navigate append the page asset on every visit
        // rather than keep an earlier copy; {@see NavigateScript} drops the stale ones.
        $nav = ' data-bladewind-nav="'.bin2hex(random_bytes(3)).'"';

        $metrics = [];

        if ($this->panel()) {
            $started = hrtime(true);
            $metrics = $this->metrics(
                $stylesheet,
                $root,
                $page,
                $cached,
                $setId,
                count($set->tokens),
                $set->analysed,
                $set->unanalysed,
                $split->supportShaken ? (string) count($names) : 'full',
                $pageCss === null ? 'link' : 'inline',
                $driver->name(),
                $this->drivers->auto(),
                count($renderedPaths),
                $set->unanalysedPaths,
            );
            $debugOverhead += hrtime(true) - $started;
        }

        return new PageLinks(
            $this->link($this->store->url($root))
                .($pageCss === null
                    ? $this->link($this->store->url($page), attributes: $nav)
                    : '<style data-bladewind-page="'.$setId.'"'.$nav.$this->nonceAttribute().'>'.self::inlineCss($pageCss).'</style>')
                .$this->secondaryLinks($stylesheet->name)
                .$this->navigateScript(),
            sprintf(
                // `unanalysed` is BW6003, reported here rather than logged. `tokens` includes the `--`
                // pseudo-tokens. `support` is the variable count in the page file, or `full` when the
                // root holds them all. `delivery` is `link` whenever `inline` could not be honoured.
                'page=%s framework=%s tokens=%d analysed=%d unanalysed=%d support=%s delivery=%s root=%d page=%d',
                $setId,
                $driver->name(),
                count($set->tokens),
                $set->analysed,
                $set->unanalysed,
                $split->supportShaken ? (string) count($names) : 'full',
                $pageCss === null ? 'link' : 'inline',
                $this->bytes($root),
                $this->bytes($page),
            ),
            false,
            $metrics,
            $debugOverhead / 1e6,
        );
    }

    /**
     * Links to the whole stylesheet as `@vite` would emit them, logging BW6001 once unless $silent.
     * Public so {@see InjectPageStyles} can fall back when {@see self::forResponse()} itself throws.
     */
    public function fallbackLinks(string $reason, bool $silent = false): PageLinks
    {
        return $this->fallback($reason, $silent);
    }

    /**
     * What `@bladewindStyles` renders: the whole stylesheet linked as `@vite` would, carrying
     * {@see StylesDirective::MARKER} so {@see InjectPageStyles} can swap in this page's own files.
     * It fails as `@vite` would too: a missing manifest or entry throws, and an entry that resolves
     * to a script is refused (BW3002). Empty only when no stylesheet is configured.
     *
     * @throws ViteException
     */
    public function markedLinks(): string
    {
        $names = $this->names();

        if ($names === []) {
            return '';
        }

        if (! $this->vite->isRunningHot()) {
            foreach ($this->stylesheets->diagnostics() as $diagnostic) {
                if ($diagnostic->code === Codes::STYLESHEET_NOT_CSS) {
                    throw new ViteException($diagnostic->message);
                }
            }
        }

        $html = '';

        foreach ($names as $name) {
            $html .= $this->link($this->vite->asset($name), marked: true);
        }

        return $html;
    }

    /**
     * What the current build's generated files are named by ({@see PageStyleStore::sheetId()}):
     * the primary stylesheet's hash, the driver name and the companion stylesheets' contents. Null
     * when no stylesheet resolves or no driver recognises it. Public so tests can derive file names.
     */
    public function buildHash(): ?string
    {
        $stylesheet = $this->primaryStylesheet();

        if ($stylesheet === null || $stylesheet->hash === null) {
            return null;
        }

        $this->split($stylesheet);

        return $this->driver === null ? null : self::buildHashFor($stylesheet->hash, $this->driver->name(), $this->companionCss($stylesheet->name));
    }

    private static function buildHashFor(string $stylesheetHash, string $driver, string $companionCss): string
    {
        return hash('xxh128', $stylesheetHash."\n".$driver."\n".hash('xxh128', $companionCss));
    }

    /**
     * The stylesheet whose utilities layer pages are built from: the first configured entry that
     * resolved through the Vite manifest and could be read.
     */
    private function primaryStylesheet(): ?Stylesheet
    {
        foreach ($this->stylesheets->stylesheets() as $stylesheet) {
            if ($stylesheet->found && $stylesheet->hash !== null) {
                return $stylesheet;
            }
        }

        return null;
    }

    /**
     * The stylesheet split by the driver that recognised it, memoised per hash with the driver in
     * {@see self::$driver}. An unrecognised stylesheet is memoised too, as an unfound split with a
     * null driver, so detection is not repeated per request.
     */
    private function split(Stylesheet $stylesheet): SplitStylesheet
    {
        if ($this->split !== null && $this->splitHash === $stylesheet->hash) {
            return $this->split;
        }

        $css = $this->stylesheets->contents()[$stylesheet->name] ?? '';
        // Clear the old memo first and assign the new one only once the driver succeeds, so a
        // throwing driver cannot leave the old split memoised under the new hash.
        $this->index = null;
        $this->indexHash = null;
        $this->graph = null;
        $this->split = null;
        $this->splitHash = null;
        $this->driver = null;
        $this->runtimeTokens = [];
        $this->splitFailure = null;

        try {
            $driver = $this->drivers->select($css);
            $split = $driver?->split($css) ?? new SplitStylesheet($css, '', false);
            $runtimeTokens = $driver === null ? [] : $this->drivers->runtimeTokens($css);
        } catch (Throwable $exception) {
            // Memoised as unrecognised for this build: the full stylesheet is served and BW6001
            // logged once. A rebuild changes the hash and is tried afresh.
            $this->splitFailure = $exception->getMessage();
            $this->logOnce(
                Codes::make(Codes::PAGE_STYLES_FALLBACK, ['reason' => 'a CSS framework driver failed on '.$stylesheet->name.': '.$exception->getMessage()]),
                ['name' => $stylesheet->name, 'message' => $exception->getMessage(), 'exception' => $exception::class],
            );
            $this->splitHash = $stylesheet->hash;

            return $this->split = new SplitStylesheet($css, '', false);
        }

        $this->driver = $driver;
        $this->runtimeTokens = $runtimeTokens;
        $this->splitHash = $stylesheet->hash;

        return $this->split = $split;
    }

    /**
     * The graph over this stylesheet's support layers, memoised for as long as the split it was built
     * from is. Needed on every request that gets this far: `keep_variables` is matched against the
     * names it declares before the class set is built.
     */
    private function graph(SplitStylesheet $split): VariableGraph
    {
        return $this->graph ??= VariableGraph::fromSplit($split);
    }

    /**
     * This page's own utilities, from the tokens its class set holds: the bare rule run {@see
     * PageCssBuilder} selects, wrapped however the driver's cascade needs it (`@layer utilities{...}`
     * for Tailwind 4, bare for a layer-less build).
     */
    private function utilitiesCss(string $stylesheetHash, CssFrameworkDriver $driver, SplitStylesheet $split, ClassSet $set): string
    {
        $index = $this->index($stylesheetHash, $driver, $split->utilities);
        // A safelist prefix (`text-*`) names whatever the stylesheet has rules for under it.
        $tokens = [...$set->tokens, ...$this->safelist()->expand($index->tokens())];

        return $driver->wrapUtilities($this->builder->build($tokens, $index));
    }

    /**
     * `bladewind.safelist`, read per request like the rest of the configuration: a test or a
     * runtime change moves it after this instance was built.
     */
    private function safelist(): Safelist
    {
        return Safelist::fromConfig($this->config);
    }

    /**
     * The support declarations one page file carries: the variables its utilities and document read,
     * closed over the values they reach, minus those the root carries. Keyframes all stay in the root.
     *
     * Closed again after the subtraction, as {@see SupportCssBuilder::page()} emits it, so the
     * header's `support=` count matches what the file actually carries.
     *
     * @param  list<string>  $documentNames  the custom properties the HTML named and `keep_variables` selected
     * @return list<string>
     */
    private function pageSupport(SplitStylesheet $split, VariableGraph $graph, string $utilitiesCss, array $documentNames, string $companionCss): array
    {
        $seeds = [...VariableGraph::refs($utilitiesCss), ...$documentNames];

        return $graph->closure(array_values(array_diff($graph->closure($seeds), $this->support->rootNames($split, $graph, $companionCss))));
    }

    /**
     * The other configured stylesheets' contents. They are linked whole ({@see self::secondaryLinks()}),
     * so whatever they read of the primary's theme has to be defined by the root.
     */
    private function companionCss(string $primary): string
    {
        $css = '';

        foreach ($this->stylesheets->contents() as $name => $contents) {
            if ($name !== $primary) {
                $css .= $contents;
            }
        }

        return $css;
    }

    /**
     * The variable names `pages.keep_variables` holds: theme variables JavaScript reads by name,
     * which nothing on the server can see. Each is an exact name or a `--prefix-*` pattern, matched
     * against what the stylesheet declares by {@see VariableGraph::match()}.
     *
     * @return list<string>
     */
    private function keepVariables(): array
    {
        $configured = $this->config->get('bladewind.pages.keep_variables', []);
        /** @var list<string> $patterns */
        $patterns = [];

        if (is_array($configured)) {
            foreach ($configured as $pattern) {
                if (is_string($pattern) && $pattern !== '') {
                    $patterns[] = $pattern;
                }
            }
        }

        return $patterns;
    }

    /**
     * The utilities layer indexed by class token, built at most once per stylesheet and only when a
     * page stylesheet actually has to be generated: a request that hits an existing page file never
     * pays for the walk (a debug request does, to fill in the header's support count).
     */
    private function index(string $stylesheetHash, CssFrameworkDriver $driver, string $utilitiesCss): UtilityRuleIndex
    {
        if ($this->index !== null && $this->indexHash === $stylesheetHash) {
            return $this->index;
        }

        // Assigned only once built, for the same reason {@see self::split()} is.
        $index = UtilityRuleIndex::build($utilitiesCss, $driver->indexTokens(...));
        $this->indexHash = $stylesheetHash;

        return $this->index = $index;
    }

    private function fallback(string $reason, bool $silent = false): PageLinks
    {
        $html = $this->fullStylesheetLinks();

        if ($html === '') {
            // A page with no stylesheet at all is always logged, whatever $silent says.
            $this->logOnce(
                Codes::make(Codes::PAGE_STYLES_FALLBACK, ['reason' => "no stylesheet could be linked ({$reason})"]),
                ['reason' => $reason],
            );
        } elseif (! $silent) {
            $this->logOnce(Codes::make(Codes::PAGE_STYLES_FALLBACK, ['reason' => $reason]), ['reason' => $reason]);
        }

        return new PageLinks($html, 'fallback='.$reason, true, $this->panel() ? ['fallback' => $reason] : []);
    }

    /**
     * A link for every configured stylesheet the Vite manifest can resolve. An entry it cannot is
     * skipped rather than failing the whole response; that entry is already what BW3001 reports.
     */
    private function fullStylesheetLinks(): string
    {
        return $this->viteLinks($this->names());
    }

    /**
     * The configured stylesheets other than the one pages are built from, linked whole after the
     * root and page files: only the primary entry is split, so an application that ships a second
     * stylesheet keeps it exactly as `@vite` would have delivered it.
     */
    private function secondaryLinks(string $primary): string
    {
        return $this->viteLinks(array_values(array_filter($this->names(), static fn (string $name): bool => $name !== $primary)));
    }

    /**
     * @param  list<string>  $names
     */
    private function viteLinks(array $names, bool $marked = false): string
    {
        $html = '';

        foreach ($names as $name) {
            try {
                $html .= $this->link($this->vite->asset($name), $marked);
            } catch (Throwable) {
                continue;
            }
        }

        return $html;
    }

    /**
     * @return list<string>
     */
    private function names(): array
    {
        $configured = $this->config->get('bladewind.stylesheets', []);
        $names = [];

        if (is_array($configured)) {
            foreach ($configured as $name) {
                if (is_string($name) && $name !== '') {
                    $names[] = $name;
                }
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * Whether this page's CSS travels in the HTML rather than in a `<link>`. Anything but the exact
     * string `inline` is the default `link` mode.
     */
    private function inlineDelivery(): bool
    {
        return $this->config->get('bladewind.pages.delivery', 'link') === 'inline';
    }

    /**
     * The page stylesheet's CSS, memoised by file name so a page repeated in a long-lived worker
     * (Octane) costs one read; the memo evicts in insertion order. Null when unreadable, in which
     * case the caller links the file.
     */
    private function pageCss(string $file): ?string
    {
        if (isset($this->inlined[$file])) {
            return $this->inlined[$file];
        }

        $css = @file_get_contents($this->store->directory().'/'.$file);

        if ($css === false) {
            return null;
        }

        $this->inlined[$file] = $css;

        while (count($this->inlined) > self::INLINE_MEMO_LIMIT) {
            unset($this->inlined[array_key_first($this->inlined)]);
        }

        return $css;
    }

    /**
     * $css made safe inside a `<style>` element. Only `</style` can end it early, so escaping every
     * `</` is sufficient and lossless: `<\/` only occurs inside a string or a comment, where CSS
     * reads the two the same.
     */
    private static function inlineCss(string $css): string
    {
        return str_replace('</', '<\/', $css);
    }

    /**
     * What a rendered view without an analysis entry does to its page: `fallback` (the default)
     * serves the full stylesheet, `html` builds the page from its rendered classes alone. Anything
     * else is the default.
     */
    private function unanalysedPolicy(): string
    {
        return $this->config->get('bladewind.pages.unanalysed', 'fallback') === 'html' ? 'html' : 'fallback';
    }

    /**
     * The unanalysed rendered paths that have a source the analysis could have covered: every one
     * except a view compiled from a string under `view.compiled`, which exists only as its HTML.
     *
     * @param  list<string>  $paths
     * @return list<string>
     */
    private function unanalysedSources(array $paths): array
    {
        $compiled = $this->compiledDirectory();

        return array_values(array_filter(
            $paths,
            static fn (string $path): bool => $compiled === null || ! str_starts_with(realpath($path) ?: $path, $compiled),
        ));
    }

    /**
     * `view.compiled` with a trailing separator, resolved; null when it is not configured.
     */
    private function compiledDirectory(): ?string
    {
        $compiled = $this->config->get('view.compiled');

        return is_string($compiled) && $compiled !== '' ? rtrim((string) (realpath($compiled) ?: $compiled), '/\\').DIRECTORY_SEPARATOR : null;
    }

    /**
     * Up to three of $paths for a log line, with an ellipsis for the rest.
     *
     * @param  list<string>  $paths
     */
    private static function listed(array $paths): string
    {
        return implode(', ', array_slice($paths, 0, 3)).(count($paths) > 3 ? ', ...' : '');
    }

    private function maxFiles(): int
    {
        $configured = $this->config->get('bladewind.pages.max_files', 500);

        return is_numeric($configured) ? (int) $configured : 500;
    }

    /**
     * Whether the debug header is going to be sent at all ({@see InjectPageStyles::handle()}), which
     * is the only thing that reads the counts this class works to fill in.
     */
    private function debug(): bool
    {
        return (bool) $this->config->get('app.debug', false) || (bool) $this->config->get('bladewind.debug', false);
    }

    /**
     * What the debug panel shows for this response. Only `bladewind.debug` renders the panel, so the
     * file reads and gzip passes happen only then; `app.debug` alone gets the header.
     *
     * `rendered` counts distinct rendered views (`analysed` also counts the dependency closure).
     * `unanalysed_kinds` sorts unanalysed views into those rendered from strings (no source to
     * analyse) and those outside `bladewind.paths`, with the directories to add.
     * `framework_detected` is whether the driver was auto-detected or named in configuration.
     *
     * @param  list<string>  $unanalysedPaths
     * @return array{}|array{set: string, tokens: int, analysed: int, unanalysed: int, support: string, delivery: string, framework: string, framework_detected: bool, cached: bool, root_file: string, page_file: string, root_bytes: int, root_gzip: int, page_bytes: int, page_gzip: int, full_bytes: int, full_gzip: int|null, rendered: int, unanalysed_kinds: array{strings: int, outside: list<string>, outside_count: int, outside_more: bool}}
     */
    private function metrics(Stylesheet $stylesheet, string $root, string $page, bool $cached, string $setId, int $tokens, int $analysed, int $unanalysed, string $support, string $delivery, string $framework, bool $frameworkDetected, int $rendered = 0, array $unanalysedPaths = []): array
    {
        if (! $this->panel()) {
            // Also checked by the caller; a production request must not pay for the gzip passes.
            return [];
        }

        [$rootBytes, $rootGzip] = $this->sizes($root);
        [$pageBytes, $pageGzip] = $this->sizes($page);
        $full = $this->stylesheets->contents()[$stylesheet->name] ?? '';
        $fullGzip = $full === '' ? null : strlen((string) gzencode($full, 6));

        return [
            'set' => $setId,
            'tokens' => $tokens,
            'analysed' => $analysed,
            'unanalysed' => $unanalysed,
            'support' => $support,
            'delivery' => $delivery,
            'framework' => $framework,
            'framework_detected' => $frameworkDetected,
            'cached' => $cached,
            'root_file' => $root,
            'page_file' => $page,
            'root_bytes' => $rootBytes,
            'root_gzip' => $rootGzip,
            'page_bytes' => $pageBytes,
            'page_gzip' => $pageGzip,
            'full_bytes' => $stylesheet->bytes,
            'full_gzip' => $fullGzip,
            'rendered' => $rendered,
            'unanalysed_kinds' => $this->unanalysedKinds($unanalysedPaths),
        ];
    }

    /**
     * @param  list<string>  $paths
     * @return array{strings: int, outside: list<string>, outside_count: int, outside_more: bool}
     */
    private function unanalysedKinds(array $paths): array
    {
        $compiled = $this->compiledDirectory();
        $base = base_path().DIRECTORY_SEPARATOR;
        $kinds = ['strings' => 0, 'outside' => [], 'outside_count' => 0, 'outside_more' => false];
        $directories = [];

        foreach ($paths as $path) {
            $real = realpath($path) ?: $path;

            if ($compiled !== null && str_starts_with($real, $compiled)) {
                $kinds['strings']++;

                continue;
            }

            $kinds['outside_count']++;
            $marker = DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'views'.DIRECTORY_SEPARATOR;
            $cut = strpos($real, $marker);
            $directory = $cut === false ? dirname($real) : substr($real, 0, $cut + strlen($marker) - 1);
            $directories[str_starts_with($directory, $base) ? substr($directory, strlen($base)) : $directory] = true;
        }

        $kinds['outside'] = array_slice(array_keys($directories), 0, 4);
        $kinds['outside_more'] = count($directories) > 4;

        return $kinds;
    }

    /**
     * Raw and gzipped (level 6) size of a stored file; both zero when it cannot be read.
     *
     * @return array{0: int, 1: int}
     */
    private function sizes(string $file): array
    {
        $css = @file_get_contents($this->store->directory().'/'.$file);

        if ($css === false || $css === '') {
            return [0, 0];
        }

        return [strlen($css), strlen((string) gzencode($css, 6))];
    }

    private function panel(): bool
    {
        return (bool) $this->config->get('bladewind.debug', false);
    }

    private function link(string $url, bool $marked = false, string $attributes = ''): string
    {
        return '<link rel="stylesheet" href="'.e($url).'"'.($marked ? ' '.StylesDirective::MARKER : '').$attributes.$this->nonceAttribute().'>';
    }

    /**
     * The `wire:navigate` cleanup script ({@see NavigateScript}): inline, carrying the nonce, when
     * the application registered one with Vite, since that runs under a nonce policy without an
     * extra request; external otherwise, so a `script-src 'self'` policy runs it too.
     */
    private function navigateScript(): string
    {
        $nonce = $this->nonceAttribute();

        if ($nonce !== '') {
            return '<script data-bladewind-navigate'.$nonce.'>'.NavigateScript::SOURCE.'</script>';
        }

        return '<script src="'.e($this->store->url(NavigateScript::file())).'" data-bladewind-navigate defer></script>';
    }

    /**
     * ` nonce="..."` for every tag this package generates when the application registered a CSP nonce
     * with Vite (`Vite::useCspNonce()`), so a `style-src` policy that names that nonce accepts the
     * generated links and the inlined page stylesheet; an empty string otherwise.
     */
    public function nonceAttribute(): string
    {
        $nonce = $this->vite->cspNonce();

        return $nonce === null || $nonce === '' ? '' : ' nonce="'.e($nonce).'"';
    }

    /**
     * The size of a generated stylesheet for the debug header, or 0 when it cannot be stat'ed.
     * Only stat'ed in debug mode, since the header is the only reader.
     */
    private function bytes(string $file): int
    {
        if (! $this->debug()) {
            return 0;
        }

        $path = $this->store->directory().'/'.$file;
        clearstatcache(true, $path);
        $size = @filesize($path);

        return $size === false ? 0 : $size;
    }

    /**
     * Logs $diagnostic the first time this process sees it. Messages can vary per request, so past
     * {@see self::LOG_KEY_LIMIT} distinct diagnostics it says so once and stays quiet.
     *
     * @param  array<string, string|list<string>>  $context
     */
    private function logOnce(Diagnostic $diagnostic, array $context, ?string $key = null): void
    {
        // A caller passes its own key when the message varies per request (BW6003 interpolates a
        // count) but the condition should still be reported once per process.
        $key ??= $diagnostic->code.'|'.substr($diagnostic->message, 0, self::LOG_KEY_LENGTH);

        if (isset($this->logged[$key])) {
            return;
        }

        if (count($this->logged) >= self::LOG_KEY_LIMIT) {
            if ($this->logCapReported) {
                return;
            }

            $this->logCapReported = true;
            $this->write(
                'BladeWind '.Codes::PAGE_STYLES_FALLBACK.': page styles have logged '.self::LOG_KEY_LIMIT.' distinct diagnostics in this process and will stay silent from here.',
                ['code' => $diagnostic->code, 'message' => $diagnostic->message],
            );

            return;
        }

        $this->logged[$key] = true;
        $this->write("BladeWind {$diagnostic->code}: {$diagnostic->message}", $context);
    }

    /**
     * @param  array<string, string|list<string>>  $context
     */
    private function write(string $message, array $context): void
    {
        try {
            $this->log->warning($message, $context);
        } catch (Throwable) {
            // Logging must never break a response.
        }
    }
}
