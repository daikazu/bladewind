<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Resolution;

use Illuminate\View\Factory;
use Throwable;

final class ViewResolver
{
    public function __construct(private Factory $views) {}

    public function path(string $name): ?string
    {
        try {
            if (! $this->views->exists($name)) {
                return null;
            }

            return $this->views->getFinder()->find($name);
        } catch (Throwable) {
            return null;
        }
    }
}
