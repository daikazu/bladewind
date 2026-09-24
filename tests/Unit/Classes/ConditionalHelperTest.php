<?php

declare(strict_types=1);

use Daikazu\BladeWind\Classes\ConditionalHelper;
use Daikazu\BladeWind\Resolution\Argument;
use Daikazu\BladeWind\Resolution\DirectiveArguments;

function helperArgument(string $arguments): ?Argument
{
    return DirectiveArguments::split($arguments)[0] ?? null;
}

it('separates unconditional, conditional, and unreadable elements', function (): void {
    $result = ConditionalHelper::parse(helperArgument("(['rounded-lg p-4', 'shadow' => \$raised, \$extra, 'a' => 'b' => 1])"));

    expect($result->staticTokens)->toBe(['rounded-lg', 'p-4'])
        ->and(array_map(fn ($c) => [$c->tokens, $c->condition], $result->conditions))->toBe([[['shadow'], '$raised']])
        ->and($result->unresolved)->toBe(['$extra', "'a' => 'b' => 1"]);
});

it('keeps multi-token keys and complex conditions verbatim', function (): void {
    $result = ConditionalHelper::parse(helperArgument("(['bg-blue-600 text-white' => \$user->isAdmin() && ! \$disabled])"));

    expect($result->conditions[0]->tokens)->toBe(['bg-blue-600', 'text-white'])
        ->and($result->conditions[0]->condition)->toBe('$user->isAdmin() && ! $disabled');
});

it('treats a non-array argument as one unresolved element', function (): void {
    expect(ConditionalHelper::parse(helperArgument('($classes)'))->unresolved)->toBe(['$classes'])
        ->and(ConditionalHelper::parse(null)->unresolved)->toBe(['(no array argument)'])
        ->and(ConditionalHelper::mergeClasses(null)->unresolved)->toBe(['(no array argument)']);
});

it('reads only the class key of a merge array', function (): void {
    $plain = ConditionalHelper::mergeClasses(helperArgument("(['class' => 'bg-white p-2', 'id' => 'x'])"));
    $nested = ConditionalHelper::mergeClasses(helperArgument("(['class' => ['border', 'shadow' => \$raised]])"));
    $dynamic = ConditionalHelper::mergeClasses(helperArgument("(['class' => \$classes])"));
    $absent = ConditionalHelper::mergeClasses(helperArgument("(['id' => 'x'])"));

    expect($plain->staticTokens)->toBe(['bg-white', 'p-2'])
        ->and($nested->staticTokens)->toBe(['border'])
        ->and($nested->conditions[0]->tokens)->toBe(['shadow'])
        ->and($dynamic->unresolved)->toBe(['$classes'])
        ->and($absent->isEmpty())->toBeTrue();
});
