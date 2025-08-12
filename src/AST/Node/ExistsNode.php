<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\AST\Node;

use HosmelQ\SearchSyntaxParser\AST\Visitor\VisitorInterface;

/**
 * Represents an exists query (field:*) that matches documents
 * where the specified field has any non-null value.
 */
class ExistsNode extends AbstractNode
{
    /**
     * Create a new exists node for the specified field.
     */
    public function __construct(
        private readonly string $field
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function accept(VisitorInterface $visitor): mixed
    {
        return $visitor->visitExists($this);
    }

    /**
     * Get the field name for this exists query.
     */
    public function getField(): string
    {
        return $this->field;
    }

    /**
     * {@inheritDoc}
     */
    public function getType(): string
    {
        return 'Exists';
    }
}
