<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Integration;

use Daikazu\BladeWind\Analysis\ViewAnalyzer;
use Daikazu\BladeWind\Diagnostics\Codes;
use Daikazu\BladeWind\Discovery\ViewLocator;
use Daikazu\BladeWind\Manifest\ManifestStore;
use Illuminate\Contracts\Container\Container;
use Illuminate\View\Compilers\BladeCompiler;
use Livewire\Blaze\BlazeManager;
use Psr\Log\LoggerInterface;
use Throwable;

final class CompileObserver
{
    public function __construct(private Container $container) {}

    public function register(BladeCompiler $compiler): void
    {
        $compiler->prepareStringsForCompilationUsing(fn (string $source): string => $this->observe($compiler, $source));
    }

    /**
     * Analyse the file being compiled and record its manifest entry. Fails open and never changes the source.
     */
    public function observe(BladeCompiler $compiler, string $source): string
    {
        $path = '';

        try {
            $path = (string) $compiler->getPath();

            if ($path === '' || ! is_file($path) || $this->isFolding()) {
                return $source;
            }

            $file = $this->container->make(ViewLocator::class)->describe($path);

            if ($file === null) {
                return $source;
            }

            // BladeCompiler::getPath() is set by compile() and never reset, so a direct
            // compileString() (Laravel's debug exception renderer does this) would be recorded
            // under the last-compiled path. Only analyse source that matches the file on disk.
            if (hash('xxh128', $source) !== hash_file('xxh128', $path)) {
                return $source;
            }

            $entry = $this->container->make(ViewAnalyzer::class)->analyze($file, $source);

            $this->container->make(ManifestStore::class)->put($entry);
        } catch (Throwable $exception) {
            $diagnostic = Codes::make(Codes::ANALYSIS_FAILED, ['message' => $exception->getMessage()]);

            try {
                $this->container->make(LoggerInterface::class)->warning(
                    "BladeWind {$diagnostic->code}: {$diagnostic->message}",
                    ['path' => $path, 'message' => $exception->getMessage(), 'exception' => $exception::class],
                );
            } catch (Throwable) {
                // Logging must never break compilation.
            }
        }

        return $source;
    }

    private function isFolding(): bool
    {
        if (! $this->container->bound('blaze')) {
            return false;
        }

        /** @var BlazeManager $blaze */
        $blaze = $this->container->make('blaze');

        return $blaze->isFolding();
    }
}
