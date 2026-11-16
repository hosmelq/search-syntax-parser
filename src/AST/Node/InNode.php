<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\AST\Node;

use HosmelQ\SearchSyntaxParser\AST\Span;
use HosmelQ\SearchSyntaxParser\AST\Visitor\VisitorInterface;
use Override;

readonly class InNode implements NodeInterface
{
    /**
     * @param list<bool|float|int|string> $values
     * @param list<bool> $quoted
     * @param list<int> $positions written positions of the values; empty means their list order
     */
    public function __construct(
        private string $field,
        private string $operator,
        private array $values,
        private null|Span $span = null,
        private bool $default = false,
        private array $quoted = [],
        private array $positions = [],
    ) {}

    #[Override]
    public function accept(VisitorInterface $visitor): mixed
    {
        return $visitor->visitIn($this);
    }

    public function getField(): string
    {
        return $this->field;
    }

    public function getOperator(): string
    {
        return $this->operator;
    }

    /**
     * @return list<int>
     */
    public function getPositions(): array
    {
        return $this->positions === [] ? array_keys($this->values) : $this->positions;
    }

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
     * @return list<bool|float|int|string>
     */
    public function getValues(): array
    {
        return $this->values;
    }

    public function isDefault(): bool
    {
        return $this->default;
    }

    public function isQuoted(int $index): bool
    {
        return $this->quoted[$index] ?? false;
    }
}
