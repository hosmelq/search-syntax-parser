<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\AST\Node;

use HosmelQ\SearchSyntaxParser\AST\Visitor\VisitorInterface;

class UnaryOperatorNode extends AbstractNode
{
    /**
     * Create a new unary operator node.
     */
    public function __construct(
        private readonly string $operator,
        private readonly NodeInterface $operand,
    ) {
    }

    /**
     * {@inheritDoc}
     */
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
    public function getType(): string
    {
        return 'UnaryOperator';
    }
}
