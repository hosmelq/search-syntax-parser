<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Validation\Validators;

use HosmelQ\SearchSyntaxParser\Validation\Concerns\ValidatesAttributes;
use HosmelQ\SearchSyntaxParser\Validation\ValidatorInterface;
use Override;

class BooleanValidator implements ValidatorInterface
{
    use ValidatesAttributes;

    /**
     * Validate that the value is a boolean or boolean-like value.
     */
    #[Override]
    public function __invoke(mixed $value, string $attribute): bool
    {
        return $this->validateBoolean($attribute, $value);
    }
}
