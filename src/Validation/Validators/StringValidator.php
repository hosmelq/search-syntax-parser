<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Validation\Validators;

use HosmelQ\SearchSyntaxParser\Validation\Concerns\ValidatesAttributes;
use HosmelQ\SearchSyntaxParser\Validation\ValidatorInterface;

class StringValidator implements ValidatorInterface
{
    use ValidatesAttributes;

    /**
     * Validate that the value is a string.
     */
    public function __invoke(mixed $value, string $attribute): bool
    {
        return $this->validateString($attribute, $value);
    }
}
