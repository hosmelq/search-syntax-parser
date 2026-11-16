<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\AST\Visitor;

use HosmelQ\SearchSyntaxParser\AST\Node\BinaryOperatorNode;
use HosmelQ\SearchSyntaxParser\AST\Node\ComparisonNode;
use HosmelQ\SearchSyntaxParser\AST\Node\ExistsNode;
use HosmelQ\SearchSyntaxParser\AST\Node\InNode;
use HosmelQ\SearchSyntaxParser\AST\Node\NodeInterface;
use HosmelQ\SearchSyntaxParser\AST\Node\RangeNode;
use HosmelQ\SearchSyntaxParser\AST\Node\TermNode;
use HosmelQ\SearchSyntaxParser\AST\Node\UnaryOperatorNode;
use HosmelQ\SearchSyntaxParser\AST\Node\WildcardNode;
use Override;

class NodeTransformer implements VisitorInterface
{
    public function transform(NodeInterface $node): NodeInterface
    {
        /**
         * @var NodeInterface
         */
        return $node->accept($this);
    }

    #[Override]
    public function visitBinaryOperator(BinaryOperatorNode $node): NodeInterface
    {
        return new BinaryOperatorNode(
            $node->getOperator(),
            $this->transform($node->getLeft()),
            $this->transform($node->getRight()),
            $node->getSpan(),
        );
    }

    #[Override]
    public function visitComparison(ComparisonNode $node): NodeInterface
    {
        return $node;
    }

    #[Override]
    public function visitExists(ExistsNode $node): NodeInterface
    {
        return $node;
    }

    #[Override]
    public function visitIn(InNode $node): NodeInterface
    {
        return $node;
    }

    #[Override]
    public function visitRange(RangeNode $node): NodeInterface
    {
        return $node;
    }

    #[Override]
    public function visitTerm(TermNode $node): NodeInterface
    {
        return $node;
    }

    #[Override]
    public function visitUnaryOperator(UnaryOperatorNode $node): NodeInterface
    {
        return new UnaryOperatorNode(
            $node->getOperator(),
            $this->transform($node->getOperand()),
            $node->getSpan(),
            $node->isDefault(),
        );
    }

    #[Override]
    public function visitWildcard(WildcardNode $node): NodeInterface
    {
        return $node;
    }
}
