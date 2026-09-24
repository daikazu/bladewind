<?php

declare(strict_types=1);

use Daikazu\BladeWind\Resolution\ViewResolver;

it('resolves an existing view name to its file path', function (): void {
    $resolver = app(ViewResolver::class);

    expect($resolver->path('partials.footer'))->toBe($this->fixturePath('views/partials/footer.blade.php'));
});

it('returns null for a missing view', function (): void {
    expect(app(ViewResolver::class)->path('partials.nope'))->toBeNull();
});

it('returns null for a malformed namespaced name', function (): void {
    expect(app(ViewResolver::class)->path('nope::a::b'))->toBeNull();
});
