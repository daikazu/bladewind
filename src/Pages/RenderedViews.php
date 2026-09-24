<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Pages;

use Daikazu\BladeWind\BladeWindServiceProvider;

/**
 * The request-scoped record of every view rendered so far. The wildcard
 * view composer registered in {@see BladeWindServiceProvider::boot()} calls
 * {@see self::record()} for each view as it composes, giving {@see ClassSetBuilder} the rendered
 * paths a page's class set is built from. Bound as a singleton so every collaborator in one
 * request shares the same accumulated list.
 */
final class RenderedViews
{
    /**
     * @var array<string, true>
     */
    private array $seen = [];

    /**
     * @var list<string>
     */
    private array $ordered = [];

    public function record(string $path): void
    {
        if (isset($this->seen[$path])) {
            return;
        }

        $this->seen[$path] = true;
        $this->ordered[] = $path;
    }

    /**
     * @return list<string> unique paths, in first-seen order
     */
    public function paths(): array
    {
        return $this->ordered;
    }

    public function reset(): void
    {
        $this->seen = [];
        $this->ordered = [];
    }
}
