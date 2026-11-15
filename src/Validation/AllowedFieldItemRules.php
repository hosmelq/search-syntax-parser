<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Validation;

use BackedEnum;
use Closure;
use HosmelQ\SearchSyntaxParser\Exception\ParseException;
use HosmelQ\SearchSyntaxParser\Validation\Validators\BetweenValidator;
use HosmelQ\SearchSyntaxParser\Validation\Validators\BooleanValidator;
use HosmelQ\SearchSyntaxParser\Validation\Validators\CallbackValidator;
use HosmelQ\SearchSyntaxParser\Validation\Validators\DateValidator;
use HosmelQ\SearchSyntaxParser\Validation\Validators\DecimalValidator;
use HosmelQ\SearchSyntaxParser\Validation\Validators\IntegerValidator;
use HosmelQ\SearchSyntaxParser\Validation\Validators\InValidator;
use HosmelQ\SearchSyntaxParser\Validation\Validators\MaxValidator;
use HosmelQ\SearchSyntaxParser\Validation\Validators\MinValidator;
use HosmelQ\SearchSyntaxParser\Validation\Validators\NumericValidator;
use HosmelQ\SearchSyntaxParser\Validation\Validators\SizeValidator;
use HosmelQ\SearchSyntaxParser\Validation\Validators\StringValidator;
use HosmelQ\SearchSyntaxParser\Validation\Validators\UlidValidator;
use HosmelQ\SearchSyntaxParser\Validation\Validators\UuidValidator;
use InvalidArgumentException;

/**
 * Builder for per-item validation rules on array fields.
 *
 * Use with AllowedField::array(...)->each(fn ($rules) => $rules->string()->max(20)).
 */
final class AllowedFieldItemRules
{
    /**
     * @var array<string, int|string>
     */
    private array $caseNames = [];

    private bool $partialDates = false;

    /**
     * @var null|'boolean'|'date'|'decimal'|'in'|'integer'|'numeric'|'string'|'ulid'|'uuid'
     */
    private null|string $type = null;

    /**
     * @var list<ValidatorInterface>
     */
    private array $validators = [];

    /**
     * @var list<mixed>
     */
    private array $values = [];

    private bool $withoutTime = false;

    /**
     * Infer untyped values without losing identifiers or quoted text.
     */
    public static function infer(string $value, bool $quoted): bool|float|int|string
    {
        if ($quoted) {
            return $value;
        }

        $boolean = match (mb_strtolower($value)) {
            'true' => true,
            'false' => false,
            default => null,
        };

        if ($boolean !== null) {
            return $boolean;
        }

        if (! is_numeric($value)) {
            return $value;
        }

        $integer = filter_var($value, FILTER_VALIDATE_INT);

        if ($integer !== false) {
            return $integer;
        }

        $decimal = preg_match('/^-?(0|[1-9]\d*)\.\d+$/D', $value);

        if ($decimal === false) {
            throw ParseException::tokenizationFailed();
        }

        if ($decimal !== 1) {
            return $value;
        }

        $float = (float) $value;

        // A decimal beyond the float range stays text, like an overflowing integer.
        return is_finite($float) ? $float : $value;
    }

    /**
     * Accept whole years and months with a date rule without a format.
     */
    public function allowPartialDates(): self
    {
        if ($this->type !== 'date') {
            throw new InvalidArgumentException(
                'Partial dates require a date rule without a format.',
            );
        }

        foreach ($this->validators as $index => $validator) {
            if ($validator instanceof DateValidator) {
                $this->validators[$index] = $validator->withPartialDates();
            }
        }

        $this->partialDates = true;

        return $this;
    }

    /**
     * Require size/value between min and max for each item.
     */
    public function between(float|int $min, float|int $max): self
    {
        $this->validators[] = new BetweenValidator($min, $max);

        return $this;
    }

    /**
     * Require each item to be boolean or boolean-like.
     */
    public function boolean(): self
    {
        $this->setType('boolean');

        $this->validators[] = new BooleanValidator();

        return $this;
    }

    /**
     * Validate each item using a custom callback.
     *
     * @param callable(mixed $value, string $attribute): bool $callback
     */
    public function callback(callable $callback): self
    {
        $this->validators[] = new CallbackValidator(Closure::fromCallable($callback));

        return $this;
    }

    /**
     * Cast raw text using this type or the fallback rules' type.
     */
    public function cast(
        string $value,
        bool $quoted,
        null|self $fallback = null,
    ): bool|float|int|string {
        if ($this->type === null) {
            return $fallback?->cast($value, $quoted) ?? self::infer($value, $quoted);
        }

        return match ($this->type) {
            'string', 'decimal', 'date' => $value,
            'integer' => filter_var($value, FILTER_VALIDATE_INT) !== false ? (int) $value : $value,
            'numeric' => filter_var($value, FILTER_VALIDATE_INT) !== false
                ? (int) $value
                : (is_numeric($value) ? (float) $value : $value),
            'boolean' => $this->toBoolean($value) ?? $value,
            'in' => $this->castMember($value, $quoted),
            'ulid' => strtr($value, 'abcdefghijklmnopqrstuvwxyz', 'ABCDEFGHIJKLMNOPQRSTUVWXYZ'),
            'uuid' => mb_strtolower($value),
        };
    }

    /**
     * Require a valid date or datetime, preserving its raw text.
     */
    public function date(null|string $format = null): self
    {
        $validator = new DateValidator($format);

        $this->setType('date');

        if ($this->partialDates) {
            $validator = $validator->withPartialDates();
        }

        if ($this->withoutTime) {
            $validator = $validator->withoutTime();
        }

        $this->validators[] = $validator;

        return $this;
    }

    /**
     * Require each numeric item to have decimal places within the range.
     */
    public function decimal(int $min, null|int $max = null): self
    {
        $this->setType('decimal');

        $this->validators[] = new DecimalValidator($min, $max);

        return $this;
    }

    /**
     * Require a backing value or case name from a backed enum, preferring values over names.
     *
     * @param class-string<BackedEnum> $enum
     */
    public function enum(string $enum): self
    {
        if (! is_subclass_of($enum, BackedEnum::class)) {
            throw new InvalidArgumentException('An enum rule requires a backed enum.');
        }

        $this->in(
            array_map(static fn (BackedEnum $case): int|string => $case->value, $enum::cases()),
        );

        foreach ($enum::cases() as $case) {
            $name = mb_strtolower($case->name);

            if (array_key_exists($name, $this->caseNames)) {
                throw new InvalidArgumentException('Enum case names must be unique ignoring case.');
            }

            $this->caseNames[$name] = $case->value;
        }

        return $this;
    }

    /**
     * Get the configured cast type for field metadata constraints.
     *
     * @internal
     *
     * @return null|'boolean'|'date'|'decimal'|'in'|'integer'|'numeric'|'string'|'ulid'|'uuid'
     */
    public function getType(): null|string
    {
        return $this->type;
    }

    /**
     * @return list<ValidatorInterface>
     */
    public function getValidators(): array
    {
        return $this->validators;
    }

    /**
     * Check that a typed value has the type query text is cast to, using the fallback rules when untyped.
     *
     * @internal
     */
    public function hasCastType(bool|float|int|string $value, null|self $fallback = null): bool
    {
        $rules = $this->type === null && $fallback instanceof self ? $fallback : $this;

        return match ($rules->type) {
            null => true,
            'boolean' => is_bool($value),
            'integer' => is_int($value),
            'numeric' => is_int($value) || is_float($value),
            'date', 'decimal', 'string', 'ulid', 'uuid' => is_string($value),
            'in' => in_array($value, $rules->values, true),
        };
    }

    /**
     * Require each item to be in the given list.
     *
     * @param list<mixed> $values
     */
    public function in(array $values): self
    {
        $validator = new InValidator($values);
        $this->caseNames = [];

        $this->setType('in');

        $this->values = $values;
        $this->validators[] = $validator;

        return $this;
    }

    /**
     * Require each item to be an integer.
     */
    public function integer(): self
    {
        $this->setType('integer');

        $this->validators[] = new IntegerValidator();

        return $this;
    }

    /**
     * Require each item to have size/value <= given max.
     */
    public function max(float|int $value): self
    {
        $this->validators[] = new MaxValidator($value);

        return $this;
    }

    /**
     * Check whether size rules measure strings by length, using the fallback rules' type when untyped.
     */
    public function measuresLength(null|self $fallback = null): bool
    {
        return ($this->type ?? $fallback?->type) === 'string';
    }

    /**
     * Require each item to have size/value >= given min.
     */
    public function min(float|int $value): self
    {
        $this->validators[] = new MinValidator($value);

        return $this;
    }

    /**
     * Require each item to be numeric.
     */
    public function numeric(): self
    {
        $this->setType('numeric');

        $this->validators[] = new NumericValidator();

        return $this;
    }

    /**
     * Require each item to have size/value equal to given size.
     */
    public function size(float|int $value): self
    {
        $this->validators[] = new SizeValidator($value);

        return $this;
    }

    /**
     * Require each item to be a string.
     */
    public function string(): self
    {
        $this->setType('string');

        $this->validators[] = new StringValidator();

        return $this;
    }

    /**
     * Require a ULID and normalize its text to uppercase.
     */
    public function ulid(): self
    {
        $this->setType('ulid');

        $this->validators[] = new UlidValidator();

        return $this;
    }

    /**
     * Require a UUID and normalize its text to lowercase.
     */
    public function uuid(): self
    {
        $this->setType('uuid');

        $this->validators[] = new UuidValidator();

        return $this;
    }

    /**
     * Validate the value; string types size strings by length regardless of rule order.
     */
    public function validate(mixed $value, string $attribute, null|bool $length = null): bool
    {
        $length ??= $this->measuresLength();

        foreach ($this->validators as $validator) {
            $valid = $validator instanceof MinValidator || $validator instanceof MaxValidator
                || $validator instanceof BetweenValidator || $validator instanceof SizeValidator
                ? $validator($value, $attribute, $length)
                : $validator($value, $attribute);

            if (! $valid) {
                return false;
            }
        }

        return true;
    }

    /**
     * Accept only calendar dates with a date rule without a format, rejecting times and instants.
     */
    public function withoutTime(): self
    {
        if ($this->type !== 'date') {
            throw new InvalidArgumentException(
                'Dates without time require a date rule without a format.',
            );
        }

        foreach ($this->validators as $index => $validator) {
            if ($validator instanceof DateValidator) {
                $this->validators[$index] = $validator->withoutTime();
            }
        }

        $this->withoutTime = true;

        return $this;
    }

    /**
     * Match boolean allowed values like boolean(), and other scalars by text or inferred number.
     */
    private function castMember(string $value, bool $quoted): bool|float|int|string
    {
        $boolean = $this->toBoolean($value);
        $inferred = self::infer($value, $quoted);
        $candidates = is_int($inferred) || is_float($inferred)
            ? [$value, (string) $inferred]
            : [$value];
        $strings = array_map(mb_strtolower(...), $candidates);

        foreach ($this->values as $allowed) {
            if (
                is_bool($allowed)
                ? $boolean === $allowed
                : (is_string($allowed)
                    ? in_array(mb_strtolower($allowed), $strings, true)
                    : is_scalar($allowed) && in_array((string) $allowed, $candidates, true))
            ) {
                return $allowed;
            }
        }

        return $this->caseNames[mb_strtolower($value)] ?? $value;
    }

    /**
     * @param 'boolean'|'date'|'decimal'|'in'|'integer'|'numeric'|'string'|'ulid'|'uuid' $type
     */
    private function setType(string $type): void
    {
        if ($this->partialDates && $type !== 'date') {
            throw new InvalidArgumentException(
                'Partial dates require a date rule without a format.',
            );
        }

        if ($this->withoutTime && $type !== 'date') {
            throw new InvalidArgumentException(
                'Dates without time require a date rule without a format.',
            );
        }

        $this->type = $type;
    }

    /**
     * Map true/1 and false/0, case-insensitively, to booleans.
     */
    private function toBoolean(string $value): null|bool
    {
        return match (mb_strtolower($value)) {
            'true', '1' => true,
            'false', '0' => false,
            default => null,
        };
    }
}
