<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\AST\Node;

use HosmelQ\SearchSyntaxParser\AST\Span;
use HosmelQ\SearchSyntaxParser\AST\Visitor\VisitorInterface;
use Override;

readonly class ComparisonNode implements NodeInterface
{
    /**
     * Create a new comparison node.
     */
    public function __construct(
        private string $field,
        private string $operator,
        private bool|float|int|string $value,
        private null|Span $span = null,
        private bool $default = false,
    ) {}

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function accept(VisitorInterface $visitor): mixed
    {
        return $visitor->visitComparison($this);
    }

    /**
     * Get the field name being compared.
     */
    public function getField(): string
    {
        return $this->field;
    }

    /**
     * Get the comparison operator (e.g., =, !=, >, >=, <, <=).
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
        return 'Comparison';
    }

    /**
     * Get the comparison value.
     */
    public function getValue(): bool|float|int|string
    {
        return $this->value;
    }

    /**
     * Whether this clause was created from a configured field default.
     */
    public function isDefault(): bool
    {
        return $this->default;
    }
}
