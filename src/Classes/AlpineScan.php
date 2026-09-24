<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Classes;

final readonly class AlpineScan
{
    /**
     * @param  list<string>  $tokens
     * @param  list<ConditionalTokens>  $conditions
     * @param  list<string>  $unresolved
     */
    public function __construct(
        public array $tokens,
        public array $conditions,
        public array $unresolved,
        public RuntimeClassification $classification,
    ) {}
}
