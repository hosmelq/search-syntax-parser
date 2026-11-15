---
title: "Field validation and value types"
description: "Restrict searchable fields and control scalar casting with string, numeric, boolean, date, enum, and custom rules."
weight: 2
---

No fields are allowed by default. Pass field names or `AllowedField` objects to `allowedFields()` to permit field queries. Unknown fields throw `InvalidFieldException` with reason `field_not_allowed`; without an allowlist, the message says "No fields are allowed." Bare terms remain available without configuration.

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

Call `allowAnyField()` explicitly to accept every field. You can combine it with `allowedFields()` to keep casting, aliases, validation, restrictions, and defaults for configured fields while accepting other fields with value inference.

```php
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;

assert(SearchParser::query('score:4.5')->allowAnyField()->build()['value'] === 4.5);

$result = SearchParser::query('code:123 score:4.5')
    ->allowAnyField()
    ->allowedFields([AllowedField::string('code')])
    ->build();

assert($result['left']['value'] === '123');
assert($result['right']['value'] === 4.5);
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

## Probing a candidate value

Adapters can call `tryCast(string $value, bool $quoted = false, int $index = 0): null|bool|float|int|string` before using a bare term as a typed field value. It resolves aliases exactly like `cast()`, then validates the result. Invalid input returns `null` without throwing. Valid `false`, `0`, and `''` remain distinct from failure, so check with `!== null`.

```php
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;

$id = AllowedField::integer('id')->min(1)->alias('@me', 1042);

assert($id->tryCast('1042') === 1042);
assert($id->tryCast('abc') === null);
assert($id->tryCast('@me') === 1042);
assert($id->tryCast('@me', quoted: true) === null);
```

For an array field, the candidate is validated as a one-item collection at `$index`, using collection, shared item, and positional rules. The return value is the cast item. Exceptions thrown by aliases or validators propagate, as they do during parsing; `tryCast()` does not apply `ignore()` or query operator restrictions.

## Extending field rules

Field factories use late static binding and all fluent field methods return `static`. A subclass can chain its own methods after inherited factories and rules. `AllowedField` has a final constructor; add package configuration through methods.

```php
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;

class Field extends AllowedField
{
    public function packageMethod(): static
    {
        return $this;
    }
}

assert(Field::string('title')->min(1)->packageMethod() instanceof Field);
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

Use `callback()` for additional validation. `nullable()` declares that the stored value can be missing: a NULL column, an optional relation, or an absent key. Adapters use this metadata to include missing values in complements such as `NOT price:>10` or `status:!=closed`. It also makes `validate(null)` pass. The query language itself has no null literal; use a missing-field expression or a [null default](defaults).

```php
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;

$result = SearchParser::query('rating:4')->allowedFields([
    AllowedField::callback('rating', fn (mixed $value, string $attribute): bool => $attribute === 'rating' && is_int($value) && $value >= 1 && $value <= 5),
])->build();

assert($result['value'] === 4);
```

## Ignored values

`ignore(...$values)` removes constraints whose values strictly match an ignored value after alias resolution and casting. Removed values never reach validation. Operator and clause restrictions still run first.

A matching comparison disappears. Lists drop matching literal items and keep an `InNode`, even when only one item remains; an empty list disappears. Wildcard-expanded lists drop matching literal items but retain their patterns. Ignored range bounds become open bounds (`null`); a range disappears when both bounds are ignored values. Existing open `*` bounds are not ignored values. Wildcard patterns, existence checks, and bare terms are unaffected by `ignore()`.

AND and OR expressions with a removed side become their surviving side. NOT over a removed clause disappears, as does a group whose content is entirely removed. Remaining nodes keep their original source spans.

```php
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;

$fields = ['title', AllowedField::in('status', ['active', 'draft'])->ignore('ALL')];

assert(SearchParser::query('title:x AND status:ALL')->allowedFields($fields)->build('string') === 'title:x');
assert(SearchParser::query('title:x OR status:ALL')->allowedFields($fields)->build('string') === 'title:x');
assert(SearchParser::query('title:x NOT status:ALL')->allowedFields($fields)->build('string') === 'title:x');
assert(SearchParser::query('status:ALL,active')->allowedFields($fields)->parse()->getValues() === ['active']);
assert(SearchParser::query('status:[ALL TO draft]')->allowedFields($fields)->parse()->getFrom() === null);
```

A removed clause still mentions its field and suppresses that field's [default](defaults). If nothing remains and no default applies, `parse()` and `build()` return `null`. `build()` does not call the adapter.

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

## Operators and clause restrictions

All comparison operators and clause kinds are allowed by default. `operators()` restricts comparisons, lists, and wildcard patterns to a subset of `=`, `!=`, `>`, `>=`, `<`, and `<=`. Passing no operators permits none of those comparisons; an unknown operator throws `InvalidArgumentException`. Ranges and existence checks have their own controls. Use `withoutLists()`, `withoutRanges()`, `withoutWildcards()`, and `withoutExists()` to reject those forms. Literal asterisks inside quotes are allowed when wildcard patterns are disabled.

Restrictions run before aliases, casting, and value validation. A violation throws `InvalidFieldOperatorException` with the external field name and the explicit comparison operator's position, or the value start when no explicit operator exists. A range starts at `[`. Defaults bypass these restrictions, which govern what users type.

```php
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;

$status = AllowedField::in('status', ['active', 'draft'])
    ->operators('=', '!=')
    ->withoutRanges()
    ->withoutWildcards()
    ->withoutExists();

assert(SearchParser::query('status:active,draft')->allowedFields([$status])->parse()->getValues() === ['active', 'draft']);
```

## Value aliases

`alias($token, $value)` resolves an exact, case-sensitive unquoted field value before casting. The alias may be a boolean, float, integer, string, or a closure called without arguments at parse time. The result bypasses casting and still passes through the field's validators, including per-item array rules. Closures must return one of those scalar types; other returns throw `InvalidArgumentException`.

Aliases apply to comparisons, list items, and both range bounds. Quoted text never matches an alias, and a closure runs again each time the query is parsed. Open `*` bounds remain null and bypass aliases. String [defaults](defaults) resolve aliases too.

```php
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;

$userId = 42;
$owner = AllowedField::integer('owner')->alias('@me', fn (): int => $userId);
$enabled = AllowedField::boolean('enabled')->alias('yes', true);

assert(SearchParser::query('owner:@me')->allowedFields([$owner])->parse()->getValue() === 42);
assert(SearchParser::query('enabled:yes')->allowedFields([$enabled])->parse()->getValue() === true);
assert(SearchParser::query('owner:[@me TO *]')->allowedFields([$owner])->parse()->getFrom() === 42);
assert(SearchParser::query('label:"yes"')->allowedFields([
    AllowedField::string('label')->alias('yes', 'resolved'),
])->parse()->getValue() === 'yes');
```
