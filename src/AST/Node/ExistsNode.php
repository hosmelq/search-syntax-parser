<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\AST\Node;

use HosmelQ\SearchSyntaxParser\AST\Visitor\VisitorInterface;
use Override;

readonly class ExistsNode implements NodeInterface
{
    /**
     * Create a new exists node.
     */
    public function __construct(private string $field) {}

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function accept(VisitorInterface $visitor): mixed
    {
        return $visitor->visitExists($this);
    }

    /**
     * Get the field name for this exists query.
     */
    public function getField(): string
    {
        return $this->field;
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function getType(): string
    {
        return 'Exists';
    }
}
