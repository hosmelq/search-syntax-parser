<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\AST\Node;

use HosmelQ\SearchSyntaxParser\AST\Visitor\VisitorInterface;

interface NodeInterface
{
    /**
     * Accept a visitor for traversing or transforming the AST.
     */
    public function accept(VisitorInterface $visitor): mixed;

    /**
     * Gets the type of the node.
     */
    public function getType(): string;
}
