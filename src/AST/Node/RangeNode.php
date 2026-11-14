<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\AST\Node;

use HosmelQ\SearchSyntaxParser\AST\Span;
use HosmelQ\SearchSyntaxParser\AST\Visitor\VisitorInterface;
use Override;

readonly class RangeNode implements NodeInterface
{
    /**
     * Create a new range node.
     */
    public function __construct(
        private string $field,
        private null|bool|float|int|string $from,
        private null|bool|float|int|string $to,
        private null|Span $span = null,
    ) {}

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function accept(VisitorInterface $visitor): mixed
    {
        return $visitor->visitRange($this);
    }

    /**
     * Get the field name the range applies to.
     */
    public function getField(): string
    {
        return $this->field;
    }

    /**
     * Get the inclusive start value, or null for an open bound.
     */
    public function getFrom(): null|bool|float|int|string
    {
        return $this->from;
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function getSpan(): null|Span
    {
        return $this->span;
    }

    /**
     * Get the inclusive end value, or null for an open bound.
     */
    public function getTo(): null|bool|float|int|string
    {
        return $this->to;
    }

    #[Override]
    public function getType(): string
    {
        return 'Range';
    }
}
