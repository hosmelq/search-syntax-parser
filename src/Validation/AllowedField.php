<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Validation;

class AllowedField
{
    /**
     * Default value for this field.
     */
    protected mixed $default = null;

    /**
     * Whether this field expects an array value.
     */
    protected bool $expectsArray = false;

    /**
     * Whether this field has a default value.
     */
    protected bool $hasDefault = false;

    /**
     * Values that should be ignored during validation.
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
     * Whether this field allows null values.
     */
    protected bool $nullable = false;

    protected AllowedFieldItemRules $rules;

    /**
     * Create a new field with the specified name.
     */
    public function __construct(
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
    public static function array(string $name, null|string $internalName = null): self
    {
        $field = new self($name, $internalName);
        $field->expectsArray = true;

        return $field;
    }

    /**
     * Create a boolean field (true/false).
     */
    public static function boolean(string $name, null|string $internalName = null): self
    {
        $field = new self($name, $internalName);

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
    ): self {
        $field = new self($name, $internalName);

        $field->rules->callback($callback);

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
    ): self {
        $field = new self($name, $internalName);

        $field->rules->decimal($min, $max);

        return $field;
    }

    /**
     * Create a field that accepts only specified values.
     *
     * @param list<mixed> $values
     */
    public static function in(string $name, array $values, null|string $internalName = null): self
    {
        $field = new self($name, $internalName);

        $field->rules->in($values);

        return $field;
    }

    /**
     * Create an integer field.
     */
    public static function integer(string $name, null|string $internalName = null): self
    {
        $field = new self($name, $internalName);

        $field->rules->integer();

        return $field;
    }

    /**
     * Create a numeric field (integer or float).
     */
    public static function numeric(string $name, null|string $internalName = null): self
    {
        $field = new self($name, $internalName);

        $field->rules->numeric();

        return $field;
    }

    /**
     * Create a string field.
     */
    public static function string(string $name, null|string $internalName = null): self
    {
        $field = new self($name, $internalName);

        $field->rules->string();

        return $field;
    }

    /**
     * Configure validators that apply to a specific item index when the value is an array.
     *
     * @param callable(AllowedFieldItemRules $rules): (AllowedFieldItemRules|void) $callback
     */
    public function at(int $index, callable $callback): self
    {
        $rules = $this->indexItemRules[$index] ??= new AllowedFieldItemRules();

        $callback($rules);

        return $this;
    }

    /**
     * Add a between constraint (min <= value <= max).
     */
    public function between(float|int $min, float|int $max): self
    {
        $this->rules->between($min, $max);

        return $this;
    }

    /**
     * Cast a field value, using index-specific or shared item types for arrays.
     */
    public function cast(string $value, bool $quoted, int $index = 0): bool|float|int|string
    {
        if ($this->expectsArray) {
            return isset($this->indexItemRules[$index])
                ? $this->indexItemRules[$index]->cast($value, $quoted, $this->itemRules)
                : $this->itemRules->cast($value, $quoted);
        }

        return $this->rules->cast($value, $quoted);
    }

    /**
     * Set a default value for the field.
     */
    public function default(mixed $value): self
    {
        $this->default = $value;
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
    public function each(callable $callback): self
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
     */
    public function getDefault(): mixed
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
     * Check if this field has a default value.
     */
    public function hasDefault(): bool
    {
        return $this->hasDefault;
    }

    /**
     * Add values that should be ignored during validation.
     */
    public function ignore(mixed ...$values): self
    {
        $this->ignored = array_merge($this->ignored, $values);

        return $this;
    }

    /**
     * Check if this field allows null values.
     */
    public function isNullable(): bool
    {
        return $this->nullable;
    }

    /**
     * Add a maximum value constraint.
     */
    public function max(float|int $value): self
    {
        $this->rules->max($value);

        return $this;
    }

    /**
     * Add a minimum value constraint.
     */
    public function min(float|int $value): self
    {
        $this->rules->min($value);

        return $this;
    }

    /**
     * Allow null values for this field.
     */
    public function nullable(bool $nullable = true): self
    {
        $this->nullable = $nullable;

        return $this;
    }

    /**
     * Add an exact size constraint.
     */
    public function size(float|int $value): self
    {
        $this->rules->size($value);

        return $this;
    }

    /**
     * Validate the given value against all configured validators.
     */
    public function validate(mixed $value): bool
    {
        if ($this->shouldSkipValidation($value)) {
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
     * Determine if validation should be skipped for the given value.
     */
    private function shouldSkipValidation(mixed $value): bool
    {
        if (in_array($value, $this->ignored, true)) {
            return true;
        }

        return $this->nullable && is_null($value);
    }
}
