<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\AST\Node;

use HosmelQ\SearchSyntaxParser\AST\Visitor\VisitorInterface;

readonly class BinaryOperatorNode implements NodeInterface
{
    /**
     * Create a new binary operator node.
     */
    public function __construct(
        private string $operator,
        private NodeInterface $left,
        private NodeInterface $right,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function accept(VisitorInterface $visitor): mixed
    {
        return $visitor->visitBinaryOperator($this);
    }

    /**
     * Get the left operand.
     */
    public function getLeft(): NodeInterface
    {
        return $this->left;
    }

    /**
     * Get the binary operator (e.g., AND, OR).
     */
    public function getOperator(): string
    {
        return $this->operator;
    }

    /**
     * Get the right operand.
     */
    public function getRight(): NodeInterface
    {
        return $this->right;
    }

    /**
     * {@inheritDoc}
     */
    public function getType(): string
    {
        return 'BinaryOperator';
    }
}
