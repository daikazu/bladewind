<?php

declare(strict_types=1);

use Daikazu\BladeWind\Stylesheets\StylesheetIndex;
use Illuminate\Filesystem\Filesystem;

it('resolves the stylesheet through the Vite manifest and hashes it', function (): void {
    $index = fixtureStylesheetIndex();
    $sheets = $index->stylesheets();

    expect($sheets)->toHaveCount(1)
        ->and($sheets[0]->name)->toBe('resources/css/app.css')
        ->and($sheets[0]->file)->toBe('assets/app-test.css')
        ->and($sheets[0]->found)->toBeTrue()
        ->and($sheets[0]->hash)->toBe(hash('xxh128', (string) file_get_contents($this->fixturePath('public/build/assets/app-test.css'))))
        ->and($sheets[0]->bytes)->toBe(strlen((string) file_get_contents($this->fixturePath('public/build/assets/app-test.css'))))
        ->and($index->hasStylesheet())->toBeTrue()
        ->and($index->contents())->toHaveKey('resources/css/app.css')
        ->and($index->diagnostics())->toBe([])
        ->and(array_keys($sheets[0]->toArray()))->toBe(['name', 'file', 'hash', 'bytes', 'found']);
});

it('reports BW3001 and stays empty when the manifest or entry is missing', function (): void {
    $missing = fixtureStylesheetIndex(sys_get_temp_dir().'/bladewind-nowhere-'.bin2hex(random_bytes(3)));

    expect($missing->stylesheets()[0]->found)->toBeFalse()
        ->and($missing->stylesheets()[0]->hash)->toBeNull()
        ->and($missing->hasStylesheet())->toBeFalse()
        ->and($missing->contents())->toBe([])
        ->and($missing->diagnostics())->toHaveCount(1)
        ->and($missing->diagnostics()[0]->code)->toBe('BW3001');

    $unknown = new StylesheetIndex(['resources/css/missing.css'], $this->fixturePath('public'), new Filesystem);

    expect($unknown->diagnostics()[0]->message)->toContain('resources/css/missing.css')->toContain('manifest.json')
        ->and($unknown->stylesheets()[0]->file)->toBeNull();
});

it('reports BW3002 and stays not found for a manifest entry that is not a stylesheet', function (): void {
    $files = new Filesystem;
    $scratch = sys_get_temp_dir().'/bladewind-script-'.bin2hex(random_bytes(3));
    $files->ensureDirectoryExists($scratch.'/build/assets');
    $files->put($scratch.'/build/assets/app-x.js', 'console.log(1)');
    $files->put($scratch.'/build/manifest.json', json_encode([
        'resources/js/app.js' => ['file' => 'assets/app-x.js', 'src' => 'resources/js/app.js', 'isEntry' => true, 'css' => ['assets/app-y.css']],
    ], JSON_THROW_ON_ERROR));

    try {
        $index = new StylesheetIndex(['resources/js/app.js'], $scratch, new Filesystem);

        expect($index->stylesheets()[0]->found)->toBeFalse()
            ->and($index->stylesheets()[0]->file)->toBe('assets/app-x.js')
            ->and($index->hasStylesheet())->toBeFalse()
            ->and($index->diagnostics())->toHaveCount(1)
            ->and($index->diagnostics()[0]->code)->toBe('BW3002')
            ->and($index->diagnostics()[0]->message)->toContain('resources/js/app.js')->toContain('assets/app-x.js');
    } finally {
        $files->deleteDirectory($scratch);
    }
});

it('resolves the manifest from the configured build directory, as Vite::useBuildDirectory() would', function (): void {
    $files = new Filesystem;
    $scratch = sys_get_temp_dir().'/bladewind-builddir-'.bin2hex(random_bytes(3));
    $files->ensureDirectoryExists($scratch.'/dist/assets');
    $files->put($scratch.'/dist/assets/app-a.css', '.a{x:y}');
    $files->put($scratch.'/dist/manifest.json', json_encode(['resources/css/app.css' => ['file' => 'assets/app-a.css', 'src' => 'resources/css/app.css', 'isEntry' => true]], JSON_THROW_ON_ERROR));
    config()->set('bladewind.build_directory', 'dist');

    try {
        $index = StylesheetIndex::fromConfig(config(), $scratch, $files);

        expect($index->stylesheets()[0]->found)->toBeTrue()
            ->and($index->contents())->toBe(['resources/css/app.css' => '.a{x:y}']);
    } finally {
        $files->deleteDirectory($scratch);
    }
});

it('deduplicates configured stylesheet names', function (): void {
    config()->set('bladewind.stylesheets', ['resources/css/app.css', 'resources/css/app.css']);
    $index = StylesheetIndex::fromConfig(config(), $this->fixturePath('public'), new Filesystem);

    expect($index->stylesheets())->toHaveCount(1);
});

it('reports BW3001 and stays not found when the resolved stylesheet is unreadable', function (): void {
    if (function_exists('posix_getuid') && posix_getuid() === 0) {
        $this->markTestSkipped('File permissions have no effect when running as root.');
    }

    $files = new Filesystem;
    $scratch = sys_get_temp_dir().'/bladewind-unreadable-'.bin2hex(random_bytes(3));
    $stylesheet = $scratch.'/build/assets/app-test.css';
    $files->ensureDirectoryExists($scratch.'/build/assets');
    $files->copy($this->fixturePath('public/build/manifest.json'), $scratch.'/build/manifest.json');
    $files->copy($this->fixturePath('public/build/assets/app-test.css'), $stylesheet);
    chmod($stylesheet, 0000);

    try {
        $index = new StylesheetIndex(['resources/css/app.css'], $scratch, new Filesystem);

        expect($index->stylesheets()[0]->found)->toBeFalse()
            ->and($index->diagnostics())->toHaveCount(1)
            ->and($index->diagnostics()[0]->code)->toBe('BW3001')
            ->and($index->diagnostics()[0]->message)->toContain($stylesheet);
    } finally {
        chmod($stylesheet, 0644);
        $files->deleteDirectory($scratch);
    }
});
