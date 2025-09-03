<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Validation\Validators;

use HosmelQ\SearchSyntaxParser\Validation\Concerns\ValidatesAttributes;
use HosmelQ\SearchSyntaxParser\Validation\ValidatorInterface;

class MaxValidator implements ValidatorInterface
{
    use ValidatesAttributes;

    /**
     * Create a maximum value validator.
     */
    public function __construct(private readonly float|int $max)
    {
    }

    /**
     * Validate that the value is at most the maximum.
     */
    public function __invoke(mixed $value, string $attribute): bool
    {
        return $this->validateMax($attribute, $value, [$this->max]);
    }
}
