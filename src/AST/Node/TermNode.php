<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\AST\Node;

use HosmelQ\SearchSyntaxParser\AST\Span;
use HosmelQ\SearchSyntaxParser\AST\Visitor\VisitorInterface;
use Override;

readonly class TermNode implements NodeInterface
{
    /**
     * Create a new term node.
     */
    public function __construct(
        private string $value,
        private bool $quoted = false,
        private null|Span $span = null,
    ) {}

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function accept(VisitorInterface $visitor): mixed
    {
        return $visitor->visitTerm($this);
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
        return 'Term';
    }

    /**
     * Get the term value.
     */
    public function getValue(): string
    {
        return $this->value;
    }

    public function isQuoted(): bool
    {
        return $this->quoted;
    }
}
