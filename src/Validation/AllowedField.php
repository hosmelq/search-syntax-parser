<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Validation;

use Closure;
use HosmelQ\SearchSyntaxParser\Validation\Validators\BetweenValidator;
use HosmelQ\SearchSyntaxParser\Validation\Validators\BooleanValidator;
use HosmelQ\SearchSyntaxParser\Validation\Validators\CallbackValidator;
use HosmelQ\SearchSyntaxParser\Validation\Validators\DecimalValidator;
use HosmelQ\SearchSyntaxParser\Validation\Validators\IntegerValidator;
use HosmelQ\SearchSyntaxParser\Validation\Validators\InValidator;
use HosmelQ\SearchSyntaxParser\Validation\Validators\MaxValidator;
use HosmelQ\SearchSyntaxParser\Validation\Validators\MinValidator;
use HosmelQ\SearchSyntaxParser\Validation\Validators\NumericValidator;
use HosmelQ\SearchSyntaxParser\Validation\Validators\SizeValidator;
use HosmelQ\SearchSyntaxParser\Validation\Validators\StringValidator;

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
     * Validators applied to a specific item index when the value is an array.
     *
     * @var array<int, list<ValidatorInterface>>
     */
    protected array $indexItemValidators = [];

    /**
     * Internal field name for mapping.
     */
    protected string $internalName;

    /**
     * Validators applied to each item when the value is an array.
     *
     * @var list<ValidatorInterface>
     */
    protected array $itemValidators = [];

    /**
     * Whether this field allows null values.
     */
    protected bool $nullable = false;

    /**
     * Validators applied to the value.
     *
     * @var list<ValidatorInterface>
     */
    protected array $validators = [];

    /**
     * Create a new field with the specified name.
     */
    public function __construct(
        protected string $name,
        null|string $internalName = null
    ) {
        $this->internalName = $internalName ?? $name;
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

        $field->validators[] = new BooleanValidator();

        return $field;
    }

    /**
     * Create a field with a custom validation callback.
     *
     * @param callable(mixed $value, string $attribute): bool $callback
     */
    public static function callback(string $name, callable $callback, null|string $internalName = null): self
    {
        $field = new self($name, $internalName);

        $field->validators[] = new CallbackValidator(Closure::fromCallable($callback));

        return $field;
    }

    /**
     * Create a decimal field with precision constraints.
     */
    public static function decimal(string $name, int $min, null|int $max = null, null|string $internalName = null): self
    {
        $field = new self($name, $internalName);

        $field->validators[] = new DecimalValidator($min, $max);

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

        $field->validators[] = new InValidator($values);

        return $field;
    }

    /**
     * Create an integer field.
     */
    public static function integer(string $name, null|string $internalName = null): self
    {
        $field = new self($name, $internalName);

        $field->validators[] = new IntegerValidator();

        return $field;
    }

    /**
     * Create a numeric field (integer or float).
     */
    public static function numeric(string $name, null|string $internalName = null): self
    {
        $field = new self($name, $internalName);

        $field->validators[] = new NumericValidator();

        return $field;
    }

    /**
     * Create a string field.
     */
    public static function string(string $name, null|string $internalName = null): self
    {
        $field = new self($name, $internalName);

        $field->validators[] = new StringValidator();

        return $field;
    }

    /**
     * Configure validators that apply to a specific item index when the value is an array.
     *
     * @param callable(AllowedFieldItemRules $rules): (AllowedFieldItemRules|void) $callback
     */
    public function at(int $index, callable $callback): self
    {
        $rules = new AllowedFieldItemRules();

        $callback($rules);

        $this->indexItemValidators[$index] = array_merge(
            $this->indexItemValidators[$index] ?? [],
            $rules->getValidators()
        );

        return $this;
    }

    /**
     * Add a between constraint (min <= value <= max).
     */
    public function between(float|int $min, float|int $max): self
    {
        $this->validators[] = new BetweenValidator($min, $max);

        return $this;
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
        $rules = new AllowedFieldItemRules();

        $callback($rules);

        $this->itemValidators = array_merge($this->itemValidators, $rules->getValidators());

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
        $this->validators[] = new MaxValidator($value);

        return $this;
    }

    /**
     * Add a minimum value constraint.
     */
    public function min(float|int $value): self
    {
        $this->validators[] = new MinValidator($value);

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
        $this->validators[] = new SizeValidator($value);

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

        if ($this->expectsArray) {
            if (! is_array($value)) {
                return false;
            }

            foreach ($this->validators as $validator) {
                if (! $validator($value, $this->internalName)) {
                    return false;
                }
            }

            if ($this->itemValidators !== []) {
                foreach ($value as $item) {
                    foreach ($this->itemValidators as $validator) {
                        if (! $validator($item, $this->internalName)) {
                            return false;
                        }
                    }
                }
            }

            foreach ($this->indexItemValidators as $index => $validators) {
                if (! array_key_exists($index, $value)) {
                    continue;
                }

                $item = $value[$index];

                foreach ($validators as $validator) {
                    if (! $validator($item, $this->internalName)) {
                        return false;
                    }
                }
            }

            return true;
        }

        foreach ($this->validators as $validator) {
            if (! $validator($value, $this->internalName)) {
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
