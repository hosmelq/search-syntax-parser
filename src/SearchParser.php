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
use HosmelQ\SearchSyntaxParser\AST\Node\RangeNode;
use HosmelQ\SearchSyntaxParser\AST\Node\TermNode;
use HosmelQ\SearchSyntaxParser\AST\Node\UnaryOperatorNode;
use HosmelQ\SearchSyntaxParser\AST\Node\WildcardNode;
use HosmelQ\SearchSyntaxParser\AST\Visitor\NegationNormalFormTransformer;
use HosmelQ\SearchSyntaxParser\Exception\InvalidFieldValueException;
use HosmelQ\SearchSyntaxParser\Exception\ParseException;
use HosmelQ\SearchSyntaxParser\Parser\Parser;
use HosmelQ\SearchSyntaxParser\Query\Clause;
use HosmelQ\SearchSyntaxParser\Query\ClauseCompiler;
use HosmelQ\SearchSyntaxParser\Query\ClauseGroup;
use HosmelQ\SearchSyntaxParser\Query\Operator;
use HosmelQ\SearchSyntaxParser\Sorting\AllowedSort;
use HosmelQ\SearchSyntaxParser\Sorting\Sort;
use HosmelQ\SearchSyntaxParser\Validation\Field;
use InvalidArgumentException;
use JsonSerializable;
use Override;
use Stringable;

class SearchParser implements JsonSerializable
{
    /**
     * @var array<string, QueryAdapterInterface>
     */
    private array $adapters = [];

    /**
     * @var array<string, Field>
     */
    private array $allowedFields = [];

    /**
     * @var array<string, AllowedSort>
     */
    private array $allowedSorts = [];

    private bool $allowsAnyField = false;

    private bool $allowsLeadingWildcards = false;

    /**
     * @var array<string, bool>
     */
    private array $authorization = [];

    private int $clauseLimit = 1_024;

    /**
     * @var array<string, Closure(): QueryAdapterInterface>
     */
    private array $customCreators = [];

    private null|string $defaultSort = null;

    private int $depthLimit = 32;

    /**
     * @var list<Clause>
     */
    private array $directiveClauses = [];

    private int $lengthLimit = 10_000;

    /**
     * @var list<Field>
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

    /**
     * @var list<string>
     */
    private array $terms = [];

    public function __construct(private readonly mixed $query = '') {}

    public static function query(mixed $query): self
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
     * @param list<Field|string> $fields
     */
    public function allowedFields(array $fields): self
    {
        if ($this->allowedSorts !== []) {
            $this->assertSortNotReserved($fields);
        }

        $this->allowedFields = [];
        $this->authorization = [];
        $this->patternFields = [];

        foreach ($fields as $field) {
            $field = is_string($field) ? new Field($field) : $field;

            if (isset($this->allowedFields[$field->getName()])) {
                throw new InvalidArgumentException(
                    sprintf("Field '%s' is configured more than once.", $field->getName()),
                );
            }

            $this->allowedFields[$field->getName()] = $field;

            $field->assertValidConfiguration();

            if ($field->isPattern()) {
                $this->patternFields[] = $field;
            }
        }

        usort($this->patternFields, static fn (Field $left, Field $right): int
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

    public function compile(): null|ClauseGroup
    {
        $terms = $this->getTermFields();
        $node = $this->parse();

        if (! $node instanceof NodeInterface) {
            return null;
        }

        $clause = $this->compileNode(
            new NegationNormalFormTransformer()->transform($node),
            new ClauseCompiler(),
            $terms,
        );

        return $clause instanceof ClauseGroup ? $clause : new ClauseGroup(true, [$clause]);
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

    public function getAllowedField(string $field): null|Field
    {
        $configured = $this->getConfiguredField($field);

        return $configured instanceof Field && $this->isAuthorized($configured)
            ? $configured
            : null;
    }

    /**
     * @return list<string>
     */
    public function getAllowedFieldNames(): array
    {
        return array_keys($this->getAllowedFields());
    }

    /**
     * @return array<string, Field>
     */
    public function getAllowedFields(): array
    {
        return array_filter($this->allowedFields, $this->isAuthorized(...));
    }

    /**
     * @return array<string, AllowedSort>
     */
    public function getAllowedSorts(): array
    {
        return $this->allowedSorts;
    }

    /**
     * @return array<string, Field>
     */
    public function getConfiguredFields(): array
    {
        return $this->allowedFields;
    }

    public function getDefaultAdapter(): string
    {
        return 'array';
    }

    /**
     * @return list<Clause>
     */
    public function getDirectiveClauses(): array
    {
        return $this->directiveClauses;
    }

    /**
     * @return array<int|string, bool|float|int|string>
     */
    public function getDirectives(): array
    {
        return $this->settings['directives'] ?? [];
    }

    public function getInternalFieldName(string $field): string
    {
        $allowedField = $this->getAllowedField($field);
        $key = $allowedField?->keyOf($field);

        if ($key !== null) {
            return mb_substr($allowedField->getInternalName(), 0, -1).$key;
        }

        return $allowedField?->getInternalName() ?? $field;
    }

    public function getSort(): null|Sort
    {
        return $this->settings['sort'] ?? null;
    }

    /**
     * @return list<Field>
     */
    public function getTermFields(): array
    {
        foreach ($this->terms as $name) {
            $field = $this->allowedFields[$name] ?? null;

            if (
                $field === null
                || $field->isPattern()
                || $field->isDirective()
                || $field->isDate()
            ) {
                throw new InvalidArgumentException(sprintf(
                    "Term field '%s' must be an exact configured field without a date type or directive.",
                    $name,
                ));
            }
        }

        return array_values(array_filter(
            array_map(fn (string $name): Field => $this->allowedFields[$name], $this->terms),
            $this->isAuthorized(...),
        ));
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
        $configured = $this->getConfiguredField($field);

        return $configured instanceof Field
            ? $this->isAuthorized($configured) && $configured->accepts($field)
            : $this->allowsAnyField;
    }

    /**
     * @return array{
     *     fields: list<array<string, mixed>>,
     *     sorts: list<array{direction: 'asc'|'desc', name: string}>,
     * }
     */
    #[Override]
    public function jsonSerialize(): array
    {
        return [
            'fields' => array_map(
                static fn (Field $field): array => $field->jsonSerialize(),
                array_values($this->getAllowedFields()),
            ),
            'sorts' => array_map(
                static fn (AllowedSort $sort): array => $sort->jsonSerialize(),
                array_values($this->allowedSorts),
            ),
        ];
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
        $this->directiveClauses = [];

        if (
            $this->query !== null
            && ! is_string($this->query)
            && ! $this->query instanceof Stringable
        ) {
            throw ParseException::invalidType();
        }

        $query = (string) $this->query;

        if (mb_strlen($query) > $this->lengthLimit) {
            throw ParseException::maximumLength($this->lengthLimit);
        }

        $defaults = [];

        foreach ($this->allowedFields as $field) {
            if ($field->hasDefault()) {
                $defaults[] = $field;
            }
        }

        $parser = new Parser($this, $this->depthLimit, $this->clauseLimit);
        $ast = $parser->parse($query, $defaults !== []);

        if ($defaults === [] && ! $parser->hasSettings() && $this->defaultSort === null) {
            return $ast;
        }

        $resolved = $parser->resolveDirectives();
        $typedDirectives = array_map(
            static fn (array $directive): bool|float|int|string => $directive['value'],
            $resolved,
        );
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

                    if (! $this->validateValue($field, $value)) {
                        throw new InvalidArgumentException(
                            sprintf("Invalid default for field '%s'.", $name),
                        );
                    }
                }

                if (! $this->isAuthorized($field)) {
                    continue;
                }

                if ($field->isDirective()) {
                    /** @var bool|float|int|string $value */
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
                    $this->directiveClauses[] = new Clause(
                        $name,
                        Operator::Equal,
                        $field->toStorage($resolvedDirectives[$name]),
                        $resolved[$name]['position'] ?? null,
                    );
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

    /**
     * @param list<string> $names
     */
    public function terms(array $names): self
    {
        $this->terms = $names;

        return $this;
    }

    public function validateField(string $field, mixed $value): bool
    {
        $allowed = $this->getAllowedField($field);

        return ! $allowed instanceof Field || $this->validateValue($allowed, $value);
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
     * @param list<Field|string> $fields
     */
    private function assertSortNotReserved(array $fields): void
    {
        if (array_any($fields, static fn (Field|string $field): bool
            => mb_strtolower(is_string($field) ? $field : $field->getName()) === 'sort')) {
            throw new InvalidArgumentException(
                "Field 'sort' is reserved when sorts are configured.",
            );
        }
    }

    private function castDefault(
        Field $field,
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

    /**
     * @param list<Field> $terms
     */
    private function compileNode(
        NodeInterface $node,
        ClauseCompiler $compiler,
        array $terms,
    ): Clause|ClauseGroup {
        if ($node instanceof BinaryOperatorNode) {
            return new ClauseGroup(
                $node->getOperator() === 'AND',
                array_map(
                    fn (NodeInterface $child): Clause|ClauseGroup
                        => $this->compileNode($child, $compiler, $terms),
                    $this->flatten($node, $node->getOperator()),
                ),
            );
        }

        $negated = $node instanceof UnaryOperatorNode;
        $node = $negated ? $node->getOperand() : $node;

        if (
            $node instanceof TermNode
            || ($node instanceof WildcardNode && $node->getField() === null)
        ) {
            $clauses = [];

            foreach ($terms as $field) {
                $clause = $compiler->term($field, $node, $negated);

                if ($clause !== null) {
                    $clauses[] = $clause;
                }
            }

            return count($clauses) === 1 ? $clauses[0] : new ClauseGroup($negated, $clauses);
        }

        /**
         * @var ComparisonNode|ExistsNode|InNode|RangeNode|WildcardNode $node
         * @var string $name
         */
        $name = $node->getField();
        $field = $this->getAllowedField($name) ?? new Field($name);
        $clauses = $compiler->compile($field, $node, $negated);

        return count($clauses) === 1
            ? $clauses[0]
            : new ClauseGroup($negated, $clauses, $name);
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

    /**
     * @return list<NodeInterface>
     */
    private function flatten(NodeInterface $node, string $operator): array
    {
        if ($node instanceof BinaryOperatorNode && $node->getOperator() === $operator) {
            return [
                ...$this->flatten($node->getLeft(), $operator),
                ...$this->flatten($node->getRight(), $operator),
            ];
        }

        return [$node];
    }

    private function getConfiguredField(string $field): null|Field
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

    private function isAuthorized(Field $field): bool
    {
        return $this->authorization[$field->getName()] ??= $field->isAuthorized();
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

    private function validateValue(Field $field, mixed $value): bool
    {
        if ($field->expectsArray()) {
            return $field->validate(is_array($value) ? $value : [$value]);
        }

        return is_array($value)
            ? array_all($value, $field->validate(...))
            : $field->validate($value);
    }
}
