<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Resolution;

final readonly class Resolved
{
    private function __construct(
        public ResolutionKind $kind,
        public ?string $view,
        public ?string $path,
        public ?string $class,
    ) {}

    public static function forView(string $view, ?string $path): self
    {
        return new self(ResolutionKind::View, $view, $path, null);
    }

    public static function forClass(string $class): self
    {
        return new self(ResolutionKind::ClassComponent, null, null, $class);
    }

    public static function unresolved(): self
    {
        return new self(ResolutionKind::Unresolved, null, null, null);
    }
}
