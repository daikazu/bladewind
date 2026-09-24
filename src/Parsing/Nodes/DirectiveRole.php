<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Parsing\Nodes;

enum DirectiveRole: string
{
    case Standalone = 'standalone';
    case Opening = 'opening';
    case Intermediate = 'intermediate';
    case Closing = 'closing';
}
