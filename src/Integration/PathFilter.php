<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Integration;

final class PathFilter
{
    /**
     * @var list<string>
     */
    private array $roots;

    /**
     * @param  list<string>  $roots
     */
    public function __construct(array $roots)
    {
        $normalised = [];

        foreach ($roots as $root) {
            $real = realpath($root);

            if ($real !== false && is_dir($real)) {
                $normalised[] = $real;
            }
        }

        $this->roots = array_values(array_unique($normalised));
    }

    /**
     * @return list<string>
     */
    public function roots(): array
    {
        return $this->roots;
    }

    public function rootFor(string $path): ?string
    {
        if ($path === '') {
            return null;
        }

        $real = realpath($path);

        if ($real === false) {
            return null;
        }

        foreach ($this->roots as $root) {
            if (str_starts_with($real, $root.DIRECTORY_SEPARATOR)) {
                return $root;
            }
        }

        return null;
    }

    public function contains(string $path): bool
    {
        return $this->rootFor($path) !== null;
    }
}
