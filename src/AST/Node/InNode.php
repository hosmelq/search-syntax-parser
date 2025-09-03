<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\AST\Node;

use HosmelQ\SearchSyntaxParser\AST\Visitor\VisitorInterface;

readonly class InNode implements NodeInterface
{
    /**
     * Create a new in node.
     *
     * @param list<bool|float|int|string> $values
     */
    public function __construct(
        private string $field,
        private string $operator,
        private array $values,
    ) {
    }

    /**
     * {@inheritDoc}
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
     * {@inheritDoc}
     */
    public function getType(): string
    {
        return 'In';
    }

    /**
     * Get the array of values for this comparison.
     *
     * @return list<bool|float|int|string>
     */
    public function getValues(): array
    {
        return $this->values;
    }
}
