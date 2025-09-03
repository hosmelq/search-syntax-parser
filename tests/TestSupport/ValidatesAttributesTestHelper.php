<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Tests\TestSupport;

use HosmelQ\SearchSyntaxParser\Validation\Concerns\ValidatesAttributes;

class ValidatesAttributesTestHelper
{
    use ValidatesAttributes;

    public function between(string $attribute, mixed $value, array $parameters): bool
    {
        return $this->validateBetween($attribute, $value, $parameters);
    }

    public function isBoolean(string $attribute, mixed $value): bool
    {
        return $this->validateBoolean($attribute, $value);
    }

    public function isInteger(string $attribute, mixed $value): bool
    {
        return $this->validateInteger($attribute, $value);
    }

    public function isNumeric(string $attribute, mixed $value): bool
    {
        return $this->validateNumeric($attribute, $value);
    }

    public function isString(string $attribute, mixed $value): bool
    {
        return $this->validateString($attribute, $value);
    }

    public function max(string $attribute, mixed $value, array $parameters): bool
    {
        return $this->validateMax($attribute, $value, $parameters);
    }

    public function min(string $attribute, mixed $value, array $parameters): bool
    {
        return $this->validateMin($attribute, $value, $parameters);
    }

    public function requireParams(int $count, array $parameters, string $rule): void
    {
        $this->requireParameterCount($count, $parameters, $rule);
    }

    public function size(string $attribute, mixed $value, array $parameters): bool
    {
        return $this->validateSize($attribute, $value, $parameters);
    }

    public function sizeOf(string $attribute, mixed $value): float|int|string
    {
        return $this->getSize($attribute, $value);
    }

    public function trimValue(mixed $value): mixed
    {
        return $this->trim($value);
    }
}
