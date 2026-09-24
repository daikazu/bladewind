<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Parsing;

use Daikazu\BladeWind\Parsing\Nodes\Attribute;
use Daikazu\BladeWind\Parsing\Nodes\BindingKind;
use Daikazu\BladeWind\Parsing\Nodes\Block;
use Daikazu\BladeWind\Parsing\Nodes\Comment;
use Daikazu\BladeWind\Parsing\Nodes\ComponentTag;
use Daikazu\BladeWind\Parsing\Nodes\Directive;
use Daikazu\BladeWind\Parsing\Nodes\DirectiveRole;
use Daikazu\BladeWind\Parsing\Nodes\EchoStatement;
use Daikazu\BladeWind\Parsing\Nodes\Element;
use Daikazu\BladeWind\Parsing\Nodes\Node;
use Daikazu\BladeWind\Parsing\Nodes\PhpBlock;
use Daikazu\BladeWind\Parsing\Nodes\Position;
use Daikazu\BladeWind\Parsing\Nodes\Template;
use Daikazu\BladeWind\Parsing\Nodes\Text;
use Forte\Ast\BladeCommentNode;
use Forte\Ast\Components\ComponentNode;
use Forte\Ast\DirectiveBlockNode;
use Forte\Ast\DirectiveNode;
use Forte\Ast\Document\Document;
use Forte\Ast\EchoNode;
use Forte\Ast\Elements\Attribute as ForteAttribute;
use Forte\Ast\Elements\ElementNode;
use Forte\Ast\Node as ForteNode;
use Forte\Ast\PhpBlockNode;
use Forte\Parser\ParserOptions;

final class ForteSourceParser implements SourceParser
{
    public function parse(string $source): Template
    {
        $document = Document::parse($source, ParserOptions::make()->acceptAllDirectives());

        $children = [];

        foreach ($document as $node) {
            $children[] = $this->map($node);
        }

        $errors = [];

        foreach ($document->diagnostics()->errors() as $diagnostic) {
            $errors[] = $diagnostic->message;
        }

        return new Template($children, $errors);
    }

    private function map(ForteNode $node): Node
    {
        $position = $this->position($node);

        if ($node instanceof ComponentNode) {
            return new ComponentTag(
                $position,
                $node->getPrefix(),
                $node->getComponentName(),
                $node->isSlot(),
                $this->attributes($node),
                $this->children($node),
            );
        }

        if ($node instanceof ElementNode) {
            return new Element($position, $node->tagNameText(), $this->attributes($node), $this->children($node));
        }

        if ($node instanceof DirectiveNode) {
            return new Directive($position, $node->nameText(), $node->arguments(), $this->role($node), $this->children($node));
        }

        if ($node instanceof DirectiveBlockNode) {
            return new Block($position, $this->children($node));
        }

        if ($node instanceof EchoNode) {
            return new EchoStatement($position, $node->render());
        }

        if ($node instanceof BladeCommentNode) {
            return new Comment($position, $node->render());
        }

        if ($node instanceof PhpBlockNode) {
            return new PhpBlock($position, $node->render());
        }

        return new Text($position, $node->render());
    }

    /**
     * @return list<Node>
     */
    private function children(ForteNode $node): array
    {
        $children = [];

        foreach ($node->getChildren() as $child) {
            $children[] = $this->map($child);
        }

        return $children;
    }

    /**
     * @return list<Attribute>
     */
    private function attributes(ElementNode $node): array
    {
        $attributes = [];

        foreach ($node->attributes() as $attribute) {
            $attributes[] = $this->attribute($attribute);
        }

        return $attributes;
    }

    private function attribute(ForteAttribute $attribute): Attribute
    {
        if ($attribute->isBladeConstruct()) {
            $construct = $attribute->getBladeConstruct();

            [$name, $arguments] = match (true) {
                $construct instanceof DirectiveNode => [$construct->nameText(), $construct->arguments()],
                $construct instanceof EchoNode => [Attribute::CONSTRUCT_ECHO, $construct->render()],
                default => [null, null],
            };

            return new Attribute(
                name: '',
                kind: BindingKind::BladeConstruct,
                value: null,
                staticTokens: null,
                containsEchoes: false,
                constructName: $name,
                constructArguments: $arguments,
            );
        }

        $kind = BindingKind::tryFrom($attribute->type()) ?? BindingKind::Static;
        $containsEchoes = count($attribute->getInternalEchoes()) > 0;

        return new Attribute(
            name: $attribute->nameText(),
            kind: $kind,
            value: $attribute->valueText(),
            staticTokens: $containsEchoes ? null : $attribute->staticTokens(),
            containsEchoes: $containsEchoes,
        );
    }

    private function role(DirectiveNode $node): DirectiveRole
    {
        if ($node->isOpening()) {
            return DirectiveRole::Opening;
        }

        if ($node->isIntermediate()) {
            return DirectiveRole::Intermediate;
        }

        if ($node->isClosing()) {
            return DirectiveRole::Closing;
        }

        return DirectiveRole::Standalone;
    }

    private function position(ForteNode $node): Position
    {
        return new Position(
            $node->startLine(),
            $node->startColumn(),
            $node->endLine(),
            $node->endColumn(),
            $node->startOffset(),
            $node->endOffset(),
        );
    }
}
