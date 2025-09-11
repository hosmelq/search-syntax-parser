<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Validation\Validators;

use HosmelQ\SearchSyntaxParser\Validation\Concerns\ValidatesAttributes;
use HosmelQ\SearchSyntaxParser\Validation\ValidatorInterface;

class MinValidator implements ValidatorInterface
{
    use ValidatesAttributes;

    /**
     * Create a minimum value validator.
     */
    public function __construct(private readonly float|int $min)
    {
    }

    /**
     * Validate that the value is at least the minimum.
     */
    public function __invoke(mixed $value, string $attribute): bool
    {
        return $this->validateMin($attribute, $value, [$this->min]);
    }
}
