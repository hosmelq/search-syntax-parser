<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Validation\Validators;

use HosmelQ\SearchSyntaxParser\Validation\Concerns\ValidatesAttributes;
use HosmelQ\SearchSyntaxParser\Validation\ValidatorInterface;
use Safe\Exceptions\PcreException;

readonly class DecimalValidator implements ValidatorInterface
{
    use ValidatesAttributes;

    /**
     * Create a decimal validator with decimal place constraints.
     */
    public function __construct(private int $min, private null|int $max = null)
    {
    }

    /**
     * Validate that the value is numeric and has the specified decimal places.
     *
     * @throws PcreException
     */
    public function __invoke(mixed $value, string $attribute): bool
    {
        $parameters = $this->max !== null
            ? [$this->min, $this->max]
            : [$this->min];

        return $this->validateDecimal($attribute, $value, $parameters);
    }
}
