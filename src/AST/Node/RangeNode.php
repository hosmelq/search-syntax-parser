<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\AST\Node;

use HosmelQ\SearchSyntaxParser\AST\Visitor\VisitorInterface;

readonly class RangeNode implements NodeInterface
{
    /**
     * Create a new range node.
     */
    public function __construct(
        private string $field,
        private bool|float|int|string $from,
        private bool|float|int|string $to,
    ) {
    }

    /**
     * {@inheritDoc}
     */
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
     * Get the inclusive start value of the range.
     */
    public function getFrom(): bool|float|int|string
    {
        return $this->from;
    }

    /**
     * Get the inclusive end value of the range.
     */
    public function getTo(): bool|float|int|string
    {
        return $this->to;
    }

    /**
     * {@inheritDoc}
     */
    public function getType(): string
    {
        return 'Range';
    }
}
