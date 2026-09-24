<?php

declare(strict_types=1);

use Daikazu\BladeWind\Declarations\ComponentDeclarations;
use Daikazu\BladeWind\Declarations\DeclarationDiagnostics;
use Daikazu\BladeWind\Declarations\Safelist;

function declarations(array $components, array $safelist = []): ComponentDeclarations
{
    config()->set('bladewind.components', $components);
    config()->set('bladewind.safelist', $safelist);
    app()->forgetInstance(ComponentDeclarations::class);
    app()->forgetInstance(Safelist::class);
    app()->forgetInstance(DeclarationDiagnostics::class);

    return app(ComponentDeclarations::class);
}

it('resolves component names and view names to paths', function (): void {
    $declarations = declarations([
        'ui::badge' => ['classes' => ['bg-red-500 bg-green-500', 'bg-blue-500']],
        'pages.about' => ['dynamic' => ['button', 'badge']],
        'forms.input' => ['classes' => ['ring-2']],
    ]);

    expect($declarations->classesFor($this->fixturePath('ui/badge.blade.php')))->toBe(['bg-red-500', 'bg-green-500', 'bg-blue-500'])
        ->and($declarations->dynamicTargetsFor($this->fixturePath('views/pages/about.blade.php')))->toBe(['button', 'badge'])
        ->and($declarations->classesFor($this->fixturePath('views/components/forms/input.blade.php')))->toBe(['ring-2'])
        ->and($declarations->classesFor($this->fixturePath('views/pages/home.blade.php')))->toBe([])
        ->and($declarations->unresolvedKeys())->toBe([]);
});

it('reports keys that resolve to nothing or to a class component', function (): void {
    $declarations = declarations(['nope' => ['classes' => ['a']], 'alert' => ['classes' => ['b']]], ['a b']);

    expect($declarations->unresolvedKeys())->toBe(['nope', 'alert']);

    $codes = array_map(fn ($d) => $d->code.':'.$d->message, app(DeclarationDiagnostics::class)->report());

    expect($codes)->toHaveCount(3)
        ->and($codes[0])->toStartWith('BW2006:Declaration key [nope]')
        ->and($codes[1])->toStartWith('BW2006:Declaration key [alert]')
        ->and($codes[2])->toStartWith('BW2007:Safelist entry [a b]');
});
