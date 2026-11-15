<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser;

use Closure;
use HosmelQ\SearchSyntaxParser\Adapter\ArrayAdapter;
use HosmelQ\SearchSyntaxParser\Adapter\QueryAdapterInterface;
use HosmelQ\SearchSyntaxParser\Adapter\StringAdapter;
use HosmelQ\SearchSyntaxParser\AST\Node\BinaryOperatorNode;
use HosmelQ\SearchSyntaxParser\AST\Node\ComparisonNode;
use HosmelQ\SearchSyntaxParser\AST\Node\ExistsNode;
use HosmelQ\SearchSyntaxParser\AST\Node\InNode;
use HosmelQ\SearchSyntaxParser\AST\Node\NodeInterface;
use HosmelQ\SearchSyntaxParser\AST\Node\UnaryOperatorNode;
use HosmelQ\SearchSyntaxParser\Exception\InvalidFieldValueException;
use HosmelQ\SearchSyntaxParser\Exception\ParseException;
use HosmelQ\SearchSyntaxParser\Parser\Parser;
use HosmelQ\SearchSyntaxParser\Sorting\AllowedSort;
use HosmelQ\SearchSyntaxParser\Sorting\Sort;
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;
use InvalidArgumentException;

class SearchParser
{
    /**
     * @var array<string, QueryAdapterInterface>
     */
    private array $adapters = [];

    /**
     * @var array<string, AllowedField>
     */
    private array $allowedFields = [];

    /**
     * @var array<string, AllowedSort>
     */
    private array $allowedSorts = [];

    private bool $allowsAnyField = false;

    private bool $allowsLeadingWildcards = false;

    private int $clauseLimit = 1_024;

    /**
     * @var array<string, Closure(): QueryAdapterInterface>
     */
    private array $customCreators = [];

    private null|string $defaultSort = null;

    private int $depthLimit = 32;

    private int $lengthLimit = 10_000;

    /**
     * @var list<AllowedField>
     */
    private array $patternFields = [];

    /**
     * @var null|array{
     *     directives: array<int|string, bool|float|int|string>,
     *     typed: array<int|string, bool|float|int|string>,
     *     sort: null|Sort,
     * }
     */
    private null|array $settings = null;

    public function __construct(private readonly string $query = '') {}

    public static function query(string $query): self
    {
        return new self($query);
    }

    public function adapter(null|string $adapter = null): QueryAdapterInterface
    {
        $adapter ??= $this->getDefaultAdapter();

        return $this->adapters[$adapter] ??= $this->createAdapter($adapter);
    }

    public function allowAnyField(): self
    {
        $this->allowsAnyField = true;

        return $this;
    }

    /**
     * @param list<AllowedField|string> $fields
     */
    public function allowedFields(array $fields): self
    {
        if ($this->allowedSorts !== []) {
            $this->assertSortNotReserved($fields);
        }

        $this->allowedFields = [];
        $this->patternFields = [];

        foreach ($fields as $field) {
            $field = is_string($field) ? new AllowedField($field) : $field;
            $this->allowedFields[$field->getName()] = $field;
        }

        // Read patterns from the final map so a repeated name resolves to the same field.
        foreach ($this->allowedFields as $field) {
            $field->assertValidConfiguration();

            if ($field->isPattern()) {
                $this->patternFields[] = $field;
            }
        }

        usort($this->patternFields, static fn (AllowedField $left, AllowedField $right): int
            => mb_strlen($right->getName()) <=> mb_strlen($left->getName()));

        return $this;
    }

    /**
     * @param list<AllowedSort|string> $sorts
     */
    public function allowedSorts(array $sorts): self
    {
        if ($sorts !== []) {
            $this->assertSortNotReserved(array_values($this->allowedFields));
        }

        $allowed = [];

        foreach ($sorts as $sort) {
            $sort = is_string($sort) ? AllowedSort::field($sort) : $sort;
            $key = mb_strtolower($sort->getName());

            if (isset($allowed[$key])) {
                throw new InvalidArgumentException('Sort names must be unique ignoring case.');
            }

            $allowed[$key] = $sort;
        }

        // `sort:created-desc` always means `created` descending, so a sort named like that
        // could never be reached with its own default direction.
        foreach (array_keys($allowed) as $key) {
            $separator = mb_strrpos($key, '-');

            if (
                $separator !== false
                && in_array(mb_substr($key, $separator + 1), ['asc', 'desc'], true)
                && isset($allowed[mb_substr($key, 0, $separator)])
            ) {
                throw new InvalidArgumentException(sprintf(
                    "Sort '%s' is ambiguous with sort '%s' and a direction.",
                    $allowed[$key]->getName(),
                    $allowed[mb_substr($key, 0, $separator)]->getName(),
                ));
            }
        }

        $this->allowedSorts = $allowed;

        return $this;
    }

    public function allowLeadingWildcards(): self
    {
        $this->allowsLeadingWildcards = true;

        return $this;
    }

    public function allowsAnyField(): bool
    {
        return $this->allowsAnyField;
    }

    public function allowsLeadingWildcards(): bool
    {
        return $this->allowsLeadingWildcards;
    }

    /**
     * A null filter tree skips adapters except string output with explicit directives or sorting.
     */
    public function build(null|string $adapter = null): mixed
    {
        $ast = $this->parse();
        $builder = $this->adapter($adapter);

        if ($ast instanceof NodeInterface) {
            return $builder->build($ast);
        }

        return $builder instanceof StringAdapter
            && $this->settings !== null
            && ($this->settings['typed'] !== [] || $this->settings['sort']?->isDefault() === false)
                ? $builder->build(null)
                : null;
    }

    public function defaultSort(string $sort): self
    {
        $this->defaultSort = $sort;

        return $this;
    }

    /**
     * @param callable(): QueryAdapterInterface $callback
     */
    public function extend(string $adapter, callable $callback): static
    {
        $this->customCreators[$adapter] = Closure::fromCallable($callback);

        return $this;
    }

    public function getAllowedField(string $field): null|AllowedField
    {
        if (isset($this->allowedFields[$field])) {
            return $this->allowedFields[$field];
        }

        foreach ($this->patternFields as $pattern) {
            // Names with the prefix resolve to the pattern even with an empty key, so the
            // pattern's key rule rejects them instead of allowAnyField() accepting them.
            if (str_starts_with($field, mb_substr($pattern->getName(), 0, -1))) {
                return $pattern;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function getAllowedFieldNames(): array
    {
        return array_keys($this->allowedFields);
    }

    /**
     * @return array<string, AllowedSort>
     */
    public function getAllowedSorts(): array
    {
        return $this->allowedSorts;
    }

    public function getDefaultAdapter(): string
    {
        return 'array';
    }

    /**
     * @return array<int|string, bool|float|int|string>
     */
    public function getDirectives(): array
    {
        return $this->settings['directives'] ?? [];
    }

    public function getSort(): null|Sort
    {
        return $this->settings['sort'] ?? null;
    }

    /**
     * Get only directives explicitly supplied by the query, in configuration order.
     *
     * @return array<int|string, bool|float|int|string>
     */
    public function getTypedDirectives(): array
    {
        return $this->settings['typed'] ?? [];
    }

    public function isFieldAllowed(string $field): bool
    {
        return $this->getAllowedField($field)?->accepts($field) ?? $this->allowsAnyField;
    }

    public function maxClauses(int $clauses): self
    {
        if ($clauses <= 0) {
            throw new InvalidArgumentException('Maximum clauses must be positive.');
        }

        $this->clauseLimit = $clauses;

        return $this;
    }

    public function maxDepth(int $depth): self
    {
        if ($depth <= 0) {
            throw new InvalidArgumentException('Maximum depth must be positive.');
        }

        $this->depthLimit = $depth;

        return $this;
    }

    public function maxLength(int $characters): self
    {
        if ($characters <= 0) {
            throw new InvalidArgumentException('Maximum length must be positive.');
        }

        $this->lengthLimit = $characters;

        return $this;
    }

    public function parse(): null|NodeInterface
    {
        $this->settings = null;

        if (mb_strlen($this->query) > $this->lengthLimit) {
            throw ParseException::maximumLength($this->lengthLimit);
        }

        $defaults = [];

        foreach ($this->allowedFields as $field) {
            if ($field->hasDefault()) {
                $defaults[] = $field;
            }
        }

        $parser = new Parser($this, $this->depthLimit, $this->clauseLimit);
        $ast = $parser->parse($this->query, $defaults !== []);

        if ($defaults === [] && ! $parser->hasSettings() && $this->defaultSort === null) {
            return $ast;
        }

        $typedDirectives = $parser->resolveDirectives();
        $directives = [];
        $sortInput = $parser->getSortInput();
        $sort = $sortInput !== null
            ? $this->resolveSort($sortInput[0], false, $sortInput[1])
            : ($this->defaultSort === null ? null : $this->resolveSort($this->defaultSort, true));

        if ($defaults !== []) {
            $referenced = $parser->getReferencedFields();

            foreach ($defaults as $field) {
                $name = $field->getName();

                if (isset($referenced[$name])) {
                    continue;
                }

                $value = $field->getDefault();

                if ($value !== null) {
                    $value = is_array($value)
                        ? array_map(
                            fn (bool|float|int|string $item, int $index): bool|float|int|string
                                => $this->castDefault($field, $item, $index),
                            $value,
                            array_keys($value),
                        )
                        : $this->castDefault($field, $value, 0);

                    if (! $this->validateField($name, $value)) {
                        throw new InvalidArgumentException(
                            sprintf("Invalid default for field '%s'.", $name),
                        );
                    }
                }

                if ($field->isDirective()) {
                    if (! is_scalar($value)) {
                        throw new InvalidArgumentException('A directive default must be a scalar.');
                    }

                    $directives[$name] = $value;

                    continue;
                }

                $node = match (true) {
                    $value === null => new UnaryOperatorNode(
                        'NOT',
                        new ExistsNode($name, default: true),
                        default: true,
                    ),
                    is_array($value) => new InNode($name, '=', $value, default: true),
                    default => new ComparisonNode($name, '=', $value, default: true),
                };
                $ast = $ast instanceof NodeInterface
                    ? new BinaryOperatorNode('AND', $ast, $node)
                    : $node;
            }
        }

        $resolvedDirectives = $directives + $typedDirectives;
        $directives = [];

        if ($resolvedDirectives !== []) {
            foreach ($this->allowedFields as $field) {
                $name = $field->getName();

                if (array_key_exists($name, $resolvedDirectives)) {
                    $directives[$name] = $resolvedDirectives[$name];
                }
            }
        }

        $this->settings = [
            'directives' => $directives,
            'typed' => array_intersect_key($directives, $typedDirectives),
            'sort' => $sort,
        ];

        return $ast;
    }

    public function validateField(string $field, mixed $value): bool
    {
        $allowed = $this->getAllowedField($field);

        if ($allowed instanceof AllowedField) {
            if ($allowed->expectsArray()) {
                return $allowed->validate(is_array($value) ? $value : [$value]);
            }

            if (is_array($value)) {
                return array_all($value, fn (mixed $item): bool => $allowed->validate($item));
            }

            return $allowed->validate($value);
        }

        return true;
    }

    protected function createArrayAdapter(): ArrayAdapter
    {
        return new ArrayAdapter($this);
    }

    protected function createStringAdapter(): StringAdapter
    {
        return new StringAdapter($this);
    }

    /**
     * @param list<AllowedField|string> $fields
     */
    private function assertSortNotReserved(array $fields): void
    {
        if (array_any($fields, static fn (AllowedField|string $field): bool
            => mb_strtolower(is_string($field) ? $field : $field->getName()) === 'sort')) {
            throw new InvalidArgumentException(
                "Field 'sort' is reserved when sorts are configured.",
            );
        }
    }

    private function castDefault(
        AllowedField $field,
        bool|float|int|string $value,
        int $index,
    ): bool|float|int|string {
        if (is_string($value)) {
            return $field->cast($value, false, $index);
        }

        if (! $field->hasCastType($value, $index)) {
            throw new InvalidArgumentException(
                sprintf("Invalid default for field '%s'.", $field->getName()),
            );
        }

        return $value;
    }

    private function createAdapter(string $adapter): QueryAdapterInterface
    {
        if (isset($this->customCreators[$adapter])) {
            return $this->customCreators[$adapter]();
        }

        $method = 'create'.$this->studly($adapter).'Adapter';

        if (method_exists($this, $method)) {
            return $this->$method(); // @phpstan-ignore-line method.dynamicName
        }

        throw new InvalidArgumentException(sprintf("Adapter '%s' not supported.", $adapter));
    }

    private function resolveSort(string $value, bool $default, null|int $position = null): Sort
    {
        $key = mb_strtolower($value);
        $direction = null;
        $allowed = $this->allowedSorts[$key] ?? null;
        $separator = mb_strrpos($key, '-');

        if ($separator !== false) {
            $suffix = mb_substr($key, $separator + 1);
            $base = $this->allowedSorts[mb_substr($key, 0, $separator)] ?? null;

            if ($base instanceof AllowedSort && in_array($suffix, ['asc', 'desc'], true)) {
                $allowed = $base;
                $direction = $suffix;
            }
        }

        if (! $allowed instanceof AllowedSort) {
            if ($default) {
                throw new InvalidArgumentException(
                    'The default sort must name an allowed sort and direction.',
                );
            }

            throw InvalidFieldValueException::invalidValue('sort', $position);
        }

        return new Sort(
            $allowed->getName(),
            $allowed->getInternalName(),
            $direction ?? $allowed->getDefaultDirection(),
            $default,
        );
    }

    private function studly(string $value): string
    {
        $value = ucwords(str_replace(['-', '_'], ' ', $value));

        return str_replace(' ', '', $value);
    }
}
