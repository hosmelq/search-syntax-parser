<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Validation\Validators;

use HosmelQ\SearchSyntaxParser\Validation\Concerns\ValidatesAttributes;
use HosmelQ\SearchSyntaxParser\Validation\ValidatorInterface;

class SizeValidator implements ValidatorInterface
{
    use ValidatesAttributes;

    /**
     * Create a size validator for exact size matching.
     */
    public function __construct(private readonly float|int $size)
    {
    }

    /**
     * Validate that the value has exactly the specified size.
     */
    public function __invoke(mixed $value, string $attribute): bool
    {
        return $this->validateSize($attribute, $value, [$this->size]);
    }
}
