<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Classes;

enum GroupClassification: string
{
    case Static = 'static';
    case Enumerable = 'enumerable';
    case Mixed = 'mixed';
    case Unresolved = 'unresolved';
}
