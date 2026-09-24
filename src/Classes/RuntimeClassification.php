<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Classes;

enum RuntimeClassification: string
{
    case Static = 'static';
    case Enumerable = 'enumerable';
    case Partial = 'partial';
    case Unresolved = 'unresolved';

    public static function of(bool $hasStatic, bool $hasConditions, bool $hasUnresolved): self
    {
        $hasTokens = $hasStatic || $hasConditions;

        return match (true) {
            $hasTokens && $hasUnresolved => self::Partial,
            $hasUnresolved => self::Unresolved,
            $hasConditions => self::Enumerable,
            default => self::Static,
        };
    }

    public static function fromResult(ClassExpressionResult $result): self
    {
        return self::of($result->staticTokens !== [], $result->conditions !== [], $result->unresolved !== []);
    }
}
