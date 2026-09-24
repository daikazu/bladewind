<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Parsing\Nodes;

final readonly class Template
{
    /**
     * @param  list<Node>  $children
     * @param  list<string>  $parseErrors
     */
    public function __construct(public array $children, public array $parseErrors = []) {}

    /**
     * @param  callable(Node): void  $visitor
     */
    public function walk(callable $visitor): void
    {
        foreach ($this->children as $child) {
            $this->visit($child, $visitor);
        }
    }

    /**
     * @param  callable(Node): void  $visitor
     */
    private function visit(Node $node, callable $visitor): void
    {
        $visitor($node);

        foreach ($node->children() as $child) {
            $this->visit($child, $visitor);
        }
    }
}
