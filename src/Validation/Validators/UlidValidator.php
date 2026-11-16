<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Validation\Validators;

use HosmelQ\SearchSyntaxParser\Validation\ValidatorInterface;
use Override;

final readonly class UlidValidator implements ValidatorInterface
{
    #[Override]
    public function __invoke(mixed $value, string $attribute): bool
    {
        return is_string($value) && preg_match('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/iD', $value) === 1;
    }
}
