<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Validation;

interface ValidatorInterface
{
    public function __invoke(mixed $value, string $attribute): bool;
}
