<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Validation\Validators;

use HosmelQ\SearchSyntaxParser\Validation\ValidatorInterface;
use Override;

final readonly class UuidValidator implements ValidatorInterface
{
    #[Override]
    public function __invoke(mixed $value, string $attribute): bool
    {
        return is_string($value)
            && preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD',
                $value,
            ) === 1;
    }
}
