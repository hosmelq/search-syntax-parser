<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\AST\Node;

use HosmelQ\SearchSyntaxParser\AST\Span;
use HosmelQ\SearchSyntaxParser\AST\Visitor\VisitorInterface;
use Override;

readonly class ComparisonNode implements NodeInterface
{
    public function __construct(
        private string $field,
        private string $operator,
        private bool|float|int|string $value,
        private null|Span $span = null,
        private bool $default = false,
        private bool $quoted = false,
    ) {}

    #[Override]
    public function accept(VisitorInterface $visitor): mixed
    {
        return $visitor->visitComparison($this);
    }

    public function getField(): string
    {
        return $this->field;
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
        return 'Comparison';
    }

    public function getValue(): bool|float|int|string
    {
        return $this->value;
    }

    public function isDefault(): bool
    {
        return $this->default;
    }

    public function isQuoted(): bool
    {
        return $this->quoted;
    }
}
