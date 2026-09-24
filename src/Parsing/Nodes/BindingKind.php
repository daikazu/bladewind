<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Parsing\Nodes;

enum BindingKind: string
{
    case Static = 'static';
    case Bound = 'bound';
    case Escaped = 'escaped';
    case Shorthand = 'shorthand';
    case BladeConstruct = 'blade-construct';
}
