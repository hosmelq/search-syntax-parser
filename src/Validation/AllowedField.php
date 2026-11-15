<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Validation;

use BackedEnum;
use Closure;
use HosmelQ\SearchSyntaxParser\Exception\InvalidFieldOperatorException;
use InvalidArgumentException;

class AllowedField
{
    /**
     * Default value for this field.
     *
     * @var null|bool|float|int|non-empty-list<bool|float|int|string>|string
     */
    protected null|array|bool|float|int|string $default = null;

    /**
     * Whether this field expects an array value.
     */
    protected bool $expectsArray = false;

    /**
     * Whether this field has a default value.
     */
    protected bool $hasDefault = false;

    /**
     * Values that remove query constraints after casting.
     *
     * @var array<int|string, mixed>
     */
    protected array $ignored = [];

    /**
     * Rules applied to a specific item index when the value is an array.
     *
     * @var array<int, AllowedFieldItemRules>
     */
    protected array $indexItemRules = [];

    /**
     * Internal field name for mapping.
     */
    protected string $internalName;

    protected AllowedFieldItemRules $itemRules;

    /**
     * Whether the stored value can be missing: a NULL column, optional relation, or absent key.
     */
    protected bool $nullable = false;

    protected AllowedFieldItemRules $rules;

    /**
     * @var array<string, bool|Closure|float|int|string>
     */
    private array $aliases = [];

    /**
     * @var array<string, true>
     */
    private array $disabledClauses = [];

    /**
     * @var null|list<string>
     */
    private null|array $operators = null;

    /**
     * Create a new field with the specified name.
     */
    final public function __construct(
        protected string $name,
        null|string $internalName = null,
    ) {
        $this->internalName = $internalName ?? $name;
        $this->rules = new AllowedFieldItemRules();
        $this->itemRules = new AllowedFieldItemRules();
    }

    /**
     * Create an array field (list of values).
     */
    public static function array(string $name, null|string $internalName = null): static
    {
        $field = new static($name, $internalName);
        $field->expectsArray = true;

        return $field;
    }

    /**
     * Create a boolean field (true/false).
     */
    public static function boolean(string $name, null|string $internalName = null): static
    {
        $field = new static($name, $internalName);

        $field->rules->boolean();

        return $field;
    }

    /**
     * Create a field with a custom validation callback.
     *
     * @param callable(mixed $value, string $attribute): bool $callback
     */
    public static function callback(
        string $name,
        callable $callback,
        null|string $internalName = null,
    ): static {
        $field = new static($name, $internalName);

        $field->rules->callback($callback);

        return $field;
    }

    /**
     * Create a date field, preserving its original text.
     */
    public static function date(
        string $name,
        null|string $format = null,
        null|string $internalName = null,
    ): static {
        $field = new static($name, $internalName);

        $field->rules->date($format);

        return $field;
    }

    /**
     * Create a decimal field with precision constraints.
     */
    public static function decimal(
        string $name,
        int $min,
        null|int $max = null,
        null|string $internalName = null,
    ): static {
        $field = new static($name, $internalName);

        $field->rules->decimal($min, $max);

        return $field;
    }

    /**
     * Create a field accepting the backing values of an enum.
     *
     * @param class-string<BackedEnum> $enum
     */
    public static function enum(
        string $name,
        string $enum,
        null|string $internalName = null,
    ): static {
        $field = new static($name, $internalName);

        $field->rules->enum($enum);

        return $field;
    }

    /**
     * Create a field that accepts only specified values.
     *
     * @param list<mixed> $values
     */
    public static function in(string $name, array $values, null|string $internalName = null): static
    {
        $field = new static($name, $internalName);

        $field->rules->in($values);

        return $field;
    }

    /**
     * Create an integer field.
     */
    public static function integer(string $name, null|string $internalName = null): static
    {
        $field = new static($name, $internalName);

        $field->rules->integer();

        return $field;
    }

    /**
     * Create a numeric field (integer or float).
     */
    public static function numeric(string $name, null|string $internalName = null): static
    {
        $field = new static($name, $internalName);

        $field->rules->numeric();

        return $field;
    }

    /**
     * Create a string field.
     */
    public static function string(string $name, null|string $internalName = null): static
    {
        $field = new static($name, $internalName);

        $field->rules->string();

        return $field;
    }

    /**
     * Replace an exact unquoted value with a scalar, or with the result of a closure called at parse time.
     */
    public function alias(string $token, bool|Closure|float|int|string $value): static
    {
        if (str_contains($token, '*')) {
            throw new InvalidArgumentException('A value alias token cannot contain a wildcard.');
        }

        $this->aliases[$token] = $value;

        return $this;
    }

    /**
     * Throw when the clause kind is disabled for this field.
     *
     * @internal
     */
    public function assertClauseAllowed(string $clause, int $position): void
    {
        if (isset($this->disabledClauses[$clause])) {
            throw InvalidFieldOperatorException::clauseNotAllowed($this->name, $clause, $position);
        }
    }

    /**
     * Throw when the operator is not allowed for this field.
     *
     * @internal
     */
    public function assertOperatorAllowed(string $operator, int $position): void
    {
        if ($this->operators !== null && ! in_array($operator, $this->operators, true)) {
            throw InvalidFieldOperatorException::operatorNotAllowed(
                $this->name,
                $operator,
                $position,
            );
        }
    }

    /**
     * Configure validators that apply to a specific item index when the value is an array.
     *
     * @param callable(AllowedFieldItemRules $rules): (AllowedFieldItemRules|void) $callback
     */
    public function at(int $index, callable $callback): static
    {
        $rules = $this->indexItemRules[$index] ??= new AllowedFieldItemRules();

        $callback($rules);

        return $this;
    }

    /**
     * Add a between constraint (min <= value <= max).
     */
    public function between(float|int $min, float|int $max): static
    {
        $this->rules->between($min, $max);

        return $this;
    }

    /**
     * Cast a field value, using index-specific or shared item types for arrays.
     */
    public function cast(string $value, bool $quoted, int $index = 0): bool|float|int|string
    {
        if (! $quoted && array_key_exists($value, $this->aliases)) {
            $alias = $this->aliases[$value];
            $resolved = $alias instanceof Closure ? $alias() : $alias;

            if (! is_scalar($resolved)) {
                throw new InvalidArgumentException(
                    'A value alias must resolve to a bool, float, int, or string.',
                );
            }

            return $resolved;
        }

        if ($this->expectsArray) {
            return isset($this->indexItemRules[$index])
                ? $this->indexItemRules[$index]->cast($value, $quoted, $this->itemRules)
                : $this->itemRules->cast($value, $quoted);
        }

        return $this->rules->cast($value, $quoted);
    }

    /**
     * Set a raw default value, validated against this field when the parser applies it.
     */
    public function default(mixed $value): static
    {
        $message = 'A default must be null, a scalar, or a non-empty list of scalars.';

        if ($value === null || is_scalar($value)) {
            $this->default = $value;
        } elseif (is_array($value) && $value !== [] && array_is_list($value)) {
            $this->default = array_map(
                static fn (mixed $item): bool|float|int|string => is_scalar($item)
                    ? $item : throw new InvalidArgumentException($message),
                $value,
            );
        } else {
            throw new InvalidArgumentException($message);
        }

        $this->hasDefault = true;

        if (is_null($value)) {
            $this->nullable();
        }

        return $this;
    }

    /**
     * Configure validators that apply to each item when the value is an array.
     *
     * @param callable(AllowedFieldItemRules $rules): (AllowedFieldItemRules|void) $callback
     */
    public function each(callable $callback): static
    {
        $callback($this->itemRules);

        return $this;
    }

    /**
     * Check whether this field expects an array value.
     */
    public function expectsArray(): bool
    {
        return $this->expectsArray;
    }

    /**
     * Get the default value for this field.
     *
     * @return null|bool|float|int|non-empty-list<bool|float|int|string>|string
     */
    public function getDefault(): null|array|bool|float|int|string
    {
        return $this->default;
    }

    /**
     * Get the internal field name used for mapping.
     */
    public function getInternalName(): string
    {
        return $this->internalName;
    }

    /**
     * Get the field name.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Check that a typed value has the type this field casts query text to at the given index.
     *
     * @internal
     */
    public function hasCastType(bool|float|int|string $value, int $index = 0): bool
    {
        if (! $this->expectsArray) {
            return $this->rules->hasCastType($value);
        }

        return isset($this->indexItemRules[$index])
            ? $this->indexItemRules[$index]->hasCastType($value, $this->itemRules)
            : $this->itemRules->hasCastType($value);
    }

    /**
     * Check if this field has a default value.
     */
    public function hasDefault(): bool
    {
        return $this->hasDefault;
    }

    /**
     * Check whether operators are restricted or clause kinds are disabled.
     *
     * @internal
     */
    public function hasUsageRules(): bool
    {
        return $this->operators !== null || $this->disabledClauses !== [];
    }

    /**
     * Remove query constraints whose cast values strictly match these values.
     */
    public function ignore(mixed ...$values): static
    {
        $this->ignored = array_merge($this->ignored, $values);

        return $this;
    }

    /**
     * Check whether a resolved and cast query value should be removed.
     */
    public function isIgnored(mixed $value): bool
    {
        return in_array($value, $this->ignored, true);
    }

    /**
     * Check whether the stored value can be missing.
     */
    public function isNullable(): bool
    {
        return $this->nullable;
    }

    /**
     * Add a maximum value constraint.
     */
    public function max(float|int $value): static
    {
        $this->rules->max($value);

        return $this;
    }

    /**
     * Add a minimum value constraint.
     */
    public function min(float|int $value): static
    {
        $this->rules->min($value);

        return $this;
    }

    /**
     * Declare that the stored value can be missing: a NULL column, optional relation, or absent key.
     * Adapters include missing values in complements; validate(null) also passes.
     */
    public function nullable(bool $nullable = true): static
    {
        $this->nullable = $nullable;

        return $this;
    }

    /**
     * Restrict the comparison operators accepted by comparisons, lists and wildcard patterns.
     */
    public function operators(string ...$operators): static
    {
        foreach ($operators as $operator) {
            if (! in_array($operator, ['=', '!=', '>', '>=', '<', '<='], true)) {
                throw new InvalidArgumentException(
                    sprintf("Unknown comparison operator '%s'.", $operator),
                );
            }
        }

        $this->operators = array_values($operators);

        return $this;
    }

    /**
     * Add an exact size constraint.
     */
    public function size(float|int $value): static
    {
        $this->rules->size($value);

        return $this;
    }

    /**
     * Cast and validate a candidate value, returning null when it does not validate.
     * Array candidates are validated as a one-item collection at the supplied index.
     */
    public function tryCast(
        string $value,
        bool $quoted = false,
        int $index = 0,
    ): null|bool|float|int|string {
        $cast = $this->cast($value, $quoted, $index);

        return $this->validate($this->expectsArray ? [$index => $cast] : $cast) ? $cast : null;
    }

    /**
     * Validate the given value against all configured validators.
     */
    public function validate(mixed $value): bool
    {
        if ($this->nullable && is_null($value)) {
            return true;
        }

        if (! $this->expectsArray) {
            return $this->rules->validate($value, $this->internalName);
        }

        if (! is_array($value) || ! $this->rules->validate($value, $this->internalName)) {
            return false;
        }

        foreach ($value as $index => $item) {
            $indexRules = $this->indexItemRules[$index] ?? null;
            $length = $indexRules?->measuresLength($this->itemRules)
                ?? $this->itemRules->measuresLength();

            if (! $this->itemRules->validate($item, $this->internalName, $length)
                || (
                    $indexRules instanceof AllowedFieldItemRules
                    && ! $indexRules->validate($item, $this->internalName, $length)
                )) {
                return false;
            }
        }

        return true;
    }

    /**
     * Reject exists queries such as field:* for this field.
     */
    public function withoutExists(): static
    {
        $this->disabledClauses['exists'] = true;

        return $this;
    }

    /**
     * Reject comma-separated lists for this field.
     */
    public function withoutLists(): static
    {
        $this->disabledClauses['lists'] = true;

        return $this;
    }

    /**
     * Reject range queries such as field:[a TO b] for this field.
     */
    public function withoutRanges(): static
    {
        $this->disabledClauses['ranges'] = true;

        return $this;
    }

    /**
     * Reject wildcard patterns such as field:abc* for this field.
     */
    public function withoutWildcards(): static
    {
        $this->disabledClauses['wildcards'] = true;

        return $this;
    }
}
