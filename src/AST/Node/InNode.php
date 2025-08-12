<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\AST\Node;

use HosmelQ\SearchSyntaxParser\AST\Visitor\VisitorInterface;

class InNode extends AbstractNode
{
    /**
     * Create a new in node for multi-value field comparison.
     *
     * @param array<int, float|int|string> $values
     */
    public function __construct(
        private readonly string $field,
        private readonly string $operator,
        private readonly array $values,
    ) {
    }

    /**
     * Accept a visitor for traversal or transformation.
     */
    public function accept(VisitorInterface $visitor): mixed
    {
        return $visitor->visitIn($this);
    }

    /**
     * Get the field name for this comparison.
     */
    public function getField(): string
    {
        return $this->field;
    }

    /**
     * Get the comparison operator (e.g., =, !=).
     */
    public function getOperator(): string
    {
        return $this->operator;
    }

    /**
     * Get node type identifier.
     */
    public function getType(): string
    {
        return 'In';
    }

    /**
     * Get the array of values for this comparison.
     *
     * @return array<int, float|int|string>
     */
    public function getValues(): array
    {
        return $this->values;
    }
}
