<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Resolution;

enum ArgumentKind: string
{
    case Literal = 'literal';
    case ArrayLiteral = 'array';
    case Expression = 'expression';
}
