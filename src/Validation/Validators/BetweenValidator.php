<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Validation\Validators;

use HosmelQ\SearchSyntaxParser\Validation\Concerns\ValidatesAttributes;
use HosmelQ\SearchSyntaxParser\Validation\ValidatorInterface;

readonly class BetweenValidator implements ValidatorInterface
{
    use ValidatesAttributes;

    /**
     * Create a between validator with min and max constraints.
     */
    public function __construct(private float|int $min, private float|int $max)
    {
    }

    /**
     * Validate that the value is between min and max (inclusive).
     */
    public function __invoke(mixed $value, string $attribute): bool
    {
        return $this->validateBetween($attribute, $value, [$this->min, $this->max]);
    }
}
