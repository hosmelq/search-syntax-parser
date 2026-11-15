<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Validation\Concerns;

use Brick\Math\BigNumber;
use Brick\Math\Exception\MathException;
use InvalidArgumentException;
use Stringable;

trait ValidatesAttributes
{
    protected function assertFinite(float|int ...$bounds): void
    {
        foreach ($bounds as $bound) {
            if (! is_finite($bound)) {
                throw new InvalidArgumentException('Size bounds must be finite.');
            }
        }
    }

    protected function getSize(
        mixed $value,
        bool $length = false,
    ): null|BigNumber {
        $size = match (true) {
            is_int($value), is_float($value) && is_finite($value) => $value,
            is_string($value) => ! $length && is_numeric($value) ? $value : mb_strlen($value),
            is_array($value) => count($value),
            default => null,
        };

        if ($size === null) {
            return null;
        }

        try {
            return BigNumber::of((string) $size);
        } catch (MathException) {
            return null;
        }
    }

    /**
     * @param array{float|int, float|int} $parameters
     */
    protected function validateBetween(
        mixed $value,
        array $parameters,
        bool $length = false,
    ): bool {
        $size = $this->getSize($value, $length);

        if ($size === null) {
            return false;
        }

        return $size->isGreaterThanOrEqualTo((string) $parameters[0])
            && $size->isLessThanOrEqualTo((string) $parameters[1]);
    }

    protected function validateBoolean(mixed $value): bool
    {
        $acceptable = [false, true, 0, 1, '0', '1'];

        return in_array($value, $acceptable, true);
    }

    /**
     * @param array{int, int} $parameters
     */
    protected function validateDecimal(mixed $value, array $parameters): bool
    {
        if (! $this->validateNumeric($value)) {
            return false;
        }

        if (preg_match('/^[+-]?\d*\.?(\d*)$/D', (string) $value, $matches) !== 1) {
            return false;
        }

        $decimals = mb_strlen($matches[1]);

        return $decimals >= $parameters[0] && $decimals <= $parameters[1];
    }

    /**
     * @param list<mixed> $parameters
     */
    protected function validateIn(mixed $value, array $parameters): bool
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

    protected function validateInteger(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_INT) !== false;
    }

    /**
     * @param array{float|int} $parameters
     */
    protected function validateMax(
        mixed $value,
        array $parameters,
        bool $length = false,
    ): bool {
        $size = $this->getSize($value, $length);

        if ($size === null) {
            return false;
        }

        return $size->isLessThanOrEqualTo((string) $parameters[0]);
    }

    /**
     * @param array{float|int} $parameters
     */
    protected function validateMin(
        mixed $value,
        array $parameters,
        bool $length = false,
    ): bool {
        $size = $this->getSize($value, $length);

        if ($size === null) {
            return false;
        }

        return $size->isGreaterThanOrEqualTo((string) $parameters[0]);
    }

    /**
     * @phpstan-assert-if-true float|int|numeric-string $value
     */
    protected function validateNumeric(mixed $value): bool
    {
        return is_numeric($value) && (! is_float($value) || is_finite($value));
    }

    /**
     * @param array{float|int} $parameters
     */
    protected function validateSize(
        mixed $value,
        array $parameters,
        bool $length = false,
    ): bool {
        $size = $this->getSize($value, $length);

        if ($size === null) {
            return false;
        }

        return $size->isEqualTo((string) $parameters[0]);
    }

    protected function validateString(mixed $value): bool
    {
        return is_string($value);
    }
}
