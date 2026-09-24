<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Declarations;

use Illuminate\Contracts\Config\Repository;

final class Safelist
{
    /**
     * @var array<string, true>
     */
    private array $exact = [];

    /**
     * @var list<string>
     */
    private array $prefixes = [];

    /**
     * @var list<string>
     */
    private array $invalid = [];

    /**
     * @param  list<mixed>  $entries
     */
    public function __construct(array $entries)
    {
        foreach ($entries as $entry) {
            if (! is_string($entry) || ! self::valid($entry)) {
                $this->invalid[] = is_string($entry) ? $entry : json_encode($entry, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);

                continue;
            }

            if (str_ends_with($entry, '*')) {
                $this->prefixes[] = substr($entry, 0, -1);
            } else {
                $this->exact[$entry] = true;
            }
        }
    }

    public static function fromConfig(Repository $config): self
    {
        /** @var list<mixed> $entries */
        $entries = $config->get('bladewind.safelist', []);

        return new self($entries);
    }

    public function matches(string $token): bool
    {
        if (isset($this->exact[$token])) {
            return true;
        }

        foreach ($this->prefixes as $prefix) {
            if (str_starts_with($token, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The exact tokens, as configured.
     *
     * @return list<string>
     */
    public function exact(): array
    {
        return array_keys($this->exact);
    }

    /**
     * The prefix patterns, as configured (`text-*`), so a page's identity can carry them.
     *
     * @return list<string>
     */
    public function patterns(): array
    {
        return array_map(static fn (string $prefix): string => $prefix.'*', $this->prefixes);
    }

    /**
     * Every one of $tokens a prefix pattern selects: what a stylesheet actually has rules for
     * under `text-*`, which is the only way a pattern can name concrete classes.
     *
     * @param  list<string>  $tokens
     * @return list<string>
     */
    public function expand(array $tokens): array
    {
        if ($this->prefixes === []) {
            return [];
        }

        $selected = [];

        foreach ($tokens as $token) {
            foreach ($this->prefixes as $prefix) {
                if (str_starts_with($token, $prefix)) {
                    $selected[] = $token;

                    break;
                }
            }
        }

        return $selected;
    }

    /**
     * @return list<string>
     */
    public function invalid(): array
    {
        return $this->invalid;
    }

    public function isEmpty(): bool
    {
        return $this->exact === [] && $this->prefixes === [];
    }

    private static function valid(string $entry): bool
    {
        if ($entry === '' || preg_match('/\s/', $entry) === 1) {
            return false;
        }

        $stars = substr_count($entry, '*');

        if ($stars === 0) {
            return true;
        }

        return $stars === 1 && str_ends_with($entry, '*') && strlen($entry) > 1;
    }
}
