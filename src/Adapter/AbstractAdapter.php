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
        if (is_null($this->searchParser)) {
            return $field;
        }

        $allowedField = $this->searchParser->getAllowedField($field);

        if ($allowedField?->isPattern() === true) {
            return mb_substr($allowedField->getInternalName(), 0, -1)
                .mb_substr($field, mb_strlen($allowedField->getName()) - 1);
        }

        return $allowedField?->getInternalName() ?? $field;
    }
}
