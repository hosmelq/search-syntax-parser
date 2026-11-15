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
use HosmelQ\SearchSyntaxParser\Exception\ParseException;
use HosmelQ\SearchSyntaxParser\Parser\Parser;
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

    private bool $allowsAnyField = false;

    private int $clauseLimit = 1_024;

    /**
     * @var array<string, Closure(): QueryAdapterInterface>
     */
    private array $customCreators = [];

    private int $depthLimit = 32;

    private int $lengthLimit = 10_000;

    /**
     * Create a new SearchParser instance.
     */
    public function __construct(private readonly string $query = '') {}

    /**
     * Create a new SearchParser instance for the given query.
     */
    public static function query(string $query): self
    {
        return new self($query);
    }

    /**
     * Get an adapter instance.
     */
    public function adapter(null|string $adapter = null): QueryAdapterInterface
    {
        $adapter ??= $this->getDefaultAdapter();

        return $this->adapters[$adapter] ??= $this->createAdapter($adapter);
    }

    /**
     * Accept unconfigured fields in addition to any configured field rules.
     */
    public function allowAnyField(): self
    {
        $this->allowsAnyField = true;

        return $this;
    }

    /**
     * Set allowed fields with optional validation.
     *
     * @param list<AllowedField|string> $fields
     */
    public function allowedFields(array $fields): self
    {
        $this->allowedFields = [];

        foreach ($fields as $field) {
            if ($field instanceof AllowedField) {
                $this->allowedFields[$field->getName()] = $field;
            } elseif (is_string($field)) {
                $this->allowedFields[$field] = new AllowedField($field);
            }
        }

        return $this;
    }

    /**
     * Parse and build a query using the specified or default adapter.
     * Return null without calling the adapter when no constraints remain.
     *
     * @throws InvalidArgumentException When a configured default is invalid or the adapter is unknown.
     * @throws ParseException
     */
    public function build(null|string $adapter = null): mixed
    {
        $ast = $this->parse();
        $builder = $this->adapter($adapter);

        return $ast instanceof NodeInterface ? $builder->build($ast) : null;
    }

    /**
     * Register a custom adapter creator.
     *
     * @param callable(): QueryAdapterInterface $callback
     */
    public function extend(string $adapter, callable $callback): static
    {
        $this->customCreators[$adapter] = Closure::fromCallable($callback);

        return $this;
    }

    /**
     * Get AllowedField object for a specific field.
     */
    public function getAllowedField(string $field): null|AllowedField
    {
        return $this->allowedFields[$field] ?? null;
    }

    /**
     * Get all allowed field names.
     *
     * @return list<string>
     */
    public function getAllowedFieldNames(): array
    {
        return array_keys($this->allowedFields);
    }

    /**
     * Get the default adapter name.
     */
    public function getDefaultAdapter(): string
    {
        return 'array';
    }

    /**
     * Check if a field is allowed for searching.
     */
    public function isFieldAllowed(string $field): bool
    {
        return $this->allowsAnyField || isset($this->allowedFields[$field]);
    }

    /**
     * Limit the number of leaf clauses in a query.
     */
    public function maxClauses(int $clauses): self
    {
        if ($clauses <= 0) {
            throw new InvalidArgumentException('Maximum clauses must be positive.');
        }

        $this->clauseLimit = $clauses;

        return $this;
    }

    /**
     * Limit nesting of groups and negation operators.
     */
    public function maxDepth(int $depth): self
    {
        if ($depth <= 0) {
            throw new InvalidArgumentException('Maximum depth must be positive.');
        }

        $this->depthLimit = $depth;

        return $this;
    }

    /**
     * Limit the query length in characters, checked before tokenizing.
     */
    public function maxLength(int $characters): self
    {
        if ($characters <= 0) {
            throw new InvalidArgumentException('Maximum length must be positive.');
        }

        $this->lengthLimit = $characters;

        return $this;
    }

    /**
     * Parse the query string into an AST, or null when no constraints or defaults remain.
     *
     * @throws InvalidArgumentException When a configured default is invalid for its field.
     * @throws ParseException
     */
    public function parse(): null|NodeInterface
    {
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

        if ($defaults !== []) {
            $referenced = $parser->getReferencedFields();

            foreach ($defaults as $field) {
                $name = $field->getName();

                if (isset($referenced[$name])) {
                    continue;
                }

                $value = $field->getDefault();

                // Validate like the equivalent query clause: list items one by one on scalar
                // fields, a scalar as a one-item collection on array fields.
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

        return $ast;
    }

    /**
     * Validate a field value.
     */
    public function validateField(string $field, mixed $value): bool
    {
        if (isset($this->allowedFields[$field])) {
            $allowed = $this->allowedFields[$field];

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

    /**
     * Create the Array adapter.
     */
    protected function createArrayAdapter(): ArrayAdapter
    {
        return new ArrayAdapter($this);
    }

    protected function createStringAdapter(): StringAdapter
    {
        return new StringAdapter($this);
    }

    /**
     * Read a string default like typed query text; other scalars must already have the cast type.
     */
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

    /**
     * Create an adapter instance.
     */
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
     * Convert a string to a studly caps case.
     */
    private function studly(string $value): string
    {
        $value = ucwords(str_replace(['-', '_'], ' ', $value));

        return str_replace(' ', '', $value);
    }
}
