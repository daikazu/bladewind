<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Discovery;

final readonly class SourceFile
{
    public function __construct(
        public string $path,
        public string $relativePath,
        public string $name,
        public ViewKind $kind,
    ) {}
}
