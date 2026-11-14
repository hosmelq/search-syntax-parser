<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Validation\Concerns;

use function Safe\preg_match;

use Brick\Math\BigNumber;
use Brick\Math\Exception\MathException;
use InvalidArgumentException;
use Safe\Exceptions\PcreException;
use Stringable;
use TypeError;

trait ValidatesAttributes
{
    /**
     * Get the size of an attribute.
     */
    protected function getSize(string $attribute, mixed $value): float|int|string
    {
        if (is_numeric($value)) {
            return $value;
        }

        if (is_array($value)) {
            return count($value);
        }

        if ($value !== null && ! is_string($value)) {
            throw new TypeError('Attribute size requires a number, string, array, or null.');
        }

        return mb_strlen($value ?? '');
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
    protected function validateBetween(string $attribute, mixed $value, array $parameters): bool
    {
        $this->requireParameterCount(2, $parameters, 'between');

        try {
            $size = BigNumber::of((string) $this->getSize($attribute, $value));

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
     *
     * @throws PcreException
     */
    protected function validateDecimal(string $attribute, mixed $value, array $parameters): bool
    {
        $this->requireParameterCount(1, $parameters, 'decimal');

        if (! $this->validateNumeric($attribute, $value)) {
            return false;
        }

        $matches = [];

        if (preg_match('/^[+-]?\d*\.?(\d*)$/', (string) $value, $matches) !== 1) {
            return false;
        }

        $decimals = mb_strlen(end($matches)); // @phpstan-ignore-line argument.type

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
    protected function validateMax(string $attribute, mixed $value, array $parameters): bool
    {
        $this->requireParameterCount(1, $parameters, 'max');

        try {
            return BigNumber::of((string) $this->getSize($attribute, $value))
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
    protected function validateMin(string $attribute, mixed $value, array $parameters): bool
    {
        $this->requireParameterCount(1, $parameters, 'min');

        try {
            return BigNumber::of((string) $this->getSize($attribute, $value))
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
        return is_numeric($value);
    }

    /**
     * Validate the size of an attribute.
     *
     * @param list<float|int> $parameters
     */
    protected function validateSize(string $attribute, mixed $value, array $parameters): bool
    {
        $this->requireParameterCount(1, $parameters, 'size');

        try {
            return BigNumber::of((string) $this->getSize($attribute, $value))
                ->isEqualTo((string) $this->trim($parameters[0]));
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
