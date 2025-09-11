<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Validation\Validators;

use HosmelQ\SearchSyntaxParser\Validation\Concerns\ValidatesAttributes;
use HosmelQ\SearchSyntaxParser\Validation\ValidatorInterface;

readonly class InValidator implements ValidatorInterface
{
    use ValidatesAttributes;

    /**
     * Create an in validator with the given allowed values.
     *
     * @param list<mixed> $values
     */
    public function __construct(private array $values)
    {
    }

    /**
     * Validate that the value is one of the allowed values.
     */
    public function __invoke(mixed $value, string $attribute): bool
    {
        return $this->validateIn($attribute, $value, $this->values);
    }
}
