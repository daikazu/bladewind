<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Parsing;

use Daikazu\BladeWind\Parsing\Nodes\Template;

interface SourceParser
{
    public function parse(string $source): Template;
}
