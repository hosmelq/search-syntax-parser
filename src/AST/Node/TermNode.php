<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\AST\Node;

use HosmelQ\SearchSyntaxParser\AST\Visitor\VisitorInterface;

readonly class TermNode implements NodeInterface
{
    /**
     * Create a new term node.
     */
    public function __construct(private string $value)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function accept(VisitorInterface $visitor): mixed
    {
        return $visitor->visitTerm($this);
    }

    /**
     * {@inheritDoc}
     */
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
