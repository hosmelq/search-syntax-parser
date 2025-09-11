# Search Syntax Parser

Parse search queries into structured data with support for field searches, boolean logic, range comparisons, and multiple output formats.

## Introduction

Use a concise, expressive query language to build structured queries. It supports field-specific searches, boolean operators, ranges, existence checks, and multi-value lists, and outputs to multiple formats via adapters.

```php
use HosmelQ\SearchSyntaxParser\SearchParser;

$result = SearchParser::query('age:>25 AND name:john')->build();
```

It returns a normalized PHP array describing the query.

## Requirements

- PHP 8.2+

## Installation & setup

Install the package via composer:

```bash
composer require hosmelq/search-syntax-parser
```

## Basic usage

### Getting started

Create a parser from a query string and build it using the default array adapter:

```php
use HosmelQ\SearchSyntaxParser\SearchParser;

$result = SearchParser::query('title:Coffee AND price:<10')->build();
```

### Default adapter (array)

When no adapter is provided, `build()` uses the array adapter and returns a structured PHP array.

```php
use HosmelQ\SearchSyntaxParser\SearchParser;

$result = SearchParser::query('title:Coffee')->build();

// Array output
[
    'field' => 'title',
    'operator' => '=',
    'type' => 'comparison',
    'value' => 'Coffee',
]
```

### Query types

#### Connectives (AND/OR)

Combine multiple terms with logical operators. When no connective is specified, AND is implied.

```php
SearchParser::query('title:Coffee AND price:<10')->build(); // Explicit AND
SearchParser::query('title:Coffee OR title:Tea')->build();  // OR operator
SearchParser::query('title:Coffee price:<10')->build();     // Implicit AND
```

#### Comparators

Use field comparison operators to define the relationship between a field and its value:

```php
SearchParser::query('price:>10')->build();      // Greater than
SearchParser::query('price:>=10')->build();     // Greater than or equal
SearchParser::query('price:<50')->build();      // Less than
SearchParser::query('price:<=50')->build();     // Less than or equal
SearchParser::query('status:!=sold')->build();  // Not equal
```

#### Comma-separated values

Use multi-value field searches as syntactic sugar for OR operations:

```php
// Single field, multiple values
SearchParser::query('status:ACTIVE,DRAFT,PENDING')->build();
// Equivalent to: status:ACTIVE OR status:DRAFT OR status:PENDING

// Works with any operator
SearchParser::query('status:!=SOLD,EXPIRED')->build();
// Equivalent to: status:!=SOLD OR status:!=EXPIRED

// Can be combined with boolean logic
SearchParser::query('status:ACTIVE,DRAFT AND price:>100')->build();

// Supports quoted values
SearchParser::query('category:"Home & Garden","Sports & Outdoors"')->build();
```

#### Exists queries

Search for documents with non-null values in specified fields using wildcard syntax:

```php
SearchParser::query('category:*')->build();      // Field has any value
SearchParser::query('NOT discount:*')->build();  // Field doesn't exist
```

#### Range queries

Search within value ranges using boundary operators:

```php
SearchParser::query('price:[10 TO 50]')->build();                 // Numeric range
SearchParser::query('date:[2025-01-01 TO 2025-12-31]')->build();  // Date range
```

#### Terms

Search using basic terms that match default searchable fields:

```php
SearchParser::query('coffee')->build();
```

#### Modifiers (NOT)

Negate terms or subqueries using `-` or `NOT`:

```php
SearchParser::query('NOT title:Coffee')->build();  // NOT modifier
SearchParser::query('-title:Coffee')->build();     // - modifier (equivalent)
```

### Field validation

Restrict which fields can be used and validate their values using `AllowedField` helpers:

```php
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;

$parser = SearchParser::query('age:25 AND status:ACTIVE')->allowedFields([
    AllowedField::integer('age')->min(0),
    AllowedField::in('status', ['ACTIVE', 'DRAFT', 'PENDING']),
    AllowedField::string('name')->size(2),
]);

$result = $parser->build(); // throws if any value is invalid or a field is not allowed
```

You can also map external field names to internal ones:

```php
$parser = SearchParser::query('age:10')->allowedFields([
    AllowedField::integer('age', 'user_age'),
]);

$result = $parser->build();
// The array adapter will output the internal name "user_age" for the field
```

### Array item validation (each and at)

For array fields, use `each()` to validate every item and `at(index)` to validate specific positions.

Per‑item rules with `each()`:

```php
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;

$parser = SearchParser::query('tags:alpha,beta')
    ->allowedFields([
        AllowedField::array('tags')
            ->max(5)
            ->each(fn ($rules) => $rules->string()->max(10)),
    ]);

$result = $parser->build();
```

Index‑specific rules with `at()` (tuple‑like arrays):

```php
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;

$parser = SearchParser::query('location:37.7749,-122.4194')
    ->allowedFields([
        AllowedField::array('location')
            ->size(2)
            ->at(0, fn ($rules) => $rules->numeric()->between(-90, 90))
            ->at(1, fn ($rules) => $rules->numeric()->between(-180, 180)),
    ]);

$result = $parser->build();
```

### Custom adapters

Create custom output formats by implementing the adapter interface:

```php
use HosmelQ\SearchSyntaxParser\Adapter\QueryAdapterInterface;
use HosmelQ\SearchSyntaxParser\AST\Node\NodeInterface;
use HosmelQ\SearchSyntaxParser\SearchParser;

class EloquentAdapter implements QueryAdapterInterface
{
    public function build(NodeInterface $ast): mixed
    {
        // Convert the AST to your preferred format
        return ['where' => ['name', 'john']];
    }
}

$parser = SearchParser::query('name:john');

$parser->extend('eloquent', fn () => new EloquentAdapter());

$result = $parser->build('eloquent');
```

### Error handling

Handle parsing errors with `ParseException`:

```php
use HosmelQ\SearchSyntaxParser\Exception\ParseException;
use HosmelQ\SearchSyntaxParser\SearchParser;

try {
    SearchParser::query('invalid:syntax:here')->build();
} catch (ParseException $e) {
    echo "Parse error: " . $e->getMessage();
}
```

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG.md](CHANGELOG.md) for more information on what has changed recently.

## Credits

- [Hosmel Quintana](https://github.com/hosmelq)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
