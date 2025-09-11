<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Adapter;

use HosmelQ\SearchSyntaxParser\AST\Node\NodeInterface;

interface QueryAdapterInterface
{
    /**
     * Convert the AST to the target query format.
     */
    public function build(NodeInterface $ast): mixed;
}
