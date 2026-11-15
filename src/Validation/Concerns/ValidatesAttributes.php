<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Validation\Concerns;

use Brick\Math\BigNumber;
use Brick\Math\Exception\MathException;
use InvalidArgumentException;
use Stringable;

trait ValidatesAttributes
{
    /**
     * Get the size of an attribute: numbers and numeric strings by value unless $length is set,
     * other strings by character length, and arrays by item count.
     */
    protected function getSize(
        string $attribute,
        mixed $value,
        bool $length = false,
    ): null|float|int|string {
        return match (true) {
            is_int($value), is_float($value) => $value,
            is_string($value) => ! $length && is_numeric($value) ? $value : mb_strlen($value),
            is_array($value) => count($value),
            default => null,
        };
    }

    /**
     * Require a certain number of parameters to be present.
     *
     * @param list<float|int|string> $parameters
     */
    protected function requireParameterCount(int $count, array $parameters, string $rule): void
    {
        if (count($parameters) < $count) {
            throw new InvalidArgumentException(
                sprintf('Validation rule %s requires at least %d parameters.', $rule, $count),
            );
        }
    }

    /**
     * Trim the value if it is a string.
     *
     * @template TValue
     *
     * @param TValue $value
     *
     * @return (TValue is string ? string : TValue)
     */
    protected function trim(mixed $value): mixed
    {
        return is_string($value) ? mb_trim($value) : $value;
    }

    /**
     * Validate the size of an attribute is between a set of values.
     *
     * @param list<float|int> $parameters
     */
    protected function validateBetween(
        string $attribute,
        mixed $value,
        array $parameters,
        bool $length = false,
    ): bool {
        $this->requireParameterCount(2, $parameters, 'between');

        $size = $this->getSize($attribute, $value, $length);

        if ($size === null) {
            return false;
        }

        try {
            $size = BigNumber::of((string) $size);

            return $size->isGreaterThanOrEqualTo((string) $this->trim($parameters[0]))
                && $size->isLessThanOrEqualTo((string) $this->trim($parameters[1]));
        } catch (MathException) {
            return false;
        }
    }

    /**
     * Validate that an attribute is a boolean.
     */
    protected function validateBoolean(string $attribute, mixed $value): bool
    {
        $acceptable = [false, true, 0, 1, '0', '1'];

        return in_array($value, $acceptable, true);
    }

    /**
     * Validate that an attribute has a given number of decimal places.
     *
     * @param list<int> $parameters
     */
    protected function validateDecimal(string $attribute, mixed $value, array $parameters): bool
    {
        $this->requireParameterCount(1, $parameters, 'decimal');

        if (! $this->validateNumeric($attribute, $value)) {
            return false;
        }

        $matches = [];

        if (preg_match('/^[+-]?\d*\.?(\d*)$/D', (string) $value, $matches) !== 1) {
            return false;
        }

        $decimals = mb_strlen($matches[1]);

        if (! isset($parameters[1])) {
            return $decimals === $parameters[0];
        }

        return $decimals >= $parameters[0] && $decimals <= $parameters[1];
    }

    /**
     * Validate an attribute is contained within a list of values.
     *
     * @param list<mixed> $parameters
     */
    protected function validateIn(string $attribute, mixed $value, array $parameters): bool
    {
        $allowed = [];

        foreach ($parameters as $parameter) {
            if (
                $parameter !== null
                && ! is_scalar($parameter)
                && ! is_resource($parameter)
                && ! $parameter instanceof Stringable
            ) {
                return false;
            }

            $allowed[] = (string) $parameter;
        }

        if (is_array($value)) {
            $values = [];

            foreach ($value as $element) {
                if (
                    $element !== null
                    && ! is_scalar($element)
                    && ! is_resource($element)
                    && ! $element instanceof Stringable
                ) {
                    return false;
                }

                $values[] = (string) $element;
            }

            return array_diff($values, $allowed) === [];
        }

        if (
            $value !== null
            && ! is_scalar($value)
            && ! is_resource($value)
            && ! $value instanceof Stringable
        ) {
            return false;
        }

        return in_array((string) $value, $allowed, true);
    }

    /**
     * Validate that an attribute is an integer.
     */
    protected function validateInteger(string $attribute, mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_INT) !== false;
    }

    /**
     * Validate the size of an attribute is less than or equal to a maximum value.
     *
     * @param list<float|int> $parameters
     */
    protected function validateMax(
        string $attribute,
        mixed $value,
        array $parameters,
        bool $length = false,
    ): bool {
        $this->requireParameterCount(1, $parameters, 'max');

        $size = $this->getSize($attribute, $value, $length);

        if ($size === null) {
            return false;
        }

        try {
            return BigNumber::of((string) $size)
                ->isLessThanOrEqualTo((string) $this->trim($parameters[0]));
        } catch (MathException) {
            return false;
        }
    }

    /**
     * Validate the size of an attribute is greater than or equal to a minimum value.
     *
     * @param list<float|int> $parameters
     */
    protected function validateMin(
        string $attribute,
        mixed $value,
        array $parameters,
        bool $length = false,
    ): bool {
        $this->requireParameterCount(1, $parameters, 'min');

        $size = $this->getSize($attribute, $value, $length);

        if ($size === null) {
            return false;
        }

        try {
            return BigNumber::of((string) $size)
                ->isGreaterThanOrEqualTo((string) $this->trim($parameters[0]));
        } catch (MathException) {
            return false;
        }
    }

    /**
     * Validate that an attribute is numeric.
     *
     * @phpstan-assert-if-true float|int|numeric-string $value
     */
    protected function validateNumeric(string $attribute, mixed $value): bool
    {
        return is_numeric($value) && (! is_float($value) || is_finite($value));
    }

    /**
     * Validate the size of an attribute.
     *
     * @param list<float|int> $parameters
     */
    protected function validateSize(
        string $attribute,
        mixed $value,
        array $parameters,
        bool $length = false,
    ): bool {
        $this->requireParameterCount(1, $parameters, 'size');

        $size = $this->getSize($attribute, $value, $length);

        if ($size === null) {
            return false;
        }

        try {
            return BigNumber::of((string) $size)->isEqualTo((string) $this->trim($parameters[0]));
        } catch (MathException) {
            return false;
        }
    }

    /**
     * Validate that an attribute is a string.
     */
    protected function validateString(string $attribute, mixed $value): bool
    {
        return is_string($value);
    }
}
