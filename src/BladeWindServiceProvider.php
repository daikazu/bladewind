<?php

declare(strict_types=1);

namespace Daikazu\BladeWind;

use Daikazu\BladeWind\Analysis\ClassAnalyzer;
use Daikazu\BladeWind\Analysis\ClassIndex;
use Daikazu\BladeWind\Analysis\DependencyExtractor;
use Daikazu\BladeWind\Analysis\ViewAnalyzer;
use Daikazu\BladeWind\Classes\Adapters\AlpineAdapter;
use Daikazu\BladeWind\Classes\Adapters\LivewireAdapter;
use Daikazu\BladeWind\Classes\Adapters\RuntimeAdapter;
use Daikazu\BladeWind\Console\AnalyzeCommand;
use Daikazu\BladeWind\Console\ClearCommand;
use Daikazu\BladeWind\Console\InspectCommand;
use Daikazu\BladeWind\Declarations\ComponentDeclarations;
use Daikazu\BladeWind\Declarations\DeclarationDiagnostics;
use Daikazu\BladeWind\Declarations\Safelist;
use Daikazu\BladeWind\Discovery\ViewLocator;
use Daikazu\BladeWind\Graph\GraphBuilder;
use Daikazu\BladeWind\Graph\GraphStore;
use Daikazu\BladeWind\Http\InjectPageStyles;
use Daikazu\BladeWind\Http\ServeAsset;
use Daikazu\BladeWind\Integration\CompatHash;
use Daikazu\BladeWind\Integration\CompileObserver;
use Daikazu\BladeWind\Integration\InstalledVersions;
use Daikazu\BladeWind\Integration\PathFilter;
use Daikazu\BladeWind\Manifest\ManifestStore;
use Daikazu\BladeWind\Manifest\ReportBuilder;
use Daikazu\BladeWind\Pages\ClassSetBuilder;
use Daikazu\BladeWind\Pages\Drivers\Bootstrap5Driver;
use Daikazu\BladeWind\Pages\Drivers\BulmaDriver;
use Daikazu\BladeWind\Pages\Drivers\CssFrameworkDriver;
use Daikazu\BladeWind\Pages\Drivers\DriverSelector;
use Daikazu\BladeWind\Pages\Drivers\FlatDriver;
use Daikazu\BladeWind\Pages\Drivers\FoundationDriver;
use Daikazu\BladeWind\Pages\Drivers\TachyonsDriver;
use Daikazu\BladeWind\Pages\Drivers\Tailwind3Driver;
use Daikazu\BladeWind\Pages\Drivers\Tailwind4Driver;
use Daikazu\BladeWind\Pages\FlatStylesheetSplitter;
use Daikazu\BladeWind\Pages\PageCssBuilder;
use Daikazu\BladeWind\Pages\PageStyles;
use Daikazu\BladeWind\Pages\PageStyleStore;
use Daikazu\BladeWind\Pages\RenderedViews;
use Daikazu\BladeWind\Pages\StylesDirective;
use Daikazu\BladeWind\Pages\StylesheetSplitter;
use Daikazu\BladeWind\Pages\SupportCssBuilder;
use Daikazu\BladeWind\Parsing\ForteSourceParser;
use Daikazu\BladeWind\Parsing\SourceParser;
use Daikazu\BladeWind\Resolution\ComponentResolver;
use Daikazu\BladeWind\Resolution\ViewResolver;
use Daikazu\BladeWind\Stylesheets\StylesheetIndex;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Vite;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\View;
use Psr\Log\LoggerInterface;

final class BladeWindServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/bladewind.php', 'bladewind');

        $this->app->singleton(SourceParser::class, ForteSourceParser::class);

        $this->app->singleton(PathFilter::class, function (Application $app): PathFilter {
            /** @var list<string> $paths */
            $paths = $app->make('config')->get('bladewind.paths', []);

            return new PathFilter($paths);
        });

        $this->app->singleton(ViewLocator::class, function (Application $app): ViewLocator {
            return new ViewLocator(
                $app->make(Filesystem::class),
                $app->basePath(),
                $app->make(PathFilter::class),
                function () use ($app): array {
                    /** @var BladeCompiler $blade */
                    $blade = $app->make('blade.compiler');

                    $paths = [];

                    foreach ($blade->getAnonymousComponentPaths() as $entry) {
                        if (is_array($entry) && is_string($entry['path'] ?? null)) {
                            $paths[] = $entry['path'];
                        }
                    }

                    return $paths;
                },
                // Livewire compiles component views into `{view.compiled}/livewire/views`. Read from
                // config rather than from its compiler so nothing resolves Livewire here, and read it
                // per call because parallel testing rewrites `view.compiled` after boot.
                static function () use ($app): ?string {
                    $compiled = $app->make('config')->get('view.compiled');

                    return is_string($compiled) && $compiled !== '' ? rtrim($compiled, '/\\').'/livewire/views' : null;
                },
            );
        });

        $this->app->singleton(ViewResolver::class, fn (Application $app): ViewResolver => new ViewResolver($app->make('view')));
        $this->app->singleton(ComponentResolver::class, fn (Application $app): ComponentResolver => new ComponentResolver($app, $app->make(ViewResolver::class)));
        $this->app->singleton(Safelist::class, fn (Application $app): Safelist => Safelist::fromConfig($app->make('config')));
        $this->app->singleton(ComponentDeclarations::class, fn (Application $app): ComponentDeclarations => new ComponentDeclarations(
            $app->make('config'),
            $app->make(ComponentResolver::class),
            $app->make(ViewResolver::class),
        ));
        $this->app->singleton(DeclarationDiagnostics::class, fn (Application $app): DeclarationDiagnostics => new DeclarationDiagnostics(
            $app->make(Safelist::class),
            $app->make(ComponentDeclarations::class),
        ));
        $this->app->singleton(DependencyExtractor::class, fn (Application $app): DependencyExtractor => new DependencyExtractor($app->make(ComponentResolver::class), $app->make(ViewResolver::class)));

        $this->app->singleton(LivewireAdapter::class);
        $this->app->singleton(AlpineAdapter::class);
        $this->app->tag([LivewireAdapter::class, AlpineAdapter::class], 'bladewind.runtime-adapters');
        $this->app->singleton(ClassAnalyzer::class, function (Application $app): ClassAnalyzer {
            /** @var iterable<RuntimeAdapter> $tagged */
            $tagged = $app->tagged('bladewind.runtime-adapters');

            /** @var list<RuntimeAdapter> $adapters */
            $adapters = iterator_to_array($tagged, false);

            return new ClassAnalyzer($adapters);
        });
        $this->app->singleton(ClassIndex::class);

        $this->app->singleton(InstalledVersions::class);
        $this->app->singleton(CompatHash::class, fn (Application $app): CompatHash => new CompatHash($app->make(InstalledVersions::class), $app->make('config')));
        $this->app->singleton(ViewAnalyzer::class, fn (Application $app): ViewAnalyzer => new ViewAnalyzer(
            $app->make(SourceParser::class),
            $app->make(DependencyExtractor::class),
            $app->make(CompatHash::class),
            $app->make(ClassAnalyzer::class),
            $app->make(ClassIndex::class),
            $app->make(ComponentDeclarations::class),
            $app->make(Safelist::class),
        ));

        $this->app->singleton(ManifestStore::class, function (Application $app): ManifestStore {
            /** @var Repository $config */
            $config = $app->make('config');
            /** @var string|null $cachePath */
            $cachePath = $config->get('bladewind.cache_path');
            /** @var string $compiledPath */
            $compiledPath = $config->get('view.compiled');
            $root = $cachePath ?: $compiledPath.'/bladewind';

            return new ManifestStore($app->make(Filesystem::class), $root, $app->make(CompatHash::class));
        });
        $this->app->singleton(ReportBuilder::class, fn (Application $app): ReportBuilder => new ReportBuilder($app->make(CompatHash::class)));
        $this->app->singleton(GraphBuilder::class);
        $this->app->singleton(GraphStore::class, fn (Application $app): GraphStore => new GraphStore(
            $app->make(Filesystem::class),
            $app->make(ManifestStore::class),
            $app->make(CompatHash::class),
            $app->make(GraphBuilder::class),
        ));

        $this->app->singleton(StylesheetIndex::class, fn (Application $app): StylesheetIndex => StylesheetIndex::fromConfig(
            $app->make('config'),
            $app->publicPath(),
            $app->make(Filesystem::class),
        ));
        $this->app->singleton(StylesDirective::class, fn (Application $app): StylesDirective => new StylesDirective($app->make(PageStyles::class)));
        $this->app->singleton(PageStyleStore::class, function (Application $app): PageStyleStore {
            $relative = $app->make('config')->get('bladewind.assets.path', 'bladewind');

            $base = $app->make('config')->get('bladewind.assets.url');

            return new PageStyleStore(
                $app->make(Filesystem::class),
                $app->publicPath(),
                is_string($relative) ? $relative : 'bladewind',
                is_string($base) && $base !== '' ? $base : null,
            );
        });
        $this->app->singleton(RenderedViews::class);
        // A rendered view whose manifest entry is missing or stale is analysed on the spot, so a
        // package upgrade (which moves the compatibility hash) needs no `view:clear`. The analyser
        // and logger are lazy closures because a warm request needs neither.
        $this->app->singleton(ClassSetBuilder::class, fn (Application $app): ClassSetBuilder => new ClassSetBuilder(
            $app->make(ManifestStore::class),
            $app->make(ViewLocator::class),
            static fn (): ViewAnalyzer => $app->make(ViewAnalyzer::class),
            $app->make(Filesystem::class),
            static fn (): LoggerInterface => $app->make(LoggerInterface::class),
        ));
        $this->app->singleton(StylesheetSplitter::class);
        // One driver per CSS framework whose output the pipeline can take apart, tagged in
        // detection order: `bladewind.framework => 'auto'` tries each signature in turn.
        $this->app->singleton(Tailwind4Driver::class, fn (Application $app): Tailwind4Driver => new Tailwind4Driver($app->make(StylesheetSplitter::class)));
        $this->app->singleton(FlatStylesheetSplitter::class);
        $this->app->singleton(Tailwind3Driver::class, fn (Application $app): Tailwind3Driver => new Tailwind3Driver($app->make(FlatStylesheetSplitter::class)));
        $this->app->singleton(TachyonsDriver::class, fn (Application $app): TachyonsDriver => new TachyonsDriver($app->make(FlatStylesheetSplitter::class)));
        $this->app->singleton(Bootstrap5Driver::class, fn (Application $app): Bootstrap5Driver => new Bootstrap5Driver($app->make(FlatStylesheetSplitter::class)));
        $this->app->singleton(BulmaDriver::class, fn (Application $app): BulmaDriver => new BulmaDriver($app->make(FlatStylesheetSplitter::class)));
        $this->app->singleton(FoundationDriver::class, fn (Application $app): FoundationDriver => new FoundationDriver($app->make(FlatStylesheetSplitter::class)));
        $this->app->tag([Tailwind4Driver::class, Tailwind3Driver::class, TachyonsDriver::class, Bootstrap5Driver::class, BulmaDriver::class, FoundationDriver::class], 'bladewind.css-drivers');
        // An application's own drivers (`bladewind.drivers`) come first, so under `auto` one of
        // them can claim a stylesheet before a built-in does and a name it shares with a built-in
        // resolves to it.
        $this->app->singleton(DriverSelector::class, function (Application $app): DriverSelector {
            /** @var iterable<CssFrameworkDriver> $tagged */
            $tagged = $app->tagged('bladewind.css-drivers');

            /** @var list<CssFrameworkDriver> $drivers */
            $drivers = [...$this->configuredDrivers($app), ...iterator_to_array($tagged, false)];

            return new DriverSelector($drivers, $app->make('config'));
        });
        $this->app->singleton(PageCssBuilder::class);
        // A singleton for its memo: it answers what the root file carries per split stylesheet, and
        // {@see PageStyles} holds one split for as long as the compiled stylesheet's hash holds.
        $this->app->singleton(SupportCssBuilder::class);
        $this->app->singleton(PageStyles::class, fn (Application $app): PageStyles => new PageStyles(
            $app->make('config'),
            $app->make(Vite::class),
            $app->make(StylesheetIndex::class),
            $app->make(DriverSelector::class),
            $app->make(PageCssBuilder::class),
            $app->make(ClassSetBuilder::class),
            $app->make(PageStyleStore::class),
            $app->make(LoggerInterface::class),
            $app->make(SupportCssBuilder::class),
        ));
        // Bound, not a singleton, so it always uses the PageStyles the container currently holds.
        // PageStyles is a closure so a failure building it is caught by the middleware (the page
        // keeps the directive's own links) instead of a 500 while the kernel constructs it.
        $this->app->bind(InjectPageStyles::class, fn (Application $app): InjectPageStyles => new InjectPageStyles(
            $app->make(RenderedViews::class),
            static fn (): PageStyles => $app->make(PageStyles::class),
            $app->make('config'),
        ));

        $this->app->singleton(CompileObserver::class, fn (Application $app): CompileObserver => new CompileObserver($app));

        // Booting callbacks fire before any provider's boot(), which puts this observer ahead of
        // Livewire's island rewriter and Forte's precompiler. A provider registered after boot
        // would never see that callback fire, so attach immediately in that case.
        if ($this->app instanceof \Illuminate\Foundation\Application && $this->app->isBooted()) {
            $this->registerCompileObserver();

            return;
        }

        $this->app->booting(function (): void {
            $this->registerCompileObserver();
        });
    }

    /**
     * The drivers `bladewind.drivers` names, resolved through the container so a driver extending
     * {@see FlatDriver} gets its splitter without wiring. An entry that is not a driver throws at
     * boot ({@see self::validateConfiguration()}).
     *
     * @return list<CssFrameworkDriver>
     */
    private function configuredDrivers(Application $app): array
    {
        $configured = $app->make('config')->get('bladewind.drivers', []);
        $drivers = [];

        foreach (is_array($configured) ? $configured : [] as $entry) {
            $driver = is_string($entry) ? $app->make($entry) : $entry;

            if (! $driver instanceof CssFrameworkDriver) {
                throw new \InvalidArgumentException(sprintf('bladewind.drivers entry [%s] is not a %s.', is_string($entry) ? $entry : get_debug_type($entry), CssFrameworkDriver::class));
            }

            $drivers[] = $driver;
        }

        return $drivers;
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/bladewind.php' => config_path('bladewind.php'),
        ], 'bladewind-config');

        if ($this->app->runningInConsole()) {
            $this->commands([AnalyzeCommand::class, ClearCommand::class, InspectCommand::class]);
        }

        Blade::directive('bladewindStyles', static fn (): string => '<?php echo app(\\Daikazu\\BladeWind\\Pages\\StylesDirective::class)->render(); ?>');

        $this->validateConfiguration();
        $this->registerRenderedViewsComposer();
        $this->registerPageStylesMiddleware();
        $this->registerAssetRoute();
    }

    /**
     * The route under `assets.path` that serves a generated file the web server did not find
     * ({@see ServeAsset}) and the navigate script. Registered only when page styles are on and
     * the application serves HTTP; returns whether it was. Public so a test can call it directly.
     */
    public function registerAssetRoute(): bool
    {
        $config = $this->app->make('config');

        if (! (bool) $config->get('bladewind.enabled', true) || ! (bool) $config->get('bladewind.pages.enabled', true) || ! $this->app->bound(Kernel::class)) {
            return false;
        }

        $relative = $config->get('bladewind.assets.path', 'bladewind');
        $relative = trim(str_replace('\\', '/', is_string($relative) ? $relative : 'bladewind'), '/');

        Route::get($relative.'/{file}', ServeAsset::class)
            ->where('file', ServeAsset::PATTERN)
            ->name('bladewind.asset');

        return true;
    }

    /**
     * Refuses unusable configuration at boot with a message naming the key, the way `@vite`
     * refuses a missing manifest: a `bladewind.drivers` entry that is not a driver (or whose
     * constructor throws), or an `assets.path` that is empty or climbs out of the public directory.
     * Otherwise these would surface as a 500 on every request, outside the fallback's reach.
     * Touches neither the filesystem nor the stylesheet. Public so a test can call it directly.
     *
     * @throws \InvalidArgumentException
     */
    public function validateConfiguration(): void
    {
        if (! (bool) $this->app->make('config')->get('bladewind.enabled', true)) {
            return;
        }

        $this->app->make(DriverSelector::class);
        $this->app->make(PageStyleStore::class);
    }

    /**
     * Appends {@see InjectPageStyles} to the global middleware stack, the way Livewire injects its
     * own assets, so no application configuration is needed. A console-only application has no
     * HTTP kernel bound and needs no middleware. Public so a test can call it directly.
     */
    public function registerPageStylesMiddleware(): void
    {
        if (! (bool) $this->app->make('config')->get('bladewind.enabled', true) || ! $this->app->bound(Kernel::class)) {
            return;
        }

        $this->pushPageStylesMiddleware($this->app->make(Kernel::class));
    }

    /**
     * `pushMiddleware()` belongs to the framework's HTTP kernel rather than to the contract, so an
     * application that bound something else of its own keeps its stack untouched.
     */
    private function pushPageStylesMiddleware(Kernel $kernel): void
    {
        if ($kernel instanceof HttpKernel) {
            $kernel->pushMiddleware(InjectPageStyles::class);
        }
    }

    public function registerCompileObserver(): void
    {
        if (! (bool) $this->app->make('config')->get('bladewind.enabled', true)) {
            return;
        }

        /** @var BladeCompiler $compiler */
        $compiler = $this->app->make('blade.compiler');

        $this->app->make(CompileObserver::class)->register($compiler);
    }

    /**
     * Records every rendered view's path onto {@see RenderedViews} so {@see ClassSetBuilder}
     * can build a page's class set from them. Public so a test can call it directly.
     */
    public function registerRenderedViewsComposer(): void
    {
        $config = $this->app->make('config');

        if (! (bool) $config->get('bladewind.enabled', true) || ! (bool) $config->get('bladewind.pages.enabled', true)) {
            return;
        }

        // Type against the contract: another engine's View would otherwise TypeError mid-render.
        // Only `Illuminate\View\View` has a path to record.
        $this->app->make('view')->composer('*', function (ViewContract $view): void {
            if (! $view instanceof View) {
                return;
            }

            $this->app->make(RenderedViews::class)->record((string) $view->getPath());
        });
    }
}
