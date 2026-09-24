<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Testing;

use InvalidArgumentException;

/**
 * What {@see AssertsPageStyles::assertPageStyles()} checks one page against, beyond the checks
 * every page gets. Everything is optional: `new PageExpectation` is "served with page styles,
 * every class covered, no diagnostics".
 */
final readonly class PageExpectation
{
    /**
     * @var list<string>
     */
    public array $diagnostics;

    /**
     * @param  list<string>  $reaches  class tokens that must have a rule in root plus page even when the rendered HTML does not carry them
     * @param  list<string>  $absent  class tokens that must have no rule in the page file
     * @param  list<string>  $diagnostics  the BW codes that must be present on the rendered views' entries or in the request log, and the only ones allowed
     * @param  bool  $fallback  true asserts the response is the full-stylesheet fallback and skips the coverage, diagnostics, framework and unanalysed checks
     * @param  int|null  $unanalysed  null leaves the header's unanalysed count unchecked (a page rendering a dynamic component or a Livewire island counts its string-compiled views there without ever falling back); an int must match exactly
     * @param  string|null  $framework  the header's `framework=` label, checked when given
     */
    public function __construct(
        public array $reaches = [],
        public array $absent = [],
        array $diagnostics = [],
        public bool $fallback = false,
        public ?int $unanalysed = null,
        public ?string $framework = null,
    ) {
        foreach ($diagnostics as $code) {
            if (preg_match('~^BW\d{4}$~', $code) !== 1) {
                throw new InvalidArgumentException(sprintf('[%s] is not a BW diagnostic code.', $code));
            }
        }

        if ($fallback && ($diagnostics !== [] || $framework !== null || $unanalysed !== null)) {
            throw new InvalidArgumentException('fallback: true skips the coverage, diagnostics, framework and unanalysed checks, so none of them may be set with it.');
        }

        $unique = array_values(array_unique($diagnostics));
        sort($unique);
        $this->diagnostics = $unique;
    }
}
