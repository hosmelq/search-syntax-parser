<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Validation;

use BackedEnum;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\IntegerOverflowException;
use Brick\Math\Exception\MathException;
use Closure;
use HosmelQ\SearchSyntaxParser\AST\Node\ComparisonNode;
use HosmelQ\SearchSyntaxParser\AST\Node\InNode;
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
     * @var null|Closure(bool|float|int|string): (bool|float|int|string)
     */
    private null|Closure $storageConversion = null;

    private TextMatch $textMatch = TextMatch::Exact;

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
     * Create a field accepting backed enum values or case names, ignoring case.
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
     * Create a ULID field, normalized to uppercase.
     */
    public static function ulid(string $name, null|string $internalName = null): static
    {
        $field = new static($name, $internalName);

        $field->rules->ulid();

        return $field;
    }

    /**
     * Create a UUID field, normalized to lowercase.
     */
    public static function uuid(string $name, null|string $internalName = null): static
    {
        $field = new static($name, $internalName);

        $field->rules->uuid();

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

        if ($this->textMatch !== TextMatch::Exact) {
            $this->assertTextMatchAllowed();
        }

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
     * Match unquoted text anywhere within the stored text.
     */
    public function contains(): static
    {
        $this->assertTextMatchAllowed();

        $this->textMatch = TextMatch::Contains;

        return $this;
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

        if ($this->textMatch !== TextMatch::Exact) {
            $this->assertTextMatchAllowed();
        }

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
     * Get the configured text match mode, or a comparison's or list item's effective mode,
     * where quotes force exact matching.
     */
    public function getTextMatch(null|ComparisonNode|InNode $node = null, int $index = 0): TextMatch
    {
        if ($node === null) {
            return $this->textMatch;
        }

        $quoted = $node instanceof ComparisonNode ? $node->isQuoted() : $node->isQuoted($index);

        return $quoted ? TextMatch::Exact : $this->textMatch;
    }

    /**
     * Check whether an exact unquoted value is an alias token, without resolving it.
     *
     * @internal
     */
    public function hasAlias(string $token): bool
    {
        return array_key_exists($token, $this->aliases);
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
     * Remove query constraints matching these cast values, ignoring case for strings.
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
        return array_any($this->ignored, static fn (mixed $ignored): bool
            => is_string($value) && is_string($ignored)
                ? mb_strtolower($value) === mb_strtolower($ignored)
                : $value === $ignored);
    }

    /**
     * Check whether the stored value can be missing.
     */
    public function isNullable(): bool
    {
        return $this->nullable;
    }

    /**
     * Convert cast values to stored values, matching string keys ignoring case; unmapped values pass through.
     *
     * @param array<int|string, bool|float|int|string> $map
     */
    public function map(array $map): static
    {
        $this->assertNoStorageConversion();

        $lookup = [];

        foreach ($map as $key => $stored) {
            $key = is_string($key) ? mb_strtolower($key) : $key;

            if (array_key_exists($key, $lookup)) {
                throw new InvalidArgumentException(
                    'Storage map keys must be unique ignoring case.',
                );
            }

            $lookup[$key] = $stored;
        }

        $this->storageConversion = static function (bool|float|int|string $value) use (
            $lookup,
        ): bool|float|int|string {
            $key = match (true) {
                is_bool($value) => (int) $value,
                is_string($value) => mb_strtolower($value),
                is_float($value) => (string) self::toDecimal($value)->strippedOfTrailingZeros(),
                default => $value,
            };

            return array_key_exists($key, $lookup) ? $lookup[$key] : $value;
        };

        return $this->withoutWildcards();
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
     * Match unquoted text at the start of stored text.
     */
    public function prefix(): static
    {
        $this->assertTextMatchAllowed();

        $this->textMatch = TextMatch::Prefix;

        return $this;
    }

    /**
     * Multiply integer, numeric, or decimal values when adapting them for storage.
     */
    public function scale(int|string $factor): static
    {
        $this->assertNoStorageConversion();

        $type = $this->expectsArray ? $this->itemRules->getType() : $this->rules->getType();

        if (! in_array($type, ['integer', 'numeric', 'decimal'], true)) {
            throw new InvalidArgumentException(
                'Scaling requires an integer, numeric, or decimal field or shared item type.',
            );
        }

        try {
            $multiplier = BigDecimal::of($factor);
        } catch (MathException $mathException) {
            throw new InvalidArgumentException(
                'The scale factor must be a decimal number.',
                $mathException->getCode(),
                previous: $mathException,
            );
        }

        $this->storageConversion = static function (bool|float|int|string $value) use (
            $multiplier,
        ): int|string {
            $result = self::toDecimal($value)->multipliedBy($multiplier)->strippedOfTrailingZeros();

            if ($result->getScale() !== 0) {
                return (string) $result;
            }

            try {
                return $result->toInt();
            } catch (IntegerOverflowException) {
                return (string) $result;
            }
        };

        return $this->withoutWildcards();
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
     * Convert a validated cast value for an adapter, without changing the AST.
     */
    public function toStorage(bool|float|int|string $value): bool|float|int|string
    {
        return $this->storageConversion instanceof Closure
            ? ($this->storageConversion)($value)
            : $value;
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

    /**
     * Match unquoted text as whole words within stored text.
     */
    public function words(): static
    {
        $this->assertTextMatchAllowed();

        $this->textMatch = TextMatch::Words;

        return $this;
    }

    /**
     * Read a number exactly, using the shortest round-trip text for floats.
     */
    private static function toDecimal(bool|float|int|string $value): BigDecimal
    {
        if (! is_float($value)) {
            return BigDecimal::of((string) $value);
        }

        // json_encode() writes the shortest round-trip text only with serialize_precision -1.
        $precision = ini_set('serialize_precision', '-1');

        try {
            return BigDecimal::of(json_encode($value, JSON_THROW_ON_ERROR));
        } finally {
            if ($precision !== false) {
                ini_set('serialize_precision', $precision);
            }
        }
    }

    private function assertNoStorageConversion(): void
    {
        if ($this->storageConversion instanceof Closure) {
            throw new InvalidArgumentException('A field accepts only one storage conversion.');
        }
    }

    private function assertTextMatchAllowed(): void
    {
        $rules = $this->expectsArray ? $this->itemRules : $this->rules;

        if ($rules->getType() !== 'string' || ($this->expectsArray && array_any(
            $this->indexItemRules,
            static fn (AllowedFieldItemRules $item): bool
                => $item->getType() !== null && $item->getType() !== 'string',
        ))) {
            throw new InvalidArgumentException(
                'Text match modes require a string field or shared string item type.',
            );
        }
    }
}
