<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Manifest;

enum DependencyType: string
{
    case Component = 'component';
    case Include = 'include';
    case IncludeFirst = 'include-first';
    case Each = 'each';
    case Extends = 'extends';
    case LegacyComponent = 'legacy-component';
    case Livewire = 'livewire';
    case DynamicComponent = 'dynamic-component';
}
