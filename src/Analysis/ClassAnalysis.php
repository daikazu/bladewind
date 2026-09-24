<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Analysis;

use Daikazu\BladeWind\Classes\CandidateSet;
use Daikazu\BladeWind\Classes\ClassGroup;
use Daikazu\BladeWind\Classes\RuntimeClasses;
use Daikazu\BladeWind\Classes\UnresolvedConstruct;
use Daikazu\BladeWind\Diagnostics\Diagnostic;

final readonly class ClassAnalysis
{
    /**
     * @param  list<ClassGroup>  $groups
     * @param  list<RuntimeClasses>  $runtime
     * @param  list<CandidateSet>  $candidates
     * @param  list<UnresolvedConstruct>  $unresolved
     * @param  list<Diagnostic>  $diagnostics
     */
    public function __construct(
        public array $groups,
        public array $runtime,
        public array $candidates,
        public array $unresolved,
        public array $diagnostics,
    ) {}

    public static function empty(): self
    {
        return new self([], [], [], [], []);
    }
}
