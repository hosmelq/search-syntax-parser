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

- PHP 8.4+

The package is framework-independent and can be installed alongside Laravel 12 and 13.

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

`NOT` and `-` bind first, then explicit or implicit `AND`, then `OR`. Use parentheses to change the grouping. `AND`, `OR`, and `NOT` are case-insensitive; in a field value such as `title:and`, they are literal text.

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

Use multi-value field searches to compare a field against a list:

```php
// Single field, multiple values
SearchParser::query('status:ACTIVE,DRAFT,PENDING')->build();
// Equivalent to: status:ACTIVE OR status:DRAFT OR status:PENDING

// Exclude every listed value
SearchParser::query('status:!=SOLD,EXPIRED')->build();
// Equivalent to: status:!=SOLD AND status:!=EXPIRED

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

Only an unquoted value exactly equal to `*` produces an exists query, and only with `:`. Other patterns (`nike*`, `*fee`, `co*fee`, `12*`) remain literal values, including their asterisks; adapters decide how to use them. A quoted `"*"` is a literal string. A bare `*` term and comparisons such as `price:>*` are invalid.

#### Range queries

Search within value ranges using brackets. Both bounds use the field's casting and validation rules:

```php
SearchParser::query('price:[10 TO 50]')->build();                 // Numeric range
SearchParser::query('date:[2025-01-01 TO 2025-12-31]')->build();  // Date range
SearchParser::query('price:[10 TO *]')->build();                  // Open-ended range
```

An unquoted `*` bound is open-ended: it is returned as `*` and is not cast or validated. `TO` is a case-insensitive separator only inside ranges. Elsewhere it is ordinary text, so `go to market` produces three terms.

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

Words support Unicode, hyphens and punctuation such as `nombre:José`, `title:t-shirt`, and `email:john@example.com`. A leading `-` negates a term; after a field operator it joins an adjacent word as a negative value, so `temp:>-5`, `price:[-5 TO 5]`, and `location:37.7749,-122.4194` work. Standalone terms keep their text, including `007` and `12.50`.

### Quoted values

Use single or double quotes for values containing spaces or reserved punctuation (`(`, `)`, `[`, `]`, `:`, `,`, `<`, `>`). For example, write `url:"https://example.com"` or `time:"12:30"`. ISO 8601 datetimes such as `created_at:>2025-01-01T12:30:45.123Z` may be unquoted, with or without a timezone.

Inside quotes, only `\\`, `\"`, and `\'` are unescaped. A quoted value immediately followed by `*`, such as `title:"Co"*`, produces `Co*`. Unterminated quotes and invalid UTF-8 throw `ParseException`.

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

### Value types

Without a typed field, quoted values stay strings. Unquoted `true` and `false` become booleans, case-insensitively. Whole numbers without leading zeros that fit in a PHP integer become integers; decimal literals such as `12.50` and `-0.5` become floats. Leading-zero identifiers (`01234`), overflowing integers, exponent notation (`1e5`), and wildcard values (`12*`) stay strings. This inference also applies to string entries in `allowedFields()`, `new AllowedField(...)`, and `AllowedField::callback()`.

Typed fields cast their values regardless of quotes:

- `string()` preserves text, including `12345` and `true`.
- `integer()` accepts whole numbers without leading zeros that fit in a PHP integer.
- `numeric()` produces an integer when possible, otherwise a float for numeric input, including exponent notation.
- `decimal()` preserves a numeric string and its decimal places. `AllowedField::decimal('price', 2)` accepts `price:10.50` and outputs `"10.50"`.
- `boolean()` maps `true`/`1` and `false`/`0` to booleans, case-insensitively.
- `in()` returns the allowed value whose string form matches the input text or its inferred value, keeping that value's type. `in('code', ['01', '02'])` keeps `01` as a string; `in('level', [1, 2, 3])` casts `2` to an integer; boolean allowed values match `true`/`1` and `false`/`0` like `boolean()`.

Invalid typed input fails validation. Size rules (`min`, `max`, `between`, `size`) compare numbers and numeric strings by value, other strings by character length, and arrays by item count, so `decimal('price', 2)->max(100)` rejects `150.00`. `string()` fields and item rules always measure character length, even for numeric-looking text such as `12345`. Values without a size, such as booleans, fail size validation.

### Array item validation (each and at)

For array fields, use `each()` to validate every item and `at(index)` to validate specific positions. Each item uses its `at()` type when set, otherwise its `each()` type, otherwise inference. All configured validators still apply, and size rules from both use that item type regardless of rule order.

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

### Query limits

By default, queries allow up to 10,000 characters, 32 nested parenthesized groups and `NOT`/`-` operators, and 1024 leaf clauses. Comparisons, lists, ranges, exists queries and terms each count as one clause; a list's items do not add clauses. The length is checked before tokenizing, and excess input throws `ParseException` before building an unsafe AST.

Configure the limits for your input:

```php
$result = SearchParser::query('NOT (title:Coffee OR title:Tea)')
    ->maxLength(500)
    ->maxDepth(16)
    ->maxClauses(100)
    ->build();
```

Limits must be positive integers; non-positive values throw `InvalidArgumentException`. Keep these limits bounded for untrusted input: very large values, such as tens of thousands of clauses, can exhaust memory or crash PHP while building or freeing the AST.

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
