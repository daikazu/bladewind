<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Tests;

use Daikazu\BladeWind\BladeWindServiceProvider;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Blade;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected $enablesPackageDiscoveries = true;

    protected string $compiledPath = '';

    protected function setUp(): void
    {
        parent::setUp();

        (new Filesystem)->ensureDirectoryExists($this->compiledPath);

        Blade::anonymousComponentPath($this->fixturePath('ui'), 'ui');
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->compiledPath);

        foreach (['public', 'public-tailwind', 'public-tailwind3', 'public-tachyons', 'public-bootstrap5', 'public-bulma', 'public-foundation'] as $public) {
            (new Filesystem)->deleteDirectory($this->fixturePath($public.'/'.static::assetsPath()));
        }

        parent::tearDown();
    }

    /**
     * Where generated stylesheets are written under the public path. Each parallel worker gets its
     * own directory, since a test's tearDown() deletes it.
     */
    protected static function assetsPath(): string
    {
        $token = getenv('TEST_TOKEN');

        return 'build/bladewind-test'.(is_string($token) && $token !== '' ? '-'.$token : '');
    }

    /**
     * @param  Application  $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [BladeWindServiceProvider::class];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $this->compiledPath = sys_get_temp_dir().'/bladewind-tests-'.bin2hex(random_bytes(6));

        $app['config']->set('view.paths', [$this->fixturePath('views')]);
        $app['config']->set('view.compiled', $this->compiledPath);
        $app['config']->set('bladewind.paths', [$this->fixturePath('views'), $this->fixturePath('ui')]);
        $app['config']->set('bladewind.cache_path', null);
        $app->usePublicPath($this->fixturePath('public'));
        $app['config']->set('bladewind.stylesheets', ['resources/css/app.css']);
        $app['config']->set('bladewind.assets.path', static::assetsPath());
    }

    protected function fixturePath(string $relative = ''): string
    {
        $base = realpath(__DIR__.'/fixtures');

        if ($base === false) {
            throw new \RuntimeException('Fixture directory is missing.');
        }

        return $relative === '' ? $base : $base.'/'.ltrim($relative, '/');
    }
}
