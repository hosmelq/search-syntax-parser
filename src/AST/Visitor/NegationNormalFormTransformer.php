<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\AST\Visitor;

use HosmelQ\SearchSyntaxParser\AST\Node\BinaryOperatorNode;
use HosmelQ\SearchSyntaxParser\AST\Node\ComparisonNode;
use HosmelQ\SearchSyntaxParser\AST\Node\ExistsNode;
use HosmelQ\SearchSyntaxParser\AST\Node\InNode;
use HosmelQ\SearchSyntaxParser\AST\Node\NodeInterface;
use HosmelQ\SearchSyntaxParser\AST\Node\UnaryOperatorNode;
use HosmelQ\SearchSyntaxParser\AST\Node\WildcardNode;
use HosmelQ\SearchSyntaxParser\AST\Span;
use Override;

/**
 * Express set complements as NOT directly around positive leaves, without flipping comparisons.
 */
final class NegationNormalFormTransformer extends NodeTransformer
{
    #[Override]
    public function visitComparison(ComparisonNode $node): NodeInterface
    {
        return $node->getOperator() === '!='
            ? new UnaryOperatorNode('NOT', new ComparisonNode(
                $node->getField(),
                '=',
                $node->getValue(),
                $node->getSpan(),
                $node->isDefault(),
                $node->isQuoted(),
            ), $node->getSpan(), $node->isDefault())
            : $node;
    }

    #[Override]
    public function visitIn(InNode $node): NodeInterface
    {
        return $node->getOperator() === '!='
            ? new UnaryOperatorNode('NOT', new InNode(
                $node->getField(),
                '=',
                $node->getValues(),
                $node->getSpan(),
                $node->isDefault(),
                array_map($node->isQuoted(...), array_keys($node->getValues())),
                $node->getPositions(),
            ), $node->getSpan(), $node->isDefault())
            : $node;
    }

    #[Override]
    public function visitUnaryOperator(UnaryOperatorNode $node): NodeInterface
    {
        return $this->negate(
            $this->transform($node->getOperand()),
            $node->getSpan(),
            $node->isDefault(),
        );
    }

    #[Override]
    public function visitWildcard(WildcardNode $node): NodeInterface
    {
        return $node->getOperator() === '!='
            ? new UnaryOperatorNode('NOT', new WildcardNode(
                $node->getField(),
                '=',
                $node->getSegments(),
                $node->getSpan(),
            ), $node->getSpan())
            : $node;
    }

    /**
     * Keep a negated default leaf recognisable as a default.
     */
    private function isDefault(NodeInterface $node): bool
    {
        return ($node instanceof ComparisonNode
            || $node instanceof ExistsNode
            || $node instanceof InNode)
            && $node->isDefault();
    }

    private function negate(
        NodeInterface $node,
        null|Span $span,
        bool $default = false,
    ): NodeInterface {
        if ($node instanceof UnaryOperatorNode) {
            return $node->getOperand();
        }

        if ($node instanceof BinaryOperatorNode) {
            $left = $node->getLeft();
            $right = $node->getRight();

            return new BinaryOperatorNode(
                $node->getOperator() === 'AND' ? 'OR' : 'AND',
                $this->negate($left, $left->getSpan()),
                $this->negate($right, $right->getSpan()),
                $span,
            );
        }

        return new UnaryOperatorNode('NOT', $node, $span, $default || $this->isDefault($node));
    }
}
