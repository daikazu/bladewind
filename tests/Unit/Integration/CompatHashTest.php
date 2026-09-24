<?php

declare(strict_types=1);

use Daikazu\BladeWind\Integration\CompatHash;
use Daikazu\BladeWind\Integration\InstalledVersions;
use Illuminate\Config\Repository;

function compatHash(array $versions, array $config = []): string
{
    $installed = new class($versions) extends InstalledVersions
    {
        /** @param array<string, string|null> $versions */
        public function __construct(private array $versions) {}

        public function version(string $package): ?string
        {
            return $this->versions[$package] ?? null;
        }
    };

    $repository = new Repository(['bladewind' => array_merge([
        'paths' => ['/app/resources/views'],
    ], $config)]);

    return (new CompatHash($installed, $repository))->current();
}

it('is stable for identical inputs', function (): void {
    $versions = ['laravel/framework' => '13.30.1', 'fortephp/forte' => '1.1.0'];

    expect(compatHash($versions))->toBe(compatHash($versions))
        ->and(compatHash($versions))->toMatch('/^[0-9a-f]{32}$/');
});

it('changes when a version or the paths change', function (): void {
    $base = ['laravel/framework' => '13.30.1', 'fortephp/forte' => '1.1.0'];
    $baseline = compatHash($base);

    expect(compatHash(['laravel/framework' => '13.31.0', 'fortephp/forte' => '1.1.0']))->not->toBe($baseline)
        ->and(compatHash($base + ['livewire/livewire' => '4.4.3']))->not->toBe($baseline)
        ->and(compatHash($base, ['paths' => ['/elsewhere']]))->not->toBe($baseline);
});

it('changes when the safelist or component declarations change', function (): void {
    $base = ['laravel/framework' => '13.30.1', 'fortephp/forte' => '1.1.0'];
    $baseline = compatHash($base);

    expect(compatHash($base, ['safelist' => ['text-*']]))->not->toBe($baseline)
        ->and(compatHash($base, ['components' => ['badge' => ['classes' => ['bg-red-500']]]]))->not->toBe($baseline)
        ->and(compatHash($base, ['safelist' => ['b', 'a']]))->toBe(compatHash($base, ['safelist' => ['a', 'b']]));
});

it('sorts numeric-string safelist entries lexicographically, not numerically', function (): void {
    $base = ['laravel/framework' => '13.30.1', 'fortephp/forte' => '1.1.0'];

    expect(compatHash($base, ['safelist' => ['10', '2']]))->toBe(compatHash($base, ['safelist' => ['2', '10']]))
        ->and(compatHash($base, ['safelist' => ['10', '9']]))->not->toBe(compatHash($base, ['safelist' => ['9', '8']]));
});
