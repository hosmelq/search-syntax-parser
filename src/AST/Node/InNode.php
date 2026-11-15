<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\AST\Node;

use HosmelQ\SearchSyntaxParser\AST\Span;
use HosmelQ\SearchSyntaxParser\AST\Visitor\VisitorInterface;
use Override;

readonly class InNode implements NodeInterface
{
    /**
     * Create a new in node.
     *
     * @param list<bool|float|int|string> $values
     * @param list<bool> $quoted
     */
    public function __construct(
        private string $field,
        private string $operator,
        private array $values,
        private null|Span $span = null,
        private bool $default = false,
        private array $quoted = [],
    ) {}

    /**
     * {@inheritDoc}
     */
    #[Override]
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
    #[Override]
    public function getSpan(): null|Span
    {
        return $this->span;
    }

    #[Override]
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

    /**
     * Whether this clause was created from a configured field default.
     */
    public function isDefault(): bool
    {
        return $this->default;
    }

    public function isQuoted(int $index): bool
    {
        return $this->quoted[$index] ?? false;
    }
}
