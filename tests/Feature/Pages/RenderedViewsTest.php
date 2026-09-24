<?php

declare(strict_types=1);

use Daikazu\BladeWind\BladeWindServiceProvider;
use Daikazu\BladeWind\Pages\RenderedViews;

it('records the paths of every rendered view, in first-seen order', function (): void {
    view('pages.compress')->render();

    expect(app(RenderedViews::class)->paths())->toBe([
        $this->fixturePath('views/pages/compress.blade.php'),
        $this->fixturePath('views/components/tile.blade.php'),
    ]);
});

it('empties on reset and records again on the next render', function (): void {
    view('pages.compress')->render();
    app(RenderedViews::class)->reset();

    expect(app(RenderedViews::class)->paths())->toBe([]);

    view('pages.compress')->render();

    expect(app(RenderedViews::class)->paths())->toBe([
        $this->fixturePath('views/pages/compress.blade.php'),
        $this->fixturePath('views/components/tile.blade.php'),
    ]);
});

it('does not duplicate a path rendered more than once', function (): void {
    view('pages.compress')->render();
    view('pages.compress')->render();

    $paths = app(RenderedViews::class)->paths();

    expect($paths)->toBe(array_values(array_unique($paths)))
        ->and(array_count_values($paths)[$this->fixturePath('views/pages/compress.blade.php')])->toBe(1);
});

it('does not register the composer when bladewind is disabled', function (): void {
    config()->set('bladewind.enabled', false);
    $before = count(app('events')->getListeners('composing: *'));

    (new BladeWindServiceProvider($this->app))->registerRenderedViewsComposer();

    expect(count(app('events')->getListeners('composing: *')))->toBe($before);
});

it('does not register the composer when pages are disabled', function (): void {
    config()->set('bladewind.pages.enabled', false);
    $before = count(app('events')->getListeners('composing: *'));

    (new BladeWindServiceProvider($this->app))->registerRenderedViewsComposer();

    expect(count(app('events')->getListeners('composing: *')))->toBe($before);
});
