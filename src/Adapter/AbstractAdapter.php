<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Adapter;

use HosmelQ\SearchSyntaxParser\AST\Visitor\VisitorInterface;
use HosmelQ\SearchSyntaxParser\SearchParser;

abstract readonly class AbstractAdapter implements QueryAdapterInterface, VisitorInterface
{
    /**
     * Create a new adapter instance.
     */
    public function __construct(protected null|SearchParser $searchParser = null)
    {
    }

    /**
     * Get the internal field name for a given external field name.
     */
    protected function getInternalFieldName(string $field): string
    {
        if (is_null($this->searchParser)) {
            return $field;
        }

        $allowedField = $this->searchParser->getAllowedField($field);

        return $allowedField?->getInternalName() ?? $field;
    }
}
