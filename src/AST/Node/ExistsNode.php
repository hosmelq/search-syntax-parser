<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\AST\Node;

use HosmelQ\SearchSyntaxParser\AST\Span;
use HosmelQ\SearchSyntaxParser\AST\Visitor\VisitorInterface;
use Override;

readonly class ExistsNode implements NodeInterface
{
    public function __construct(
        private string $field,
        private null|Span $span = null,
        private bool $default = false,
    ) {}

    #[Override]
    public function accept(VisitorInterface $visitor): mixed
    {
        return $visitor->visitExists($this);
    }

    public function getField(): string
    {
        return $this->field;
    }

    #[Override]
    public function getSpan(): null|Span
    {
        return $this->span;
    }

    #[Override]
    public function getType(): string
    {
        return 'Exists';
    }

    public function isDefault(): bool
    {
        return $this->default;
    }
}
