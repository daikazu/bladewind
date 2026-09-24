<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Manifest;

enum Resolution: string
{
    case Static = 'static';
    case Declared = 'declared';
    case Unresolved = 'unresolved';
}
