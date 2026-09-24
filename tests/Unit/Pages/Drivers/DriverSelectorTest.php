<?php

declare(strict_types=1);

use Daikazu\BladeWind\Pages\Drivers\CssFrameworkDriver;
use Daikazu\BladeWind\Pages\Drivers\DriverSelector;
use Daikazu\BladeWind\Pages\SplitStylesheet;
use Illuminate\Config\Repository;

/**
 * A driver that answers to $name and recognises any stylesheet containing $signature.
 */
function stubDriver(string $name, string $signature): CssFrameworkDriver
{
    return new class($name, $signature) implements CssFrameworkDriver
    {
        public function __construct(private string $name, private string $signature) {}

        public function name(): string
        {
            return $this->name;
        }

        public function detect(string $css): bool
        {
            return str_contains($css, $this->signature);
        }

        public function split(string $css): SplitStylesheet
        {
            return new SplitStylesheet($css, '', false);
        }

        public function indexTokens(string $selectorList): array
        {
            return [];
        }

        public function wrapUtilities(string $rules): string
        {
            return $rules;
        }

        public function expects(): string
        {
            return $this->signature.' somewhere';
        }

        public function runtimeTokens(): array
        {
            return [];
        }
    };
}

function selector(?string $framework, CssFrameworkDriver ...$drivers): DriverSelector
{
    return new DriverSelector(array_values($drivers), new Repository($framework === null ? [] : ['bladewind' => ['framework' => $framework]]));
}

it('tries each driver in order under auto and selects none when none recognises the stylesheet', function (): void {
    $first = stubDriver('first', 'AAA');
    $second = stubDriver('second', 'BBB');

    expect(selector('auto', $first, $second)->select('x AAA BBB'))->toBe($first)
        ->and(selector('auto', $first, $second)->select('x BBB'))->toBe($second)
        ->and(selector(null, $first, $second)->select('x BBB'))->toBe($second)
        ->and(selector('auto', $first, $second)->select('x'))->toBeNull()
        ->and(selector('auto', $first, $second)->expectations())->toBe(['AAA somewhere', 'BBB somewhere']);
});

it('selects the configured driver without asking it, and treats an unknown name as auto', function (): void {
    $first = stubDriver('first', 'AAA');
    $second = stubDriver('second', 'BBB');

    expect(selector('second', $first, $second)->select('x AAA'))->toBe($second)
        // Named outright, the driver gets the stylesheet whether or not its signature is there;
        // what it makes of it is BW6002's business, not the selector's.
        ->and(selector('first', $first, $second)->select('x'))->toBe($first)
        ->and(selector('third', $first, $second)->select('x BBB'))->toBe($second)
        ->and(selector('', $first, $second)->select('x BBB'))->toBe($second);
});

it('reads the configuration on every selection', function (): void {
    $first = stubDriver('first', 'AAA');
    $second = stubDriver('second', 'BBB');
    $config = new Repository(['bladewind' => ['framework' => 'auto']]);
    $selector = new DriverSelector([$first, $second], $config);

    expect($selector->select('x'))->toBeNull();

    $config->set('bladewind.framework', 'second');

    expect($selector->select('x'))->toBe($second);
});

it('unions the runtime tokens of every driver that recognises the stylesheet under auto, and only the named one otherwise', function (): void {
    $withTokens = static fn (string $name, string $signature, array $tokens): CssFrameworkDriver => new class($name, $signature, $tokens) implements CssFrameworkDriver
    {
        public function __construct(private string $name, private string $signature, private array $tokens) {}

        public function name(): string
        {
            return $this->name;
        }

        public function detect(string $css): bool
        {
            return str_contains($css, $this->signature);
        }

        public function split(string $css): SplitStylesheet
        {
            return new SplitStylesheet($css, '', false);
        }

        public function indexTokens(string $selectorList): array
        {
            return [];
        }

        public function wrapUtilities(string $rules): string
        {
            return $rules;
        }

        public function expects(): string
        {
            return $this->signature;
        }

        public function runtimeTokens(): array
        {
            return $this->tokens;
        }
    };
    $tw = $withTokens('tw', '--tw-', []);
    $bs = $withTokens('bs', '--bs-', ['modal-backdrop', 'show']);
    $css = ':root{--bs-blue:#00f}.ring{box-shadow:var(--tw-ring-shadow)}';

    // A Bootstrap bundle with one Tailwind-generated snippet in it is claimed by the `--tw-`
    // signature first; Bootstrap's JavaScript still creates its backdrops on every page.
    expect(selector(null, $tw, $bs)->select($css)?->name())->toBe('tw')
        ->and(selector(null, $tw, $bs)->runtimeTokens($css))->toBe(['modal-backdrop', 'show'])
        ->and(selector('tw', $tw, $bs)->runtimeTokens($css))->toBe([])
        ->and(selector('bs', $tw, $bs)->runtimeTokens($css))->toBe(['modal-backdrop', 'show']);
});
