<?php

declare(strict_types=1);

use App\View\Components\Alert;
use Daikazu\BladeWind\Resolution\ComponentResolver;
use Daikazu\BladeWind\Resolution\ResolutionKind;

it('resolves anonymous components, nested paths, and index files to view paths', function (): void {
    $resolver = app(ComponentResolver::class);

    $card = $resolver->resolve('card');
    expect($card->kind)->toBe(ResolutionKind::View)
        ->and($card->view)->toBe('components.card')
        ->and($card->path)->toBe($this->fixturePath('views/components/card.blade.php'));

    expect($resolver->resolve('forms.input')->path)->toBe($this->fixturePath('views/components/forms/input.blade.php'))
        ->and($resolver->resolve('icon')->path)->toBe($this->fixturePath('views/components/icon/index.blade.php'));
});

it('resolves prefixed anonymous component paths', function (): void {
    $badge = app(ComponentResolver::class)->resolve('ui::badge');

    expect($badge->kind)->toBe(ResolutionKind::View)
        ->and($badge->path)->toBe($this->fixturePath('ui/badge.blade.php'));
});

it('resolves class components to their class without instantiating them', function (): void {
    $alert = app(ComponentResolver::class)->resolve('alert');

    expect($alert->kind)->toBe(ResolutionKind::ClassComponent)
        ->and($alert->class)->toBe(Alert::class)
        ->and($alert->path)->toBeNull();
});

it('reports unknown components as unresolved', function (): void {
    expect(app(ComponentResolver::class)->resolve('does-not-exist')->kind)->toBe(ResolutionKind::Unresolved);
});
