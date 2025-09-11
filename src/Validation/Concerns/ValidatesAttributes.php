<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Validation\Concerns;

use function Safe\preg_match;

use Brick\Math\BigNumber;
use Brick\Math\Exception\MathException;
use InvalidArgumentException;
use Safe\Exceptions\PcreException;

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
            throw new InvalidArgumentException(sprintf('Validation rule %s requires at least %d parameters.', $rule, $count));
        }
    }

    /**
     * Trim the value if it is a string.
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
            $size = BigNumber::of($this->getSize($attribute, $value));

            return $size->isGreaterThanOrEqualTo($this->trim($parameters[0]))
                && $size->isLessThanOrEqualTo($this->trim($parameters[1]));
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
     * @param list<int|string> $parameters
     */
    protected function validateIn(string $attribute, mixed $value, array $parameters): bool
    {
        if (is_array($value)) {
            foreach ($value as $element) {
                if (is_array($element)) {
                    return false;
                }
            }

            return array_diff($value, $parameters) === [];
        }

        // @phpstan-ignore-next-line cast.string function.impossibleType
        return ! is_array($value) && in_array((string) $value, array_map('strval', $parameters), true);
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
            return BigNumber::of($this->getSize($attribute, $value))->isLessThanOrEqualTo($this->trim($parameters[0]));
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
            return BigNumber::of($this->getSize($attribute, $value))->isGreaterThanOrEqualTo($this->trim($parameters[0]));
        } catch (MathException) {
            return false;
        }
    }

    /**
     * Validate that an attribute is numeric.
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
            return BigNumber::of($this->getSize($attribute, $value))->isEqualTo($this->trim($parameters[0]));
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
