<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Analysis;

use Daikazu\BladeWind\Diagnostics\Diagnostic;
use Daikazu\BladeWind\Manifest\Dependency;

final readonly class Extraction
{
    /**
     * @param  list<Dependency>  $dependencies
     * @param  list<Diagnostic>  $diagnostics
     */
    public function __construct(public array $dependencies, public array $diagnostics) {}
}
