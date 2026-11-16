<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Adapter;

use HosmelQ\SearchSyntaxParser\AST\Visitor\VisitorInterface;
use HosmelQ\SearchSyntaxParser\SearchParser;

abstract readonly class AbstractAdapter implements QueryAdapterInterface, VisitorInterface
{
    public function __construct(protected null|SearchParser $searchParser = null) {}

    protected function getInternalFieldName(string $field): string
    {
        return $this->searchParser?->getInternalFieldName($field) ?? $field;
    }
}
