<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Resolution;

enum ResolutionKind: string
{
    case View = 'view';
    case ClassComponent = 'class';
    case Unresolved = 'unresolved';
}
