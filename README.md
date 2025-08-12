# Search Syntax Parser

Parse complex search queries into structured data with support for field searches, boolean logic, range comparisons, and multiple output formats.

Built on Doctrine Lexer with extensible adapter architecture.

## Features

- **Field-Specific Searches** - Support queries like `age:>25` and `name:john`.
- **Boolean Operators** - `AND`, `OR`, `NOT` operations with correct precedence handling.
- **Range Comparisons** - Greater than, less than, and equality operators.
- **Complex Query Parsing** - Handle sophisticated search expressions with proper precedence.
- **Abstract Syntax Tree** - Generate structured `AST` from search queries.
- **Extensible Adapters** - Convert `AST` to multiple output formats.
- **Built-in `ArrayAdapter`** - Ready-to-use array conversion with customization options.

## Requirements

- PHP 8.2+.

## Installation

```bash
composer require hosmelq/search-syntax-parser
```

## Basic Usage

```php
use HosmelQ\SearchSyntaxParser\SearchParser;

$parser = new SearchParser();

// Parse and build a query
$result = $parser->build('age:>25 AND name:john');

// Result
array:4 [
  "left" => array:4 [
    "field" => "age"
    "operator" => ">"
    "type" => "comparison"
    "value" => "25"
  ]
  "operator" => "AND"
  "right" => array:4 [
    "field" => "name"
    "operator" => "="
    "type" => "comparison"
    "value" => "john"
  ]
  "type" => "binary"
]
```

## Usage

### Query Parsing

Parse search strings into Abstract Syntax Tree (`AST`) nodes:

```php
use HosmelQ\SearchSyntaxParser\SearchParser;

$parser = new SearchParser();

// Simple term
$ast = $parser->parse('shoes');

// Result
HosmelQ\SearchSyntaxParser\AST\Node\TermNode {
  -value: "shoes"
}

// Field search
$ast = $parser->parse('name:john');

// Result
HosmelQ\SearchSyntaxParser\AST\Node\ComparisonNode {
  -field: "name"
  -operator: "="
  -value: "john"
}

// Complex boolean expression
$ast = $parser->parse('age:>25 AND (name:jane OR name:john)');

// Result
HosmelQ\SearchSyntaxParser\AST\Node\BinaryOperatorNode {
  -left: HosmelQ\SearchSyntaxParser\AST\Node\ComparisonNode {
    -field: "age"
    -operator: ">"
    -value: "25"
  }
  -operator: "AND"
  -right: HosmelQ\SearchSyntaxParser\AST\Node\BinaryOperatorNode {
    -left: HosmelQ\SearchSyntaxParser\AST\Node\ComparisonNode {
      -field: "name"
      -operator: "="
      -value: "jane"
    }
    -operator: "OR"
    -right: HosmelQ\SearchSyntaxParser\AST\Node\ComparisonNode {
      -field: "name"
      -operator: "="
      -value: "john"
    }
  }
}
```

### Building Queries

Convert parsed queries into structured data using adapters:

```php
$parser = new SearchParser();

// Using default ArrayAdapter
$result = $parser->build('age:>25 AND name:john');

// Explicitly specify adapter
$result = $parser->build('name:john', 'array');
```

### Adapters

#### ArrayAdapter

The built-in `ArrayAdapter` converts `AST` to nested arrays:

```php
$result = $parser->build('name:john', 'array');

// Result
array:4 [
  "field" => "name"
  "operator" => "="
  "type" => "comparison"
  "value" => "john"
]

// Complex query
$result = $parser->build('age:>25 AND name:john', 'array');

// Result
array:4 [
  "left" => array:4 [
    "field" => "age"
    "operator" => ">"
    "type" => "comparison" 
    "value" => "25"
  ]
  "operator" => "AND"
  "right" => array:4 [
    "field" => "name"
    "operator" => "="
    "type" => "comparison"
    "value" => "john"
  ]
  "type" => "binary"
]
```

#### Custom Adapters

Create custom adapters for your specific output format:

```php
use HosmelQ\SearchSyntaxParser\Adapter\QueryAdapterInterface;
use HosmelQ\SearchSyntaxParser\AST\Node\NodeInterface;

class EloquentAdapter implements QueryAdapterInterface
{
    public function build(NodeInterface $ast): array
    {
        // Convert AST to Eloquent query format
        return ['where' => ['name' => 'john']];
    }
}

// Register the adapter
$parser->extend('eloquent', fn() => new EloquentAdapter());

// Use the custom adapter
$eloquentQuery = $parser->build('name:john', 'eloquent');
```

### Error Handling

Handle parsing errors with `ParseException`:

```php
use HosmelQ\SearchSyntaxParser\Exception\ParseException;

try {
    $result = $parser->build('invalid:query:syntax');
} catch (ParseException $e) {
    echo "Parse error: " . $e->getMessage();
}
```

## Testing

```bash
composer test
```

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for a list of changes.

## Credits

- [Hosmel Quintana](https://github.com/hosmelq)
- [All Contributors](../../contributors)

**Built on:**
- [Doctrine Lexer](https://github.com/doctrine/lexer)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
