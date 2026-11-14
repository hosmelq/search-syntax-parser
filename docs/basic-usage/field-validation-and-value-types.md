---
title: "Field validation and value types"
description: "Restrict searchable fields and control scalar casting with string, numeric, boolean, date, enum, and custom rules."
weight: 2
---

Pass field names or `AllowedField` objects to `allowedFields()`. With no configured fields, every field is allowed; otherwise unknown fields throw `InvalidFieldException`.

```php
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;

$result = SearchParser::query('age:25 status:ACTIVE')->allowedFields([
    AllowedField::integer('age')->min(0),
    AllowedField::in('status', ['ACTIVE', 'DRAFT']),
    AllowedField::string('name')->max(100),
])->build();

assert($result['left']['value'] === 25);
```

## Value inference

Untyped field values infer booleans from `true` and `false`, case-insensitively. Whole numbers without leading zeros that fit in a PHP integer become integers. Complete decimal literals such as `12.50` and `-0.5` become floats. Quoted values stay strings.

Leading-zero identifiers (`01234`), overflowing integers, exponent notation (`1e5`), and incomplete decimals (`5.` and `.5`) stay strings. This inference applies to string entries in `allowedFields()`, `new AllowedField(...)`, and callback fields. Standalone terms always preserve their text. Wildcard patterns have explicit nodes rather than scalar values.

## Typed fields

Typed fields cast from raw text regardless of quoting.

| Helper | Casting and validation |
| --- | --- |
| `string($name, $internalName = null)` | Preserves text, including numeric and boolean-looking text. |
| `integer($name, $internalName = null)` | Accepts whole numbers without leading zeros that fit in a PHP integer. |
| `numeric($name, $internalName = null)` | Produces an integer when possible, otherwise a float; accepts exponent notation. |
| `decimal($name, $min, $max = null, $internalName = null)` | Preserves decimal text; requires exactly `$min` decimal places, or `$min` through `$max`. |
| `boolean($name, $internalName = null)` | Maps `true`/`1` and `false`/`0` to booleans, case-insensitively. |
| `in($name, $values, $internalName = null)` | Returns the matching allowed scalar with its type preserved. |
| `date($name, $format = null, $internalName = null)` | Validates a date or datetime and preserves the raw text. |
| `enum($name, $enum, $internalName = null)` | Uses the backing values of a backed enum, with their integer or string types. |

`in()` matches the input text or its inferred numeric value. Boolean members match like `boolean()`. For example, `in('code', ['01', '02'])` preserves `01`, and `in('level', [1, 2])` casts `2` to an integer. Invalid typed values fail validation rather than being silently coerced.

```php
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;

assert(SearchParser::query('price:"10.50"')->allowedFields([
    AllowedField::decimal('price', 2)->max(100),
])->build()['value'] === '10.50');

assert(SearchParser::query('code:123')->allowedFields([
    AllowedField::string('code')->size(3),
])->build()['value'] === '123');
```

## Dates

Without a format, `date()` accepts `YYYY-MM-DD` and `YYYY-MM-DDTHH:MM[:SS[.fraction]][Z|±HH[:MM]]`. Dates, hours, minutes, seconds, and timezone components must be valid: `2025-02-30`, `25:00`, and minute `60` are rejected. The `T` and `Z` markers are uppercase.

With a format, PHP's `DateTimeImmutable::createFromFormat('!'.$format, $value)` must succeed without warnings or errors, and formatting the result with the same format must reproduce the input exactly. Date values stay strings; size rules retain their ordinary behavior and do not compare calendar dates.

```php
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;

assert(SearchParser::query('created:2024-02-29T12:30:45.123Z')->allowedFields([
    AllowedField::date('created'),
])->build()['value'] === '2024-02-29T12:30:45.123Z');

assert(SearchParser::query('birthday:"29/02/2024"')->allowedFields([
    AllowedField::date('birthday', 'd/m/Y'),
])->build()['value'] === '29/02/2024');
```

## Backed enums

`enum()` behaves like `in()` over `$enum::cases()` backing values. A unit enum or a class that is not a backed enum throws `InvalidArgumentException` when the rule is configured.

```php
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;

enum Status: string
{
    case Draft = 'draft';
    case Published = 'published';
}

assert(SearchParser::query('status:draft')->allowedFields([
    AllowedField::enum('status', Status::class),
])->build()['value'] === 'draft');
```

## Size rules and custom validation

Chain `min()`, `max()`, `between()`, and `size()`. Numbers and numeric strings are compared by value, other strings by character length, and arrays by item count. String fields always measure character length, including numeric-looking text. Booleans fail size validation because they have no size.

Use `callback()` for additional validation. `ignore(...$values)` skips validation for exact matching values; `nullable()` permits null when validating programmatic values. The query language itself has no null literal; use a missing-field expression or a [null default](defaults).

```php
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;

$result = SearchParser::query('rating:4')->allowedFields([
    AllowedField::callback('rating', fn (mixed $value, string $attribute): bool => $attribute === 'rating' && is_int($value) && $value >= 1 && $value <= 5),
])->build();

assert($result['value'] === 4);
```

## Internal field names

Every field helper accepts an optional internal name. Array adapters use that name, while AST nodes and the string adapter retain the external query name. Callback validation receives the internal name.

```php
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;

$parser = SearchParser::query('age:10')->allowedFields([
    AllowedField::integer('age', 'user_age'),
]);

assert($parser->build()['field'] === 'user_age');
assert($parser->build('string') === 'age:10');
```

For collections and tuples, see [array fields](array-fields). Validation failures expose the field and its position as described in [error handling](error-handling).
