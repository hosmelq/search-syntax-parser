<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\AST\Node;

use HosmelQ\SearchSyntaxParser\AST\Visitor\VisitorInterface;
use Override;

readonly class TermNode implements NodeInterface
{
    /**
     * Create a new term node.
     */
    public function __construct(private string $value) {}

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
}
