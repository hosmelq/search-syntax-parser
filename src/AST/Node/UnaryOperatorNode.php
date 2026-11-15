<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\AST\Node;

use HosmelQ\SearchSyntaxParser\AST\Span;
use HosmelQ\SearchSyntaxParser\AST\Visitor\VisitorInterface;
use Override;

readonly class UnaryOperatorNode implements NodeInterface
{
    public function __construct(
        private string $operator,
        private NodeInterface $operand,
        private null|Span $span = null,
        private bool $default = false,
    ) {}

    #[Override]
    public function accept(VisitorInterface $visitor): mixed
    {
        return $visitor->visitUnaryOperator($this);
    }

    public function getOperand(): NodeInterface
    {
        return $this->operand;
    }

    public function getOperator(): string
    {
        return $this->operator;
    }

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

    public function isDefault(): bool
    {
        return $this->default;
    }
}
