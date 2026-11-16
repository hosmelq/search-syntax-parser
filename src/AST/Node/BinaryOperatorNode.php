<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\AST\Node;

use HosmelQ\SearchSyntaxParser\AST\Span;
use HosmelQ\SearchSyntaxParser\AST\Visitor\VisitorInterface;
use Override;

readonly class BinaryOperatorNode implements NodeInterface
{
    public function __construct(
        private string $operator,
        private NodeInterface $left,
        private NodeInterface $right,
        private null|Span $span = null,
    ) {}

    #[Override]
    public function accept(VisitorInterface $visitor): mixed
    {
        return $visitor->visitBinaryOperator($this);
    }

    public function getLeft(): NodeInterface
    {
        return $this->left;
    }

    public function getOperator(): string
    {
        return $this->operator;
    }

    public function getRight(): NodeInterface
    {
        return $this->right;
    }

    #[Override]
    public function getSpan(): null|Span
    {
        return $this->span;
    }

    #[Override]
    public function getType(): string
    {
        return 'BinaryOperator';
    }
}
