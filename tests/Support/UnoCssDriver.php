<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Tests\Support;

use Daikazu\BladeWind\Pages\Drivers\FlatDriver;

/**
 * What an application's own driver looks like: a name and a signature on top of {@see FlatDriver}.
 */
final class UnoCssDriver extends FlatDriver
{
    public function name(): string
    {
        return 'unocss';
    }

    public function detect(string $css): bool
    {
        return str_contains($css, '--un-');
    }

    public function runtimeTokens(): array
    {
        return ['uno-runtime'];
    }
}
