---
title: "Field Validation and Value Types"
description: "Choose searchable fields, validate and cast their values, and configure text matching, dates, aliases, and storage conversions."
weight: 2
---

Pass field names or `AllowedField` definitions to `allowedFields()` before accepting field queries. Bare terms work without configuration; unknown fields throw `InvalidFieldException` with reason `field.not_allowed`.

```php
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;

$filters = SearchParser::query('age:25 status:ACTIVE')->allowedFields([
    AllowedField::integer('age')->min(0),
    AllowedField::in('status', ['active', 'draft']),
    AllowedField::string('name')->max(100),
])->build();
```

The age becomes the integer `25`, and the status becomes the canonical string `active`. Call `allowAnyField()` to accept arbitrary names. You can combine it with field definitions: configured fields keep their rules, and other fields use value inference.

## Value inference

A string entry in `allowedFields()`, `new AllowedField(...)`, or a callback field uses ordinary value inference:

| Input | Value |
| --- | --- |
| `true`, `false`, ignoring case | Boolean. |
| Whole number without leading zeros, within PHP's integer range | Integer. |
| Complete decimal such as `12.50` or `-0.5`, within float range | Float. |
| Quoted text | String. |
| Other text | String. |

Leading-zero identifiers such as `01234`, overflowing numbers, exponent notation such as `1e5`, and incomplete decimals such as `5.` and `.5` remain strings. Standalone terms always keep their text. Wildcards have their own nodes.

## Choose a type

Typed fields cast raw text even when it is quoted:

| Helper | Behavior |
| --- | --- |
| `string($name, $internalName = null)` | Keeps text, including numeric and boolean-looking values. |
| `integer($name, $internalName = null)` | Accepts whole integers without leading zeros, within PHP's range. |
| `numeric($name, $internalName = null)` | Produces an integer when possible, otherwise a finite float; accepts exponents. |
| `decimal($name, $min, $max = null, $internalName = null)` | Keeps decimal text with exactly `$min` places, or `$min` through `$max`. |
| `boolean($name, $internalName = null)` | Maps `true`/`1` and `false`/`0` to booleans, ignoring case. |
| `in($name, $values, $internalName = null)` | Returns an allowed scalar with its configured type. |
| `date($name, $format = null, $internalName = null)` | Validates date text and keeps it as a string. |
| `enum($name, $enum, $internalName = null)` | Resolves a backed enum's value or case name to its backing value. |
| `uuid($name, $internalName = null)` | Validates a UUID and normalizes it to lowercase. |
| `ulid($name, $internalName = null)` | Validates a ULID and normalizes it to uppercase. |

`in()` matches strings ignoring case and returns the configured spelling. Duplicate strings ignoring case are configuration errors. Numeric and boolean members follow their type's matching rules: `in('code', ['01', '02'])` preserves `01`, while `in('level', [1, 2])` casts `2` to an integer.

```php
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;

$parser = SearchParser::query('price:"10.50"')->allowedFields([
    AllowedField::decimal('price', 2)->max(100),
]);

$value = $parser->parse()->getValue();
```

`$value` is the string `10.50`. Invalid typed values fail validation instead of being silently coerced.

## Add value rules

Chain `min()`, `max()`, `between()`, and `size()` to constrain a value. Numbers and numeric strings use their numeric value; other strings use their Unicode character length; arrays use their item count. A `string()` field always measures length, including numeric-looking strings. Booleans fail size rules.

Use `callback()` for application validation:

```php
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;

$rating = AllowedField::callback(
    'rating',
    fn (mixed $value, string $attribute): bool => is_int($value) && $value >= 1 && $value <= 5,
);
```

The callback receives the cast value and the internal field name. Callback exceptions propagate.

`nullable()` declares that the stored value may be missing and makes `validate(null)` pass. Adapters use it to include missing values in [complements](query-syntax#include-missing-values-in-complements). The query language has no null literal; use `NOT field:*` or a [null default](defaults).

## Map field names

Every factory accepts an internal name. Array output uses that name; the syntax tree and string output keep the external name:

```php
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;

$parser = SearchParser::query('age:10')->allowedFields([
    AllowedField::integer('age', 'user_age'),
]);

$filters = $parser->build();
$text = $parser->build('string');
```

The array field is `user_age`, and the text is `age:10`.

## Allow dynamic keys

A name ending in `.*` declares a pattern. Configure `keys()` with a regular expression or a callback that returns true for accepted suffixes:

```php
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;

$metadata = AllowedField::string('meta.*', 'attributes.*')
    ->keys('/^[a-z_]+$/D')
    ->prefix();

$parser = SearchParser::query('meta.color:blue')->allowedFields([
    $metadata,
    AllowedField::integer('meta.count', 'item_count'),
]);

$filters = $parser->build();
```

The output field is `attributes.color` with prefix matching. The syntax tree keeps `meta.color`. Exact names win over patterns; otherwise the longest matching prefix wins. A rejected key does not fall through to another pattern or `allowAnyField()`.

The key is the full suffix, including nested dots. It must be non-empty and contain no `*`. `meta.:x` and `meta.*:x` are therefore rejected even with `allowAnyField()`. The selected pattern's aliases, types, validators, match modes, and storage conversion apply to every accepted key.

A pattern needs a key rule when passed to `allowedFields()`. Invalid regular expressions, key rules on exact fields, and internal pattern names without `.*` are configuration errors. Patterns cannot have defaults; declare an exact field for that key.

## Resolve aliases

Use `alias()` for an exact, case-sensitive token. It resolves before casting and can return a scalar or call a closure at parse time:

```php
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;

$userId = 42;
$owner = AllowedField::integer('owner')->alias('@me', fn (): int => $userId);
$node = SearchParser::query('owner:@me')->allowedFields([$owner])->parse();
```

The value is the integer `42`. Alias results bypass casting and still pass validation. Closures must return a boolean, float, integer, or string; other results throw `InvalidArgumentException`.

Aliases apply to comparisons, list items, range bounds, and string defaults. Quoted values and open `*` bounds do not resolve aliases. A closure runs each time the query is parsed.

## Ignore values

`ignore(...$values)` removes matching values after aliases and casting, before validation and storage conversion. Strings compare ignoring case with `mb_strtolower()`; other values compare strictly.

```php
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;

$text = SearchParser::query('title:coffee status:ALL')->allowedFields([
    'title',
    AllowedField::in('status', ['active', 'draft'])->ignore('all'),
])->build('string');
```

The result is `title:coffee`. Removed clauses still suppress their field's default and still count toward query limits. Operator and clause restrictions run before ignoring values.

Comparisons disappear when ignored. Lists drop matching literal items and remain lists, even with one survivor; empty lists disappear. Ignored range bounds become open, and a range disappears when both bounds were ignored. Existing open bounds do not count as ignored values.

Wildcard patterns, existence checks, and bare terms are unaffected. A boolean group with one removed side becomes its surviving side, and NOT over a removed clause disappears. Surviving nodes keep their spans. If no filters or defaults remain, `parse()` and array or custom `build()` return null.

## Text match modes

Text fields match exactly by default. Call `prefix()`, `contains()`, or `words()` to configure prefix, substring, or whole-word matching. The last call wins:

```php
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;

$title = AllowedField::string('title')->prefix();
$parser = SearchParser::query('title:mug,"cup"')->allowedFields([$title]);
$filters = $parser->build();
```

The match modes are `['prefix', 'exact']`, and the quote flags are `[false, true]`. Quoting forces exact match metadata without changing case sensitivity. The [clause compiler](../advanced-usage/compiling-clauses) treats quoted `words()` values as ordered phrases.

These modes need `string()` or an array field with shared `each()->string()` rules. Other types and non-string positional overrides are configuration errors. Configure `each()` before selecting a mode on an array field.

`getTextMatch()` returns the configured `TextMatch` enum. Pass a comparison, or a list and item index, to get its effective mode. Defaults are unquoted. Explicit wildcards and text ranges retain their own semantics.

## Restrict query forms

Use `operators()` to allow a subset of `=`, `!=`, `>`, `>=`, `<`, and `<=` for comparisons, lists, and patterns. Passing no arguments allows none; unknown operators are configuration errors.

```php
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;

$status = AllowedField::in('status', ['active', 'draft'])
    ->operators('=', '!=')
    ->withoutRanges()
    ->withoutWildcards()
    ->withoutExists();
```

Use `withoutLists()`, `withoutRanges()`, `withoutWildcards()`, and `withoutExists()` to reject those forms. Quoted literal stars are still allowed when patterns are disabled. Restrictions run before aliases, casting, and validation; defaults bypass them.

## Restrict wildcard patterns

Leading wildcards are rejected by default. `AllowedField::allowLeadingWildcards()` enables them for one field. The parser-level method applies to bare terms and unconfigured fields accepted by `allowAnyField()`; it does not override configured fields.

```php
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;

$name = AllowedField::string('name')->allowLeadingWildcards()->minWildcardLength(3);
```

This accepts `name:*son*` and `name:s*on`. The minimum counts literal Unicode characters across all segments, excluding wildcard operators. Quoted literal stars count as text. Zero removes the minimum; a negative number is a configuration error. Ordinary values and `field:*` existence checks are unaffected.

A `words()` field accepts only trailing prefixes, such as `coff*` or `"coffee ma"*`. Leading and infix patterns fail with `wildcard.word_not_allowed`. Combining `words()` with `allowLeadingWildcards()` is a configuration error when the field is registered.

Field violations use `InvalidFieldOperatorException` and identify the offending item. Free-text leading wildcards use `ParseException`. See [error handling](error-handling).

## Convert storage values

Use `scale()` to multiply integer, numeric, or decimal values with exact decimal arithmetic:

```php
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;

$price = AllowedField::decimal('price', 2)->max(20)->scale(100);
$parser = SearchParser::query('price:10.50')->allowedFields([$price]);

$value = $parser->parse()->getValue();
$stored = $parser->build()['value'];
```

The AST value is `'10.50'`; the stored value is `1050`. Integral results use integers when they fit in PHP's range. Other results use decimal strings without trailing zeros. Floats are converted from their shortest round-trip representation. The factor must be an integer or valid numeric string.

Use `map()` to translate cast values to stored scalars:

```php
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;

$priority = AllowedField::in('priority', ['low', 'normal', 'high'])
    ->map(['low' => 1, 'normal' => 2, 'high' => 3]);
```

String keys match ignoring case. Boolean keys use `0` and `1`; fractional keys use strings such as `'1.5'`. Decimal keys match by numeric value, so `7.00` matches `7`. Colliding keys are configuration errors. Unmapped values pass through, except decimal text loses trailing zeros.

If a map stores any string, every converted value is returned as text. Numbers use decimal digits and booleans use `1` or `0`. `storesText()` reports this behavior. Each field accepts one storage conversion.

The array adapter calls `toStorage()` for comparison values, list items, and non-null range bounds. The AST and string output keep user-facing values. Aliases, ignored values, validation, and size checks run before storage conversion.

Array fields use the written item position for conversion; see [array fields](array-fields#preserve-written-positions). Configure shared numeric item rules before `scale()`. Non-numeric positional overrides keep their values.

Storage conversions reject explicit wildcards because a pattern cannot be converted. Existence checks and quoted literal stars remain allowed. Use order-preserving conversions for ordering comparisons and ranges.

## Validate dates

Without a custom format, `date()` accepts `YYYY-MM-DD`, ISO datetimes, and the relative expressions below. Datetimes use `YYYY-MM-DDTHH[:MM[:SS[.fraction]]][Z|±HH[:MM]]`. Calendar, time, and offset components must be valid; `T` and `Z` are uppercase.

```php
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;

$created = AllowedField::date('created');
$birthday = AllowedField::date('birthday', 'd/m/Y');
```

A custom format must parse without warnings or errors and format back to exactly the input. Dates stay strings in the AST and both built-in adapters. Size rules keep their ordinary behavior; they do not compare calendar dates.

### Allow partial dates

`date()->allowPartialDates()` also accepts `YYYY` and `YYYY-MM`. It is available on fields and item rules. Without it, partial dates fail validation. It cannot be used with another type or a custom date format.

### Accept calendar dates only

`date()->withoutTime()` accepts full dates and day anchors such as `today-7d`. Combine it with `allowPartialDates()` to accept years and months. Times, `now`, and bare offsets such as `-7d` are rejected; write `today-7d` instead. The method also works on item rules and cannot be combined with a custom format.

### Use relative dates

Format-less date fields accept these expressions:

| Expression | Meaning |
| --- | --- |
| `now` | The request's instant. |
| `today`, `yesterday`, `tomorrow` | A whole local day. |
| `now-7d`, `now+2h`, `now+1M` | An anchor with one offset. |
| `-7d`, `+3d` | An offset from `now`. |
| `today-7d`, `tomorrow+1w` | A day anchor with a calendar offset. |

Anchors ignore case. Units are case-sensitive: `y` years, `M` months, `w` weeks, `d` days, `h` hours, and `m` minutes. Day anchors accept only calendar units. An offset is a sign followed by up to six integer digits and a unit, with no spaces, decimals, or chained offsets.

Relative values work in comparisons, lists, ranges, and string defaults. The string adapter preserves their spelling. Adapters resolve them for each request.

### Resolve date intervals

Use `DateValue::parse()` to resolve format-less dates. Supply a timezone and capture one `$now` for the request:

```php
use HosmelQ\SearchSyntaxParser\Date\DateValue;

$zone = new DateTimeZone('UTC');
$now = new DateTimeImmutable('2024-03-15T12:00:00Z');
$value = DateValue::parse('2024-03-15T10:30', $zone, $now);
$bounds = $value->bounds('>');
```

The value spans 10:30:00 inclusive to 10:31:00 exclusive. The greater-than bounds start at 10:31:00 and have no upper limit.

`start()` is inclusive and `end()` exclusive. `precision()` returns `Year`, `Month`, `Day`, `Hour`, `Minute`, `Second`, `Fraction`, or `Instant`. `isRelative()` identifies relative input, and `hasExplicitOffset()` identifies a written timezone offset.

Absolute values span their written precision: a year covers that year, and a minute covers that minute. Fractional seconds span their written digits; PHP truncates more than six digits to microseconds. Relative instants span one microsecond.

`bounds($operator = '=')` returns `DateBounds`. Apply `stored >= from` and `stored < to` for each non-null bound:

| Operator | Inclusive `from` | Exclusive `to` |
| --- | --- | --- |
| `=` | Start | End |
| `>` | End | Null |
| `>=` | Start | Null |
| `<` | Null | Start |
| `<=` | Null | End |

Use `DateBounds::range()` for the language's inclusive written ranges:

```php
use HosmelQ\SearchSyntaxParser\Date\DateBounds;
use HosmelQ\SearchSyntaxParser\Date\DateValue;

$zone = new DateTimeZone('UTC');
$bounds = DateBounds::range(
    DateValue::parse('2024-01', $zone),
    DateValue::parse('2024-06', $zone),
);
```

These bounds cover January 1 inclusive through July 1 exclusive. Pass null for an open end and reuse one request time for relative bounds.

`!=` is the complement of an interval and cannot be represented by one `DateBounds`; `bounds('!=')` throws `InvalidArgumentException`. Adapters negate equality intervals and include missing values for nullable fields. Lists combine equality intervals with OR.

Invalid expressions throw `InvalidArgumentException`. Field validation still decides whether partial dates are allowed. Custom-format dates need an adapter-specific conversion.

### Resolve time zones

Text without an offset uses the supplied zone. A written `Z` or numeric offset takes precedence. Relative values use `$now` converted to the zone, or the current time if `$now` is omitted.

Calendar offsets preserve local wall-clock time. Month and year offsets clamp to the last day of the target month. Hours and minutes are elapsed durations: `now-168h` is always 168 hours earlier, while `now-7d` crosses daylight-saving changes in local time.

Days, months, and years run between local boundaries, so a day can last 23 or 25 hours. Repeated boundaries use their first occurrence; skipped midnight starts at the first instant after the gap. A skipped hour, minute, second, or fraction is an empty interval. A repeated one refers to its first occurrence.

```php
use HosmelQ\SearchSyntaxParser\Date\DateValue;

$day = DateValue::parse('2024-09-08', new DateTimeZone('America/Santiago'));
```

This day begins at 01:00 and ends at 00:00 the next day, lasting 23 hours. Relative values must resolve within years 0001 through 9999. Field validation checks this in UTC at request time; the resolver checks in the supplied zone.

## Use backed enums

`enum()` accepts backing values and case names ignoring case, and returns the backing value:

```php
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;

enum Status: string
{
    case Draft = 'pending';
    case Published = 'live';
}

$value = SearchParser::query('status:DRAFT')->allowedFields([
    AllowedField::enum('status', Status::class),
])->parse()->getValue();
```

The value is `pending`. If a token matches one case's backing value and another case's name, the backing value wins. Backing values and case names must each be unique ignoring case. Unit enums and classes that are not backed enums are rejected.

## Validate identifiers

`uuid()` accepts the 8-4-4-4-12 hexadecimal form, including nil and max values, without requiring a particular version or variant. `ulid()` accepts 26 Crockford base32 characters within 128 bits: the first character is `0` through `7`, and `I`, `L`, `O`, and `U` are excluded.

Both accept either letter case, require strings, and work in `each()` and `at()` rules. UUIDs normalize to lowercase and ULIDs to uppercase.

## Probe a term value

Adapters can call `tryCast($value, quoted: false, index: 0)` to resolve aliases, cast, and validate a candidate. Invalid input returns null. Valid `false`, `0`, and empty strings remain distinct from failure:

```php
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;

$id = AllowedField::integer('id')->min(1);
$value = $id->tryCast('1042');
```

The result is `1042`; `tryCast('coffee')` returns null. Array fields validate one item at the supplied position, including collection rules. Alias and validator exceptions propagate. This method does not apply ignored values or operator restrictions; the [clause compiler](../advanced-usage/compiling-clauses#compile-free-text-terms) handles term eligibility.

`isText()` identifies a scalar string field. `comparesText($value)` also requires a stored string value. A numeric field mapped to strings still uses typed equality rather than substring matching; `storesText()` describes storage separately.

## Extend field definitions

Factories use late static binding and fluent methods return `static`, so subclasses can add methods and retain chaining. The constructor is final; add custom configuration through methods.

For collections and tuples, continue with [array fields](array-fields). Query failures are described in [error handling](error-handling).
