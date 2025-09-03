<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Validation\Validators;

use HosmelQ\SearchSyntaxParser\Validation\Concerns\ValidatesAttributes;
use HosmelQ\SearchSyntaxParser\Validation\ValidatorInterface;

class NumericValidator implements ValidatorInterface
{
    use ValidatesAttributes;

    /**
     * Validate that the value is numeric.
     */
    public function __invoke(mixed $value, string $attribute): bool
    {
        return $this->validateNumeric($attribute, $value);
    }
}
