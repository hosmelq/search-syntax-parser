<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser;

use Closure;
use HosmelQ\SearchSyntaxParser\Adapter\ArrayAdapter;
use HosmelQ\SearchSyntaxParser\Adapter\QueryAdapterInterface;
use HosmelQ\SearchSyntaxParser\AST\Node\NodeInterface;
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

    /**
     * @var array<string, Closure(): QueryAdapterInterface>
     */
    private array $customCreators = [];

    /**
     * Create a new SearchParser instance.
     */
    public function __construct(private readonly string $query = '')
    {
    }

    /**
     * Create a new SearchParser instance for the given query.
     */
    public static function query(string $query): self
    {
        return new self(mb_trim($query));
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
        if ($this->query === '') {
            throw ParseException::emptyQuery();
        }

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
     * Parse the query string into an AST.
     *
     * @throws ParseException
     */
    public function parse(): NodeInterface
    {
        if ($this->query === '') {
            throw ParseException::emptyQuery();
        }

        $parser = new Parser($this);

        return $parser->parse($this->query);
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
                foreach ($value as $item) {
                    if (! $allowed->validate($item)) {
                        return false;
                    }
                }

                return true;
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
