<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Pages;

use Daikazu\BladeWind\BladeWind;

/**
 * A page's class set: every token its HTML wears, unioned with the analysed
 * inventories of the views that rendered it and their static dependency closure.
 */
final readonly class ClassSet
{
    /**
     * @param  list<string>  $tokens  sorted, unique
     * @param  int  $unanalysed  rendered paths with no manifest entry
     * @param  int  $analysed  entries visited, rendered paths plus their dependency closure
     * @param  list<string>  $unanalysedPaths  the rendered paths behind $unanalysed, for the debug panel to explain
     */
    public function __construct(
        public array $tokens,
        public int $unanalysed,
        public int $analysed,
        public array $unanalysedPaths = [],
    ) {}

    /**
     * A content-addressed identifier for this set against one stylesheet: two pages with the same
     * tokens, built against the same stylesheet and BladeWind version, share a cached page CSS file.
     */
    public function hash(string $stylesheetHash): string
    {
        return hash('xxh128', implode("\n", $this->tokens)."\n".$stylesheetHash."\n".BladeWind::VERSION);
    }
}
