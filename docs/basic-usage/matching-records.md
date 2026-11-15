---
title: "Matching Records"
description: "Filter records in memory with validated search clauses, stored values, and date intervals."
weight: 6
---

## Filter records

Construct a `Matcher` with a configured parser, then pass each record to `matches()`:

```php
use DateTimeImmutable;
use DateTimeZone;
use HosmelQ\SearchSyntaxParser\Query\Matcher;
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\Field;

$parser = SearchParser::query('coffee price:<10')->allowedFields([
    Field::string('title'),
    Field::string('sku'),
    Field::decimal('price', 0, 2)->scale(100),
]);

$matcher = new Matcher(
    $parser,
    terms: ['title', 'sku'],
    timezone: new DateTimeZone('UTC'),
    now: new DateTimeImmutable('2024-03-15T12:00:00Z'),
);

$records = [
    ['title' => 'Coffee mug', 'sku' => 'MUG-1', 'price' => 950],
    ['title' => 'Tea pot', 'sku' => 'POT-1', 'price' => 2500],
];

$matches = array_filter($records, $matcher->matches(...));
$matchesFirst = $matcher->matches($records[0]);
```

`$matches` contains the first record, and `$matchesFirst` is true. `array_filter()` preserves the original keys.

| Argument | Purpose |
| --- | --- |
| `parser` | The query and its field definitions. |
| `terms` | Exact public field names to search for bare terms; defaults to an empty list. |
| `timezone` | A `DateTimeZone` for query dates without an offset; defaults to `date_default_timezone_get()`. |
| `storageTimezone` | A `DateTimeZone` for stored datetime strings without an offset; defaults to the resolved `timezone`. |
| `now` | A `DateTimeInterface` used for relative query dates; defaults to the current time. |

The constructor parses once and prepares clauses, date intervals, patterns, and query words. Every record uses the same captured clock. Construct a new matcher after changing the parser or its fields. An empty filter tree matches every record. Field defaults participate as ordinary filters.

The constructor throws the parser's `SearchSyntaxException` subclasses for invalid queries. A relative date outside years 0001 through 9999 in the supplied zone raises `InvalidFieldValueException` at its clause. Invalid term configuration, or a custom date format on a field other than a directive, raises `InvalidArgumentException` even if the query does not use that field.

## Search bare terms

A term matches when any eligible term field matches. Text terms search for a substring, while `words()` fields use word matching. Other types cast and validate the term before comparing it. Bare wildcards use each field's wildcard rules.

A field participates only when its value rules, equality operator, and applicable wildcard rules accept the term. A term with no eligible field matches nothing; its negation matches everything.

Term names must identify exact configured fields. Unknown names, dynamic patterns, directives, and date fields or shared date items are configuration errors. `allowAnyField()` permits unconfigured field clauses, but does not make them term targets.

## Read stored values

Records may be arrays or objects, including `ArrayAccess` implementations. The matcher reads the internal field name. For dynamic fields, `meta.color` mapped through `meta.*` to `attributes.*` reads `attributes.color`.

For arrays and `ArrayAccess`, an existing key with the whole name takes precedence, even when its value is null. Otherwise the matcher walks dot-separated segments. Other objects expose only public properties. A missing segment reads as null.

When a segment meets a list or `Traversable`, the matcher reads it from each element, skips elements without a value, and flattens the results one level. A field mapped to `items.sku` reads every item's SKU; `orders.items.sku` also walks nested lists. Integer segments still select an element, as in `tags.0`.

Backed enums compare as their backing values, and `Stringable` objects as their strings. A `Traversable` that is also `Stringable` remains a collection. `DateTimeInterface` values retain their dates and time zones. Other objects do not satisfy scalar comparisons.

Only lists (`array_is_list()`) and `Traversable` values hold several alternatives. Any matching element satisfies the clause; range bounds must match the same element. Separate clauses can match different elements. An associative array is one value: it does not satisfy scalar comparisons, but it satisfies `field:*`. Nested collections do not satisfy scalar comparisons. Each `Traversable` is read once per `matches()` call, even when several paths pass through it. Single-pass iterables are consumed; use reusable iterables when matching the same record again.

Records contain stored values. Query values pass through `scale()` and `map()` before comparison, so a price scaled by 100 compares against stored cents. These conversions are not applied to records.

## Compare text and words

Text clauses ignore case with `mb_strtolower()`: comparisons on scalar `string()` fields, word matches, and wildcard patterns. Text fields also compare stored integers and finite floats as decimal strings. Ordering and ranges use the resulting Unicode code-point order, without locale collation.

`prefix()`, `contains()`, quoting, and explicit wildcards retain their clause semantics. Wildcard patterns match the whole value and can span newlines. Literal `%`, `_`, and `!` remain literal when escaped by the clause compiler.

For `words()`, punctuation separates tokens. Letters, numbers, and combining marks stay in words. Every query word must match a token. Quoted phrases require consecutive tokens in order, and a trailing wildcard permits the last word to match the start of a token. A query with no words matches nothing.

The matcher does not stem words or remove stop words. Database full-text search may stem or tokenize differently, so its results can differ from in-memory word matching.

## Compare numbers and booleans

Integer, numeric, and decimal fields or shared item types compare numerically, as do numeric clause values. Comparisons use exact decimal arithmetic. Stored integers, finite floats, and numeric strings are accepted; other types do not match. Floats use their shortest round-trip decimal representation, and numeric strings with an exponent compare as floats.

The matcher intentionally accepts numeric strings because database rows often return decimals as strings. Text fields also accept stored integers and floats as decimal strings. JSON targets in database adapters compare types strictly.

Numeric ranges include both bounds. A `*` end is open. A map that stores text compares the mapped strings instead.

Boolean values accept stored booleans, the integers `0` and `1`, and the strings `'0'` and `'1'`. Strings such as `'true'` are not stored booleans. Other strings compare exactly, including UUIDs, ULIDs, `in()` and enum values, text produced by `map()`, array items, and untyped values. The parser already normalizes UUIDs to lowercase, ULIDs to uppercase, and members to their configured spelling. An identifier such as `007` keeps its leading zeros.

## Resolve dates

Format-less date fields and shared date items use `DateValue` intervals. A date covers its written precision: a day covers the local day, and a month covers the whole month. Explicit offsets override the configured zone. Relative expressions use the captured clock.

```php
use DateTimeImmutable;
use DateTimeZone;
use HosmelQ\SearchSyntaxParser\Query\Matcher;
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\Field;

$parser = SearchParser::query('created:yesterday..today')->allowedFields([
    Field::date('created', internalName: 'created_at'),
]);

$matcher = new Matcher(
    $parser,
    timezone: new DateTimeZone('America/New_York'),
    storageTimezone: new DateTimeZone('UTC'),
    now: new DateTimeImmutable('2024-03-15T12:00:00Z'),
);

$matches = $matcher->matches(['created_at' => '2024-03-15 02:30:00']);
```

An interval includes its start and excludes its end. Equality uses one interval, lists combine intervals with OR, and ranges extend through the end of the written upper value. `>` begins at the value's end; `>=` begins at its start. `<` ends at its start; `<=` ends at its end. Empty intervals, including skipped local hours, match nothing.

Stored datetime values compare as instants. `DateTimeInterface` objects keep their own zone. Strings without an offset use `storageTimezone`; a written offset or `Z` takes precedence. With UTC storage and the query zone `America/New_York`, `created:2024-03-14` matches the stored string `2024-03-15 02:30:00` because that instant is still March 14 in New York.

Stored strings use the core's date grammar. They must contain a full calendar date and may add an ISO time, offset, and fractional seconds, such as `2024-03-15T10:30:00.000000Z`. A single space may replace `T`, as in SQL `Y-m-d H:i:s`. Empty strings, times without dates, relative expressions, partial years or months, impossible dates, and PHP modifiers do not match. Filter fields cannot use custom date formats, including formats in array item rules.

Fields and shared date items with `withoutTime()` compare calendar dates without zone conversion. A `DateTimeInterface` contributes its own `format('Y-m-d')`; a string contributes its written date. The matcher compares that date's local midnight in `timezone` with the query intervals. A stored `2024-03-14T00:00:00Z` therefore matches `due:2024-03-14` in New York.

## Include missing values in complements

AND, OR, and NOT use boolean logic. Negation is the exact complement of the positive clause, regardless of `nullable()`:

| Query | Matches |
| --- | --- |
| `quantity:10` | A scalar or element equal to 10. |
| `quantity:!=10` | No scalar or element equals 10, including missing, null, empty, or incomparable values. |
| `NOT quantity:>10` | No scalar or element is greater than 10, including missing values. |
| `quantity:*` | A non-null single value or a non-empty list or `Traversable`, regardless of element types. |
| `quantity:*..*` | The same presence check. |

For several stored values, a complement requires that no element match. A missing value satisfies negative clauses but never positive scalar comparisons.

A database column's complement includes NULL only when the field is `nullable()`; see [query syntax](query-syntax#include-missing-values-in-complements).

## Read directives and sorting

The matcher ignores directives and sorting. After construction, read `$parser->getDirectives()` and `$parser->getSort()` to apply those settings in your application. A query containing only settings matches every record.
