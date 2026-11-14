<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\AST\Node;

use HosmelQ\SearchSyntaxParser\AST\Span;
use HosmelQ\SearchSyntaxParser\AST\Visitor\VisitorInterface;
use Override;

readonly class UnaryOperatorNode implements NodeInterface
{
    /**
     * Create a new unary operator node.
     */
    public function __construct(
        private string $operator,
        private NodeInterface $operand,
        private null|Span $span = null,
    ) {}

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function accept(VisitorInterface $visitor): mixed
    {
        return $visitor->visitUnaryOperator($this);
    }

    /**
     * Get the operand.
     */
    public function getOperand(): NodeInterface
    {
        return $this->operand;
    }

    /**
     * Get the unary operator (e.g., NOT).
     */
    public function getOperator(): string
    {
        return $this->operator;
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function getSpan(): null|Span
    {
        return $this->span;
    }

    #[Override]
    public function getType(): string
    {
        return 'UnaryOperator';
    }
}
