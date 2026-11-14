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
use HosmelQ\SearchSyntaxParser\AST\Node\UnaryOperatorNode;
use HosmelQ\SearchSyntaxParser\AST\Node\WildcardNode;
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
     *
     * @throws ParseException
     */
    public function build(null|string $adapter = null): mixed
    {
        $ast = $this->parse();

        return $this->adapter($adapter)->build($ast);
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
        return $this->allowedFields === [] || isset($this->allowedFields[$field]);
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
     * Parse the query string into an AST.
     *
     * @throws ParseException
     */
    public function parse(): NodeInterface
    {
        if (mb_strlen($this->query) > $this->lengthLimit) {
            throw ParseException::maximumLength($this->lengthLimit);
        }

        $defaults = array_filter(
            $this->allowedFields,
            static fn (AllowedField $field): bool => $field->hasDefault(),
        );
        $parser = new Parser($this, $this->depthLimit, $this->clauseLimit);
        $ast = $parser->parse($this->query, $defaults !== []);

        if ($defaults !== []) {
            $referenced = $ast instanceof NodeInterface ? $this->referencedFields($ast) : [];

            foreach ($defaults as $field) {
                $name = $field->getName();

                if (isset($referenced[$name])) {
                    continue;
                }

                $value = $field->getDefault();
                $node = match (true) {
                    $value === null => new UnaryOperatorNode('NOT', new ExistsNode($name)),
                    is_array($value) => new InNode($name, '=', $value),
                    default => new ComparisonNode($name, '=', $value),
                };
                $ast = $ast instanceof NodeInterface
                    ? new BinaryOperatorNode('AND', $ast, $node)
                    : $node;
            }
        }

        return $ast ?? throw ParseException::emptyQuery();
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
        return new StringAdapter();
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
     * @return array<string, true>
     */
    private function referencedFields(NodeInterface $ast): array
    {
        $fields = [];
        $pending = [$ast];

        while ($pending !== []) {
            $node = array_pop($pending);

            if ($node instanceof BinaryOperatorNode) {
                $pending[] = $node->getLeft();
                $pending[] = $node->getRight();
            } elseif ($node instanceof UnaryOperatorNode) {
                $pending[] = $node->getOperand();
            } elseif (
                $node instanceof ComparisonNode
                || $node instanceof InNode
                || $node instanceof RangeNode
                || $node instanceof ExistsNode
                || $node instanceof WildcardNode
            ) {
                $name = $node->getField();

                if ($name !== null) {
                    $fields[$name] = true;
                }
            }
        }

        return $fields;
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
