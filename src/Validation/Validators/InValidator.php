<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Validation\Validators;

use HosmelQ\SearchSyntaxParser\Validation\Concerns\ValidatesAttributes;
use HosmelQ\SearchSyntaxParser\Validation\ValidatorInterface;
use InvalidArgumentException;
use Override;

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
        $seen = [];

        foreach ($values as $value) {
            if (! is_string($value)) {
                continue;
            }

            $key = mb_strtolower($value);

            if (isset($seen[$key])) {
                throw new InvalidArgumentException(
                    'Allowed string values must be unique ignoring case.',
                );
            }

            $seen[$key] = true;
        }
    }

    /**
     * Validate that the value is one of the allowed values.
     */
    #[Override]
    public function __invoke(mixed $value, string $attribute): bool
    {
        return $this->validateIn($attribute, $value, $this->values);
    }
}
