<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser;

use Closure;
use HosmelQ\SearchSyntaxParser\Adapter\ArrayAdapter;
use HosmelQ\SearchSyntaxParser\Adapter\QueryAdapterInterface;
use HosmelQ\SearchSyntaxParser\AST\Node\NodeInterface;
use HosmelQ\SearchSyntaxParser\Configuration\SearchConfiguration;
use HosmelQ\SearchSyntaxParser\Exception\ParseException;
use HosmelQ\SearchSyntaxParser\Parser\Parser;
use InvalidArgumentException;

class SearchParser
{
    /**
     * @var array<string, QueryAdapterInterface>
     */
    private array $adapters = [];

    /**
     * The search configuration instance.
     */
    private readonly SearchConfiguration $config;

    /**
     * @var array<string, Closure(): QueryAdapterInterface>
     */
    private array $customCreators = [];

    /**
     * The parser instance that handles query parsing.
     */
    private readonly Parser $parser;

    /**
     * Create a new SearchParser instance.
     */
    public function __construct(null|SearchConfiguration $configuration = null)
    {
        $this->config = $configuration ?? new SearchConfiguration();
        $this->parser = new Parser($this->config);
    }

    /**
     * Convenience factory method to create a parser with a given configuration.
     */
    public static function create(SearchConfiguration $configuration): self
    {
        return new self($configuration);
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
     * Parse and build a query using the specified or default adapter.
     *
     * @throws ParseException
     */
    public function build(string $query, null|string $adapter = null): mixed
    {
        $ast = $this->parse($query);

        return $this->adapter($adapter)->build($ast);
    }

    /**
     * Register a custom adapter creator Closure.
     */
    public function extend(string $adapter, Closure $callback): static
    {
        $this->customCreators[$adapter] = $callback;

        return $this;
    }

    /**
     * Get the default adapter name.
     */
    public function getDefaultAdapter(): string
    {
        return 'array';
    }

    /**
     * Parse a search query string into an AST.
     *
     * @throws ParseException
     */
    public function parse(string $query): NodeInterface
    {
        return $this->parser->parse($query);
    }

    /**
     * Create the Array adapter.
     */
    protected function createArrayAdapter(): ArrayAdapter
    {
        return new ArrayAdapter($this->config);
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
        $value = str_replace(['-', '_'], ' ', $value);
        $value = ucwords($value);

        return str_replace(' ', '', $value);
    }
}
