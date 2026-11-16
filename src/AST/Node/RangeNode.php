<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\AST\Node;

use HosmelQ\SearchSyntaxParser\AST\Span;
use HosmelQ\SearchSyntaxParser\AST\Visitor\VisitorInterface;
use Override;

readonly class RangeNode implements NodeInterface
{
    public function __construct(
        private string $field,
        private null|bool|float|int|string $from,
        private null|bool|float|int|string $to,
        private null|Span $span = null,
    ) {}

    #[Override]
    public function accept(VisitorInterface $visitor): mixed
    {
        return $visitor->visitRange($this);
    }

    public function getField(): string
    {
        return $this->field;
    }

    public function getFrom(): null|bool|float|int|string
    {
        return $this->from;
    }

    #[Override]
    public function getSpan(): null|Span
    {
        return $this->span;
    }

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
