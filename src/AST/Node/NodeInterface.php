<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\AST\Node;

use HosmelQ\SearchSyntaxParser\AST\Span;
use HosmelQ\SearchSyntaxParser\AST\Visitor\VisitorInterface;

interface NodeInterface
{
    public function accept(VisitorInterface $visitor): mixed;

    /**
     * Source extent, or null for programmatic and default-generated nodes.
     */
    public function getSpan(): null|Span;

    public function getType(): string;
}
