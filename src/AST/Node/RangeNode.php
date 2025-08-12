<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\AST\Node;

use HosmelQ\SearchSyntaxParser\AST\Visitor\VisitorInterface;

class RangeNode extends AbstractNode
{
    /**
     * Create a new range node.
     */
    public function __construct(
        private readonly string $field,
        private readonly mixed $from,
        private readonly mixed $to,
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
    public function getFrom(): mixed
    {
        return $this->from;
    }

    /**
     * Get the inclusive end value of the range.
     */
    public function getTo(): mixed
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
