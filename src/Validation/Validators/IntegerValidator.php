<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Validation\Validators;

use HosmelQ\SearchSyntaxParser\Validation\Concerns\ValidatesAttributes;
use HosmelQ\SearchSyntaxParser\Validation\ValidatorInterface;
use Override;

class IntegerValidator implements ValidatorInterface
{
    use ValidatesAttributes;

    #[Override]
    public function __invoke(mixed $value, string $attribute): bool
    {
        return $this->validateInteger($value);
    }
}
