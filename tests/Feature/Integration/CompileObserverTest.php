<?php

declare(strict_types=1);

use Daikazu\BladeWind\Analysis\ClassAnalyzer;
use Daikazu\BladeWind\Analysis\ClassIndex;
use Daikazu\BladeWind\Analysis\DependencyExtractor;
use Daikazu\BladeWind\Analysis\ViewAnalyzer;
use Daikazu\BladeWind\BladeWindServiceProvider;
use Daikazu\BladeWind\Declarations\ComponentDeclarations;
use Daikazu\BladeWind\Declarations\Safelist;
use Daikazu\BladeWind\Discovery\SourceFile;
use Daikazu\BladeWind\Integration\CompatHash;
use Daikazu\BladeWind\Integration\CompileObserver;
use Daikazu\BladeWind\Manifest\ManifestStore;
use Daikazu\BladeWind\Manifest\ViewEntry;
use Daikazu\BladeWind\Parsing\SourceParser;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;

function forgetCompiledMemo(): void
{
    app('view.engine.resolver')->resolve('blade')->forgetCompiledOrNotExpired();
}

function throwingAnalyzer(): ViewAnalyzer
{
    return new class(app(SourceParser::class), app(DependencyExtractor::class), app(CompatHash::class), app(ClassAnalyzer::class), app(ClassIndex::class), app(ComponentDeclarations::class), app(Safelist::class)) extends ViewAnalyzer
    {
        public function analyze(SourceFile $file, string $source): ViewEntry
        {
            throw new RuntimeException('analysis exploded');
        }
    };
}

it('records an entry when laravel compiles a view and leaves the output unchanged', function (): void {
    $store = app(ManifestStore::class);
    $path = $this->fixturePath('views/simple.blade.php');

    $html = view('simple')->render();

    expect($html)->toContain('<div class="p-4 text-sm">Simple</div>')
        ->and($store->get($path))->not->toBeNull()
        ->and($store->get($path)?->name)->toBe('simple');
});

it('updates the entry when the source changes and does nothing for fresh views', function (): void {
    $store = app(ManifestStore::class);
    $files = new Filesystem;
    $scratch = $this->fixturePath('views/scratch-'.bin2hex(random_bytes(3)).'.blade.php');
    $files->put($scratch, '<p class="a">one</p>');
    $name = basename($scratch, '.blade.php');

    try {
        view($name)->render();
        $first = $store->get($scratch);
        expect($first)->not->toBeNull();

        $entryFile = $store->entryPath($scratch);
        $written = filemtime($entryFile);

        forgetCompiledMemo();
        view($name)->render();
        clearstatcache();
        expect(filemtime($entryFile))->toBe($written);

        $files->put($scratch, '<p class="b">two</p>');
        touch($scratch, time() + 5);
        forgetCompiledMemo();
        view($name)->render();

        expect($store->get($scratch)?->fingerprint->content)->toBe(hash('xxh128', '<p class="b">two</p>'))
            ->and($store->get($scratch)?->fingerprint->content)->not->toBe($first?->fingerprint->content);
    } finally {
        $files->delete($scratch);
    }
});

it('ignores views outside the configured paths', function (): void {
    $outside = sys_get_temp_dir().'/bw-outside-'.bin2hex(random_bytes(3));
    (new Filesystem)->ensureDirectoryExists($outside);
    file_put_contents($outside.'/elsewhere.blade.php', '<i>x</i>');
    View::addLocation($outside);

    try {
        view('elsewhere')->render();

        expect(app(ManifestStore::class)->get($outside.'/elsewhere.blade.php'))->toBeNull();
    } finally {
        (new Filesystem)->deleteDirectory($outside);
    }
});

it('never breaks compilation when analysis throws, and logs a warning', function (): void {
    app()->instance(ViewAnalyzer::class, throwingAnalyzer());

    Log::shouldReceive('warning')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'BW1008')
            && str_contains($context['message'] ?? '', 'analysis exploded'));

    expect(view('simple')->render())->toContain('Simple');
});

it('does not register the observer when bladewind is disabled', function (): void {
    $this->app['config']->set('bladewind.enabled', false);

    $compiler = app('blade.compiler');
    $property = new ReflectionProperty($compiler, 'prepareStringsForCompilationUsing');
    $before = count($property->getValue($compiler));

    (new BladeWindServiceProvider($this->app))->registerCompileObserver();

    expect(count($property->getValue($compiler)))->toBe($before);
});

it('never analyses source compiled under another view\'s sticky compiler path', function (): void {
    $store = app(ManifestStore::class);
    $path = $this->fixturePath('views/simple.blade.php');

    view('simple')->render();

    $entryFile = $store->entryPath($path);
    $contentBefore = file_get_contents($entryFile);
    $mtimeBefore = filemtime($entryFile);
    $countBefore = count($store->all());

    // BladeCompiler::getPath() is sticky: it still reports simple.blade.php's path here,
    // but this source does not match that file's content on disk.
    app('blade.compiler')->compileString('<x-button />');

    clearstatcache();

    expect(file_get_contents($entryFile))->toBe($contentBefore)
        ->and(filemtime($entryFile))->toBe($mtimeBefore)
        ->and(count($store->all()))->toBe($countBefore);
});

it('runs before Livewire\'s island rewriter in the fully booted app', function (): void {
    $compiler = app('blade.compiler');
    $property = new ReflectionProperty($compiler, 'prepareStringsForCompilationUsing');
    $callbacks = $property->getValue($compiler);

    expect($callbacks)->not->toBeEmpty();

    $first = new ReflectionFunction($callbacks[0]);

    expect($first->getClosureScopeClass()?->getName())->toBe(CompileObserver::class);
});

it('attaches the observer immediately when the provider is registered after boot', function (): void {
    $compiler = app('blade.compiler');
    $property = new ReflectionProperty($compiler, 'prepareStringsForCompilationUsing');
    $before = count($property->getValue($compiler));

    expect($this->app->isBooted())->toBeTrue();

    (new BladeWindServiceProvider($this->app))->register();

    expect(count($property->getValue($compiler)))->toBe($before + 1);
});

it('records no entry while Blaze is folding, since the compiler path is the parent\'s', function (): void {
    app()->instance('blaze', new class
    {
        public function isFolding(): bool
        {
            return true;
        }
    });

    $path = $this->fixturePath('views/pages/compress.blade.php');

    expect(view('pages.compress')->render())->toContain('class="flex items-center gap-2 rounded-lg border p-4"')
        ->and(app(ManifestStore::class)->get($path))->toBeNull();
});
