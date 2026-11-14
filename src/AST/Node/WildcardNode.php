<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\AST\Node;

use HosmelQ\SearchSyntaxParser\AST\Span;
use HosmelQ\SearchSyntaxParser\AST\Visitor\VisitorInterface;
use Override;

final readonly class WildcardNode implements NodeInterface
{
    /**
     * @param list<string> $segments
     */
    public function __construct(
        private null|string $field,
        private string $operator,
        private array $segments,
        private null|Span $span = null,
    ) {}

    #[Override]
    public function accept(VisitorInterface $visitor): mixed
    {
        return $visitor->visitWildcard($this);
    }

    public function getField(): null|string
    {
        return $this->field;
    }

    public function getOperator(): string
    {
        return $this->operator;
    }

    /**
     * @return list<string>
     */
    public function getSegments(): array
    {
        return $this->segments;
    }

    #[Override]
    public function getSpan(): null|Span
    {
        return $this->span;
    }

    #[Override]
    public function getType(): string
    {
        return 'Wildcard';
    }
}
