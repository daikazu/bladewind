<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Integration;

use Daikazu\BladeWind\BladeWind;
use Illuminate\Contracts\Config\Repository;

final class CompatHash
{
    private ?string $cached = null;

    public function __construct(
        private InstalledVersions $versions,
        private Repository $config,
    ) {}

    public function current(): string
    {
        if ($this->cached !== null) {
            return $this->cached;
        }

        /** @var list<string> $paths */
        $paths = $this->config->get('bladewind.paths', []);
        $paths = array_map(static fn (string $path): string => realpath($path) ?: $path, $paths);
        sort($paths);

        $payload = [
            'bladewind' => BladeWind::VERSION,
            'components' => self::normalise($this->config->get('bladewind.components', [])),
            'stylesheets' => self::normalise($this->config->get('bladewind.stylesheets', [])),
            'forte' => $this->versions->version('fortephp/forte'),
            'laravel' => $this->versions->version('laravel/framework') ?? $this->versions->version('illuminate/view'),
            'livewire' => $this->versions->version('livewire/livewire'),
            'paths' => $paths,
            'safelist' => self::normalise($this->config->get('bladewind.safelist', [])),
        ];

        ksort($payload);

        return $this->cached = hash('xxh128', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /**
     * Sort arrays recursively so equivalent declarations hash identically:
     * associative arrays by key, lists by value.
     */
    private static function normalise(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $normalised = array_map(static fn (mixed $item): mixed => self::normalise($item), $value);

        if (array_is_list($normalised)) {
            usort($normalised, static fn (mixed $a, mixed $b): int => strcmp(self::sortKey($a), self::sortKey($b)));

            return $normalised;
        }

        ksort($normalised);

        return $normalised;
    }

    /**
     * A canonical, lexicographically comparable string for a normalised list element: the value
     * itself when it is already scalar, or its JSON form otherwise.
     */
    private static function sortKey(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : json_encode($value, JSON_THROW_ON_ERROR);
    }
}
