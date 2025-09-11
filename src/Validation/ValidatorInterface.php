<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Validation;

interface ValidatorInterface
{
    /**
     * Validate a field value from a search query.
     */
    public function __invoke(mixed $value, string $attribute): bool;
}
