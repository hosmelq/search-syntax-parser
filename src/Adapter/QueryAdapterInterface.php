<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Adapter;

use HosmelQ\SearchSyntaxParser\AST\Node\NodeInterface;

interface QueryAdapterInterface
{
    public function build(NodeInterface $ast): mixed;
}
