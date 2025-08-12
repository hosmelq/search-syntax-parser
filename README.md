# Search Syntax Parser

Parse search queries into structured data with support for field searches, boolean logic, range comparisons, and multiple output formats.

## Features

- **Abstract Syntax Tree** - Generate structured AST from search queries
- **Boolean Operators** - `AND`, `OR`, `NOT` operations with correct precedence handling
- **Built-in ArrayAdapter** - Ready-to-use array conversion
- **Exists Queries** - Check field presence with `field:*` syntax
- **Extensible Adapters** - Convert AST to multiple output formats
- **Field-Specific Searches** - Support queries like `age:>25` and `name:john`
- **Field Validation** - Custom validators and field restrictions
- **Range Queries** - Range searches with `field:[from TO to]` syntax

## Requirements

- PHP 8.2+

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
    "value" => 25
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

### Query Types

#### Connectives (AND/OR)

Logical operators that combine multiple terms. When no connective is specified, AND is implied.

```php
$result = $parser->build('title:Coffee AND price:<10');  // Explicit AND
$result = $parser->build('title:Coffee OR title:Tea');   // OR operator
$result = $parser->build('title:Coffee price:<10');      // Implicit AND
```

#### Comparators

Field comparison operators that define the relationship between field and value:

```php
$result = $parser->build('price:>10');      // Greater than
$result = $parser->build('price:>=10');     // Greater than or equal
$result = $parser->build('price:<50');      // Less than
$result = $parser->build('price:<=50');     // Less than or equal
$result = $parser->build('status:!=sold');  // Not equal
```

#### Exists Queries

Search for documents with non-null values in specified fields using wildcard syntax:

```php
$result = $parser->build('category:*');      // Field has any value
$result = $parser->build('NOT discount:*');  // Field doesn't exist
```

#### Range Queries

Search within value ranges using boundary operators:

```php
$result = $parser->build('price:[10 TO 50]');                 // Numeric range
$result = $parser->build('date:[2025-01-01 TO 2025-12-31]');  // Date range
```

#### Terms

Basic search units that match against default searchable fields:

```php
$result = $parser->build('coffee');
```

#### Modifiers (NOT)

Operators that negate terms or subqueries. Both `-` and `NOT` modifiers are supported:

```php
$result = $parser->build('NOT title:Coffee');  // NOT modifier
$result = $parser->build('-title:Coffee');     // - modifier (equivalent)
```

### Field Validation

Configure field restrictions and custom validators using `SearchConfiguration`:

```php
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Configuration\SearchConfiguration;

$config = new SearchConfiguration();

// Allow only specific fields
$config->setAllowedFields(['age', 'date', 'name', 'status']);

// Set fields searched when no field specified
$config->setSearchableFields(['description', 'name']);

// Add custom validators
$config->addFieldValidator('age', fn ($value) => is_numeric($value) && $value >= 0);
$config->addFieldValidator('email', fn ($value) => filter_var($value, FILTER_VALIDATE_EMAIL));

// Configure parser limits
$config->setLimit('max_conditions', 10);
$config->setLimit('max_nesting_depth', 3);

$parser = new SearchParser($config);

// Valid queries
$result = $parser->build('age:25');     // Passes validation
$result = $parser->build('name:john');  // Allowed field

// Invalid queries will throw ParseException
try {
    $parser->build('age:-5');               // Fails validator
    $parser->build('invalid_field:value');  // Field not allowed
} catch (ParseException $e) {
    echo "Validation error: " . $e->getMessage();
}
```

### Custom Adapters

Create custom output formats by implementing the adapter interface:

```php
use HosmelQ\SearchSyntaxParser\Adapter\QueryAdapterInterface;
use HosmelQ\SearchSyntaxParser\AST\Node\NodeInterface;

class EloquentAdapter implements QueryAdapterInterface
{
    public function build(NodeInterface $ast): mixed
    {
        // Convert AST to your preferred format
        return ['where' => ['name', 'john']];
    }
}

// Register and use the adapter
$parser->extend('eloquent', fn() => new EloquentAdapter());

$result = $parser->build('name:john', 'eloquent');
```

### Error Handling

Handle parsing errors with ParseException:

```php
use HosmelQ\SearchSyntaxParser\Exception\ParseException;

try {
    $result = $parser->build('invalid:syntax:here');
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
