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
use HosmelQ\SearchSyntaxParser\Support\Decimal;
use InvalidArgumentException;
use JsonSerializable;
use Override;

class Field implements JsonSerializable
{
    /**
     * @var null|bool|float|int|non-empty-list<bool|float|int|string>|string
     */
    protected null|array|bool|float|int|string $default = null;

    protected bool $expectsArray = false;

    protected bool $hasDefault = false;

    /**
     * @var array<int|string, mixed>
     */
    protected array $ignored = [];

    /**
     * @var array<int, FieldItemRules>
     */
    protected array $indexItemRules = [];

    protected string $internalName;

    protected FieldItemRules $itemRules;

    protected bool $nullable = false;

    protected FieldItemRules $rules;

    /**
     * @var array<string, bool|Closure|float|int|string>
     */
    private array $aliases = [];

    private bool $allowsLeadingWildcards = false;

    private bool $decimalKeyCollision = false;

    private bool $directive = false;

    /**
     * @var array<string, true>
     */
    private array $disabledClauses = [];

    /**
     * @var null|(Closure(string): bool)|string
     */
    private null|Closure|string $keyRule = null;

    /**
     * @var null|list<string>
     */
    private null|array $operators = null;

    private readonly bool $pattern;

    /**
     * @var null|Closure(bool|float|int|string, null|string): (bool|float|int|string)
     */
    private null|Closure $storageConversion = null;

    private bool $storesText = false;

    private TextMatch $textMatch = TextMatch::Exact;

    private int $wildcardMinimumLength = 0;

    final public function __construct(
        protected string $name,
        null|string $internalName = null,
    ) {
        $this->internalName = $internalName ?? $name;
        $this->pattern = str_ends_with($name, '.*');

        if ($this->pattern && ! str_ends_with($this->internalName, '.*')) {
            throw new InvalidArgumentException('A pattern field internal name must end in ".*".');
        }

        $this->rules = new FieldItemRules();
        $this->itemRules = new FieldItemRules();
    }

    public static function array(string $name, null|string $internalName = null): static
    {
        $field = new static($name, $internalName);
        $field->expectsArray = true;

        return $field;
    }

    public static function boolean(string $name, null|string $internalName = null): static
    {
        $field = new static($name, $internalName);

        $field->rules->boolean();

        return $field;
    }

    /**
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

    public static function date(
        string $name,
        null|string $format = null,
        null|string $internalName = null,
    ): static {
        $field = new static($name, $internalName);

        $field->rules->date($format);

        return $field;
    }

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
     * @param list<mixed> $values
     */
    public static function in(string $name, array $values, null|string $internalName = null): static
    {
        $field = new static($name, $internalName);

        $field->rules->in($values);

        return $field;
    }

    public static function integer(string $name, null|string $internalName = null): static
    {
        $field = new static($name, $internalName);

        $field->rules->integer();

        return $field;
    }

    public static function numeric(string $name, null|string $internalName = null): static
    {
        $field = new static($name, $internalName);

        $field->rules->numeric();

        return $field;
    }

    public static function string(string $name, null|string $internalName = null): static
    {
        $field = new static($name, $internalName);

        $field->rules->string();

        return $field;
    }

    public static function ulid(string $name, null|string $internalName = null): static
    {
        $field = new static($name, $internalName);

        $field->rules->ulid();

        return $field;
    }

    public static function uuid(string $name, null|string $internalName = null): static
    {
        $field = new static($name, $internalName);

        $field->rules->uuid();

        return $field;
    }

    /**
     * @internal
     */
    public function accepts(string $field): bool
    {
        if (! $this->pattern) {
            return true;
        }

        $key = mb_substr($field, mb_strlen($this->name) - 1);

        return $key !== '' && ! str_contains($key, '*') && $this->allowsKey($key);
    }

    public function alias(string $token, bool|Closure|float|int|string $value): static
    {
        if (str_contains($token, '*')) {
            throw new InvalidArgumentException('A value alias token cannot contain a wildcard.');
        }

        $this->aliases[$token] = $value;

        return $this;
    }

    public function allowLeadingWildcards(): static
    {
        $this->allowsLeadingWildcards = true;

        return $this;
    }

    public function allowPartialDates(): static
    {
        if ($this->expectsArray) {
            throw new InvalidArgumentException(
                'On array fields, allow partial dates on the each() or at() date rules.',
            );
        }

        $this->rules->allowPartialDates();

        return $this;
    }

    /**
     * @internal
     */
    public function allowsKey(string $key): bool
    {
        return $this->keyRule instanceof Closure
            ? ($this->keyRule)($key) === true
            : $this->keyRule !== null && preg_match($this->keyRule, $key) === 1;
    }

    /**
     * @internal
     */
    public function assertClauseAllowed(string $clause, int $position, string $field): void
    {
        if (isset($this->disabledClauses[$clause])) {
            throw InvalidFieldOperatorException::clauseNotAllowed($field, $clause, $position);
        }
    }

    /**
     * @internal
     */
    public function assertOperatorAllowed(string $operator, int $position, string $field): void
    {
        if ($this->operators !== null && ! in_array($operator, $this->operators, true)) {
            throw InvalidFieldOperatorException::operatorNotAllowed(
                $field,
                $operator,
                $position,
            );
        }
    }

    /**
     * @internal
     */
    public function assertValidConfiguration(): void
    {
        if ($this->isPattern() && $this->keyRule === null) {
            throw new InvalidArgumentException(
                sprintf("Pattern field '%s' requires a keys() rule.", $this->name),
            );
        }

        if ($this->textMatch === TextMatch::Words && $this->allowsLeadingWildcards) {
            throw new InvalidArgumentException(
                sprintf("Whole-word field '%s' cannot allow leading wildcards.", $this->name),
            );
        }

        if ($this->decimalKeyCollision && $this->hasDecimalItems()) {
            throw new InvalidArgumentException(
                sprintf(
                    "Storage map keys of decimal field '%s' must be unique by value.",
                    $this->name,
                ),
            );
        }
    }

    /**
     * @internal
     */
    public function assertWildcardAllowed(
        bool $leading,
        int $literals,
        int $position,
        string $field,
        bool $prefix,
    ): void {
        if ($this->textMatch === TextMatch::Words && ! $prefix) {
            throw InvalidFieldOperatorException::wordWildcardNotAllowed($field, $position);
        }

        if ($leading && ! $this->allowsLeadingWildcards) {
            throw InvalidFieldOperatorException::leadingWildcardNotAllowed($field, $position);
        }

        if ($literals < $this->wildcardMinimumLength) {
            throw InvalidFieldOperatorException::wildcardTooShort(
                $field,
                $this->wildcardMinimumLength,
                $position,
            );
        }
    }

    /**
     * @param callable(FieldItemRules $rules): (FieldItemRules|void) $callback
     */
    public function at(int $index, callable $callback): static
    {
        $rules = $this->indexItemRules[$index] ??= new FieldItemRules();

        $callback($rules);

        if ($this->textMatch !== TextMatch::Exact) {
            $this->assertTextMatchAllowed();
        }

        return $this;
    }

    public function between(float|int $min, float|int $max): static
    {
        $this->rules->between($min, $max);

        return $this;
    }

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

    public function comparesText(bool|float|int|string $value): bool
    {
        return $this->isText() && is_string($value);
    }

    public function contains(): static
    {
        $this->assertTextMatchAllowed();

        $this->textMatch = TextMatch::Contains;

        return $this;
    }

    public function default(mixed $value): static
    {
        if ($this->isPattern()) {
            throw new InvalidArgumentException('Pattern fields cannot have defaults.');
        }

        if ($this->isDirective() && ! is_scalar($value)) {
            throw new InvalidArgumentException('A directive default must be a scalar.');
        }

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

    public function directive(): static
    {
        if ($this->isPattern() || $this->expectsArray) {
            throw new InvalidArgumentException('Directives require an exact scalar field.');
        }

        if ($this->hasDefault && ! is_scalar($this->default)) {
            throw new InvalidArgumentException('A directive default must be a scalar.');
        }

        $this->directive = true;

        return $this;
    }

    /**
     * @param callable(FieldItemRules $rules): (FieldItemRules|void) $callback
     */
    public function each(callable $callback): static
    {
        $callback($this->itemRules);

        if ($this->textMatch !== TextMatch::Exact) {
            $this->assertTextMatchAllowed();
        }

        return $this;
    }

    public function expectsArray(): bool
    {
        return $this->expectsArray;
    }

    /**
     * @internal
     *
     * @return null|array{format: null|string, partial: bool, time: bool}
     */
    public function getDateOptions(): null|array
    {
        return ($this->expectsArray ? $this->itemRules : $this->rules)->getDateOptions();
    }

    /**
     * @return null|bool|float|int|non-empty-list<bool|float|int|string>|string
     */
    public function getDefault(): null|array|bool|float|int|string
    {
        return $this->default;
    }

    public function getInternalName(): string
    {
        return $this->internalName;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getTextMatch(null|ComparisonNode|InNode $node = null, int $index = 0): TextMatch
    {
        if ($node === null) {
            return $this->textMatch;
        }

        $quoted = $node instanceof ComparisonNode ? $node->isQuoted() : $node->isQuoted($index);

        return $quoted ? TextMatch::Exact : $this->textMatch;
    }

    /**
     * @internal
     */
    public function getValueType(): null|string
    {
        return ($this->expectsArray ? $this->itemRules : $this->rules)->getType();
    }

    /**
     * @internal
     */
    public function hasAlias(string $token): bool
    {
        return array_key_exists($token, $this->aliases);
    }

    /**
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
     * @internal
     */
    public function hasCustomDateFormat(): bool
    {
        return array_any(
            [$this->rules, $this->itemRules, ...$this->indexItemRules],
            static fn (FieldItemRules $rules): bool
                => ($rules->getDateOptions()['format'] ?? null) !== null,
        );
    }

    public function hasDefault(): bool
    {
        return $this->hasDefault;
    }

    /**
     * @internal
     */
    public function hasUsageRules(): bool
    {
        return $this->directive || $this->operators !== null || $this->disabledClauses !== [];
    }

    public function ignore(mixed ...$values): static
    {
        $this->ignored = array_merge($this->ignored, $values);

        return $this;
    }

    public function isDirective(): bool
    {
        return $this->directive;
    }

    public function isIgnored(mixed $value): bool
    {
        return array_any($this->ignored, static fn (mixed $ignored): bool
            => is_string($value) && is_string($ignored)
                ? mb_strtolower($value) === mb_strtolower($ignored)
                : $value === $ignored);
    }

    public function isNullable(): bool
    {
        return $this->nullable;
    }

    public function isPattern(): bool
    {
        return $this->pattern;
    }

    public function isText(): bool
    {
        return $this->rules->getType() === 'string';
    }

    /**
     * @return array{
     *     aliases: list<string>,
     *     clauses: list<string>,
     *     date: null|array{format: null|string, partial: bool, time: bool},
     *     directive: bool,
     *     items: null|array{
     *         date: null|array{format: null|string, partial: bool, time: bool},
     *         names: null|list<string>,
     *         type: null|string,
     *         values: null|list<mixed>,
     *     },
     *     match: null|string,
     *     name: string,
     *     names: null|list<string>,
     *     operators: list<string>,
     *     pattern: bool,
     *     type: null|string,
     *     values: null|list<mixed>,
     * }
     */
    #[Override]
    public function jsonSerialize(): array
    {
        $operators = $this->directive ? ['='] : ['=', '!=', '>', '>=', '<', '<='];
        $clauses = ['exists', 'lists', 'ranges'];

        if (in_array($this->getValueType(), [null, 'string'], true)) {
            $clauses[] = 'wildcards';
        }

        return [
            'aliases' => array_map(strval(...), array_keys($this->aliases)),
            'clauses' => $this->directive ? [] : array_values(array_diff(
                $clauses,
                array_keys($this->disabledClauses),
            )),
            'date' => $this->expectsArray ? null : $this->rules->getDateOptions(),
            'directive' => $this->directive,
            'items' => $this->expectsArray ? [
                'date' => $this->itemRules->getDateOptions(),
                'names' => $this->itemRules->getNames(),
                'type' => $this->itemRules->getType(),
                'values' => $this->itemRules->getValues(),
            ] : null,
            'match' => $this->getValueType() === 'string' ? $this->textMatch->value : null,
            'name' => $this->name,
            'names' => $this->expectsArray ? null : $this->rules->getNames(),
            'operators' => array_values(
                array_intersect($operators, $this->operators ?? $operators),
            ),
            'pattern' => $this->pattern,
            'type' => $this->expectsArray ? 'array' : $this->rules->getType(),
            'values' => $this->expectsArray ? null : $this->rules->getValues(),
        ];
    }

    /**
     * @param (Closure(string): bool)|string $rule
     */
    public function keys(Closure|string $rule): static
    {
        if (! $this->isPattern()) {
            throw new InvalidArgumentException('Key rules require a pattern field.');
        }

        if (is_string($rule)) {
            set_error_handler(static fn (): bool => true);

            try {
                if (preg_match($rule, '') === false) {
                    throw new InvalidArgumentException(
                        'A key rule must be a valid regular expression.',
                    );
                }
            } finally {
                restore_error_handler();
            }
        }

        $this->keyRule = $rule;

        return $this;
    }

    /**
     * @param array<int|string, bool|float|int|string> $map
     */
    public function map(array $map): static
    {
        $this->assertNoStorageConversion();

        $lookup = [];
        $decimals = [];

        foreach ($map as $key => $stored) {
            $key = is_string($key) ? mb_strtolower($key) : $key;

            if (array_key_exists($key, $lookup)) {
                throw new InvalidArgumentException(
                    'Storage map keys must be unique ignoring case.',
                );
            }

            $lookup[$key] = $stored;
            $decimal = $this->decimalKey($key);

            if ($decimal === null) {
                continue;
            }

            // Item types can be configured after map(), so assertValidConfiguration() rejects the collision.
            $this->decimalKeyCollision = $this->decimalKeyCollision
                || array_key_exists($decimal, $decimals);
            $decimals[$decimal] = $stored;
        }

        $text = array_filter($lookup, is_string(...)) !== [];
        $this->storageConversion = static function (
            bool|float|int|string $value,
            null|string $type,
        ) use ($decimals, $lookup, $text): bool|float|int|string {
            if ($type === 'decimal' && ! is_bool($value)) {
                $value = (string) Decimal::from($value)->strippedOfTrailingZeros();
                $stored = array_key_exists($value, $decimals) ? $decimals[$value] : $value;
            } else {
                $key = match (true) {
                    is_bool($value) => (int) $value,
                    is_string($value) => mb_strtolower($value),
                    is_float($value) => (string) Decimal::from($value)->strippedOfTrailingZeros(),
                    default => $value,
                };
                $stored = array_key_exists($key, $lookup) ? $lookup[$key] : $value;
            }

            return match (true) {
                ! $text, is_string($stored) => $stored,
                is_bool($stored) => $stored ? '1' : '0',
                is_float($stored) => (string) Decimal::from($stored)->strippedOfTrailingZeros(),
                default => (string) $stored,
            };
        };
        $this->storesText = $text;

        return $this->withoutWildcards();
    }

    public function max(float|int $value): static
    {
        $this->rules->max($value);

        return $this;
    }

    public function min(float|int $value): static
    {
        $this->rules->min($value);

        return $this;
    }

    public function minWildcardLength(int $characters): static
    {
        if ($characters < 0) {
            throw new InvalidArgumentException('Minimum wildcard length cannot be negative.');
        }

        $this->wildcardMinimumLength = $characters;

        return $this;
    }

    public function nullable(bool $nullable = true): static
    {
        $this->nullable = $nullable;

        return $this;
    }

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

    public function prefix(): static
    {
        $this->assertTextMatchAllowed();

        $this->textMatch = TextMatch::Prefix;

        return $this;
    }

    public function scale(int|string $factor): static
    {
        $this->assertNoStorageConversion();

        if (! in_array($this->getValueType(), ['integer', 'numeric', 'decimal'], true)) {
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

        $this->storageConversion = static function (
            bool|float|int|string $value,
            null|string $type,
        ) use ($multiplier): bool|float|int|string {
            if (! in_array($type, ['integer', 'numeric', 'decimal'], true)) {
                return $value;
            }

            $result = Decimal::from($value)->multipliedBy($multiplier)->strippedOfTrailingZeros();

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

    public function size(float|int $value): static
    {
        $this->rules->size($value);

        return $this;
    }

    public function storesText(): bool
    {
        return $this->storesText;
    }

    public function toStorage(bool|float|int|string $value, int $index = 0): bool|float|int|string
    {
        return $this->storageConversion instanceof Closure
            ? ($this->storageConversion)($value, $this->storageType($index))
            : $value;
    }

    public function tryCast(
        string $value,
        bool $quoted = false,
        int $index = 0,
    ): null|bool|float|int|string {
        $cast = $this->cast($value, $quoted, $index);

        return $this->validate($this->expectsArray ? [$index => $cast] : $cast) ? $cast : null;
    }

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
                    $indexRules instanceof FieldItemRules
                    && ! $indexRules->validate($item, $this->internalName, $length)
                )) {
                return false;
            }
        }

        return true;
    }

    public function withoutExists(): static
    {
        $this->disabledClauses['exists'] = true;

        return $this;
    }

    public function withoutLists(): static
    {
        $this->disabledClauses['lists'] = true;

        return $this;
    }

    public function withoutRanges(): static
    {
        $this->disabledClauses['ranges'] = true;

        return $this;
    }

    public function withoutTime(): static
    {
        if ($this->expectsArray) {
            throw new InvalidArgumentException(
                'On array fields, reject times on the each() or at() date rules.',
            );
        }

        $this->rules->withoutTime();

        return $this;
    }

    public function withoutWildcards(): static
    {
        $this->disabledClauses['wildcards'] = true;

        return $this;
    }

    public function words(): static
    {
        $this->assertTextMatchAllowed();

        $this->textMatch = TextMatch::Words;

        return $this;
    }

    private function assertNoStorageConversion(): void
    {
        if ($this->storageConversion instanceof Closure) {
            throw new InvalidArgumentException('A field accepts only one storage conversion.');
        }
    }

    private function assertTextMatchAllowed(): void
    {
        if ($this->getValueType() !== 'string' || ($this->expectsArray && array_any(
            $this->indexItemRules,
            static fn (FieldItemRules $item): bool
                => $item->getType() !== null && $item->getType() !== 'string',
        ))) {
            throw new InvalidArgumentException(
                'Text match modes require a string field or shared string item type.',
            );
        }
    }

    private function decimalKey(int|string $key): null|string
    {
        if (is_int($key)) {
            return (string) $key;
        }

        if (preg_match('/^[+-]?(?:\d+\.?\d*|\.\d+)$/D', $key) !== 1) {
            return null;
        }

        return (string) BigDecimal::of($key)->strippedOfTrailingZeros();
    }

    private function hasDecimalItems(): bool
    {
        if (! $this->expectsArray) {
            return $this->rules->getType() === 'decimal';
        }

        return array_any(
            [$this->itemRules, ...$this->indexItemRules],
            static fn (FieldItemRules $rules): bool => $rules->getType() === 'decimal',
        );
    }

    private function storageType(int $index): null|string
    {
        if (! $this->expectsArray) {
            return $this->rules->getType();
        }

        return ($this->indexItemRules[$index] ?? null)?->getType() ?? $this->itemRules->getType();
    }
}
