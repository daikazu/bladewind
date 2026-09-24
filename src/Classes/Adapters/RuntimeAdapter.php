<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Classes\Adapters;

use Daikazu\BladeWind\Classes\RuntimeClasses;
use Daikazu\BladeWind\Parsing\Nodes\ComponentTag;
use Daikazu\BladeWind\Parsing\Nodes\Element;

interface RuntimeAdapter
{
    /**
     * @return list<RuntimeClasses>
     */
    public function attributes(Element|ComponentTag $node): array;
}
