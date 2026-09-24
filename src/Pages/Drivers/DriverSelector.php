<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Pages\Drivers;

use Illuminate\Contracts\Config\Repository;

/**
 * Which {@see CssFrameworkDriver} a compiled stylesheet is taken apart with.
 *
 * `bladewind.framework` names one driver, or is `auto` (the default): the first driver in
 * registration order whose {@see CssFrameworkDriver::detect()} accepts the stylesheet. An
 * unrecognised value behaves as `auto`, so a config typo cannot change how a page is delivered (the
 * same policy as `pages.delivery`). If no driver matches, the request path serves the full
 * stylesheet and reports BW6002.
 */
final class DriverSelector
{
    public const AUTO = 'auto';

    /**
     * @param  list<CssFrameworkDriver>  $drivers  in detection order: the most specific signature first
     */
    public function __construct(
        private array $drivers,
        private Repository $config,
    ) {}

    public function select(string $css): ?CssFrameworkDriver
    {
        $configured = $this->configured();

        foreach ($this->drivers as $driver) {
            if ($configured === self::AUTO ? $driver->detect($css) : $driver->name() === $configured) {
                return $driver;
            }
        }

        return null;
    }

    /**
     * The runtime class tokens ({@see CssFrameworkDriver::runtimeTokens()}) for $css. Under `auto`
     * this is the union over every driver that detects the stylesheet, not just the selected one: a
     * Bootstrap bundle containing a Tailwind snippet is claimed by the `--tw-` signature first, but
     * Bootstrap's JavaScript still creates `modal-backdrop`. Extra tokens cost a few rules; a
     * missing one costs a modal with no backdrop. A named driver answers alone.
     *
     * @return list<string>
     */
    public function runtimeTokens(string $css): array
    {
        if ($this->configured() !== self::AUTO) {
            return $this->select($css)?->runtimeTokens() ?? [];
        }

        $tokens = [];

        foreach ($this->drivers as $driver) {
            if ($driver->detect($css)) {
                $tokens = [...$tokens, ...$driver->runtimeTokens()];
            }
        }

        return array_values(array_unique($tokens));
    }

    /**
     * Whether the driver is being recognised from the stylesheet rather than named in
     * configuration: `auto`, an empty value, or a name no registered driver answers to.
     */
    public function auto(): bool
    {
        return $this->configured() === self::AUTO;
    }

    /**
     * What every driver looks for, in detection order, for the BW6002 message.
     *
     * @return list<string>
     */
    public function expectations(): array
    {
        return array_map(static fn (CssFrameworkDriver $driver): string => $driver->expects(), $this->drivers);
    }

    /**
     * The configured driver name, or `auto` for anything that names no registered driver. Read on
     * every call, since config can change after this selector is built.
     */
    private function configured(): string
    {
        $value = $this->config->get('bladewind.framework', self::AUTO);

        if (! is_string($value) || $value === '' || $value === self::AUTO) {
            return self::AUTO;
        }

        foreach ($this->drivers as $driver) {
            if ($driver->name() === $value) {
                return $value;
            }
        }

        return self::AUTO;
    }
}
