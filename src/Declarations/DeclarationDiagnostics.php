<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Declarations;

use Daikazu\BladeWind\Diagnostics\Codes;
use Daikazu\BladeWind\Diagnostics\Diagnostic;

final class DeclarationDiagnostics
{
    public function __construct(
        private Safelist $safelist,
        private ComponentDeclarations $declarations,
    ) {}

    /**
     * Report-level diagnostics for declarations that could not be applied.
     *
     * @return list<Diagnostic>
     */
    public function report(): array
    {
        $diagnostics = [];

        foreach ($this->declarations->unresolvedKeys() as $key) {
            $diagnostics[] = Codes::make(Codes::DECLARATION_UNRESOLVED, ['key' => $key]);
        }

        foreach ($this->safelist->invalid() as $entry) {
            $diagnostics[] = Codes::make(Codes::SAFELIST_ENTRY_INVALID, ['entry' => $entry]);
        }

        return $diagnostics;
    }
}
