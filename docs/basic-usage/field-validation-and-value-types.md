---
title: "Field validation and value types"
description: "Restrict searchable fields and control scalar casting with string, numeric, enum, UUID, ULID, matching, and storage conversion rules."
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

## Dynamic field keys

A factory name ending in `.*` declares a pattern field: `AllowedField::string('meta.*')` matches `meta.<key>` for a non-empty key without `*`. Configure `keys(string|Closure $rule)` with a regular expression or `fn (string $key): bool`; the callback receives the full suffix after `meta.`, including any nested dots, and only a `true` result accepts the key. A pattern without `keys()` throws `InvalidArgumentException` when passed to `allowedFields()`. Invalid regexes and key rules on exact fields are configuration errors too.

Lookup checks exact names first, then the longest matching pattern, regardless of registration order. The selected pattern's key rule must pass; a rejected key does not fall through to a shorter pattern or `allowAnyField()`. Every name with the pattern's prefix resolves to the pattern, so `meta.:x` and `meta.*:x` are rejected even when `allowAnyField()` is on. It throws `InvalidFieldException` at the field's position with reason `field_not_allowed`. Allowed-field lists display the configured patterns, such as `meta.*`.

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

assert($parser->parse()->getField() === 'meta.color');
assert($parser->build()['field'] === 'attributes.color');
assert($parser->build()['match'] === 'prefix');
assert($parser->getAllowedField('meta.color') === $metadata);
assert(SearchParser::query('meta.count:12')->allowedFields([
    $metadata,
    AllowedField::integer('meta.count', 'item_count'),
])->build()['value'] === 12);
```

Pattern fields apply their usual aliases, casts, validation, match modes, storage conversions, and clause restrictions to every accepted key. The AST and string adapter keep the full external name. Internal pattern names must also end in `.*`: `meta.color` becomes `attributes.color` in the array adapter above. Omitting an internal name retains `meta.color`. Pattern fields cannot have defaults, including null defaults; `default()` throws `InvalidArgumentException` immediately. Configure an exact field when a particular key needs a default.

```php
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;

assert(SearchParser::query('metrics.views:2')->allowedFields([
    AllowedField::integer('metrics.*')
        ->keys(fn (string $key): bool => in_array($key, ['views', 'likes'], true))
        ->scale(100),
])->build()['value'] === 200);
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
| `enum($name, $enum, $internalName = null)` | Accepts a backing value or case name, case-insensitively, and returns the backing value. |
| `uuid($name, $internalName = null)` | Accepts the 8-4-4-4-12 hexadecimal UUID form and normalizes to lowercase. |
| `ulid($name, $internalName = null)` | Accepts a 26-character Crockford base32 ULID within 128 bits and normalizes to uppercase. |

`in()` matches string members case-insensitively with `mb_strtolower()` and returns the configured canonical value: `status:OPEN` with `in('status', ['open'])` produces `'open'`. Two configured strings equal ignoring case throw `InvalidArgumentException`, including duplicate identical strings. Non-string members retain their text or inferred-number matching, and boolean members match like `boolean()`. For example, `in('code', ['01', '02'])` preserves `01`, and `in('level', [1, 2])` casts `2` to an integer. Invalid typed values fail validation rather than being silently coerced.

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

Without a format, `date()` accepts `YYYY-MM-DD` and `YYYY-MM-DDTHH[:MM[:SS[.fraction]]][Z|±HH[:MM]]`, as well as the relative expressions below. Dates, hours, minutes, seconds, and timezone components must be valid: `2025-02-30`, `25:00`, and minute `60` are rejected. The `T` and `Z` markers are uppercase. The AST and both built-in adapters keep date text as written.

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

### Partial dates

Call `AllowedField::date('created')->allowPartialDates()` to also accept `YYYY` for a whole year and `YYYY-MM` for a whole month. Without this opt-in, partial dates fail validation. The same `allowPartialDates()` method is available after `date()` in `each()` and `at()` item rules. Configuring partial dates on another type or a date with a custom format throws `InvalidArgumentException`.

```php
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;

$fields = [AllowedField::date('created')->allowPartialDates()];
$range = SearchParser::query('created:[2023 TO 2024-06]')->allowedFields($fields)->parse();

assert($range->getFrom() === '2023');
assert($range->getTo() === '2024-06');
assert(SearchParser::query('created:2024')->allowedFields($fields)->parse()->getValue() === '2024');
```

### Relative dates

Every format-less date field accepts these expressions without an additional opt-in:

- `now` is an instant. `today`, `yesterday`, and `tomorrow` represent whole days. Anchors ignore case, so `TODAY` and `NoW` are valid.
- An anchor may have one offset: `[+-]Nunit`, where `N` is a non-negative integer of at most six digits. Units are case-sensitive: `y` years, `M` months, `w` weeks, `d` days, `h` hours, and `m` minutes. `now-7d`, `now+2h`, and `now+1M` are valid; `now-1D` and `now+1s` are not.
- A bare offset, such as `-7d` or `+3d`, uses `now` as its anchor.
- Day anchors accept only `y`, `M`, `w`, and `d`, and keep whole-day precision. Examples include `today-7d` and `tomorrow+1w`; `today+1h` fails validation.

Offsets contain no spaces or decimal amounts, and cannot be chained. Relative values work in comparisons (`created:>-7d`, `due:<today`, `created:<=now-1h`), lists (`created:-7d,today,tomorrow+1w`), and either range bound (`created:[-30d TO now]`). A leading minus must touch the value. `StringAdapter` preserves the expression's spelling and case. String defaults such as `AllowedField::date('due')->default('today')` remain text and are resolved by the adapter for each request.

### Resolving date intervals

Use `HosmelQ\SearchSyntaxParser\Date\DateValue::parse(string $text, DateTimeZone $zone, null|DateTimeImmutable $now = null): self` in an adapter to resolve format-less date text. It accepts full, partial, and relative dates; invalid expressions throw `InvalidArgumentException`. Field validation still controls whether a query may contain a partial date. Custom-format fields need their own conversion.

`start()` is inclusive and `end()` is exclusive. `precision()` returns a `DatePrecision` case: `Year`, `Month`, `Day`, `Hour`, `Minute`, `Second`, `Fraction`, or `Instant`. `isRelative()` identifies relative text, and `hasExplicitOffset()` identifies a written `Z` or numeric offset. An absolute value spans its written precision: `2024` covers `[2024-01-01, 2025-01-01)`, and `2024-03-15T10:30` covers one minute. Fractional seconds use `Fraction` precision at the written number of digits: `.1` covers a tenth of a second and `.123` a millisecond. PHP represents at most six fractional digits, so longer fractions are truncated to microseconds. Relative instants, including `now-7d`, span one microsecond.

```php
use HosmelQ\SearchSyntaxParser\Date\DatePrecision;
use HosmelQ\SearchSyntaxParser\Date\DateValue;

$zone = new DateTimeZone('America/New_York');
$value = DateValue::parse('2024-03-15T10:30', $zone);

assert($value->start()->format('Y-m-d H:i:sP') === '2024-03-15 10:30:00-04:00');
assert($value->end()->format('H:i:s') === '10:31:00');
assert($value->precision() === DatePrecision::Minute);
assert(!$value->isRelative());
assert(!$value->hasExplicitOffset());

$bounds = $value->bounds('>');
assert($bounds->from == $value->end());
assert($bounds->to === null);
```

`bounds($operator = '=')` returns a readonly `DateBounds` with nullable `from` and `to` properties. Apply `stored >= from` and `stored < to` for non-null bounds. With `S = start()` and `E = end()`:

| Query | Inclusive `from` | Exclusive `to` |
| --- | --- | --- |
| `= value` | S | E |
| `> value` | E | null |
| `>= value` | S | null |
| `< value` | null | S |
| `<= value` | null | E |
| `[a TO b]` or `a..b` | S(a), or null for `*` | E(b), or null for `*` |

`DateBounds::range(null|DateValue $from, null|DateValue $to)` converts the language's inclusive written range into half-open bounds. Resolve both ends with the same request time:

```php
use HosmelQ\SearchSyntaxParser\Date\DateBounds;
use HosmelQ\SearchSyntaxParser\Date\DateValue;

$zone = new DateTimeZone('UTC');
$now = new DateTimeImmutable('2024-03-15T12:00:00Z');
$bounds = DateBounds::range(
    DateValue::parse('-30d', $zone, $now),
    DateValue::parse('now', $zone, $now),
);

assert($bounds->from->format('Y-m-d H:i:s') === '2024-02-14 12:00:00');
assert($bounds->to->format('Y-m-d H:i:s.u') === '2024-03-15 12:00:00.000001');
assert(DateBounds::range(null, DateValue::parse('2024-06', $zone))->to->format('Y-m-d') === '2024-07-01');
```

`!=` is the set complement of `=` and cannot be represented by one interval. Adapters negate the equality interval and include missing values for `nullable()` fields. Calling `bounds('!=')` throws `InvalidArgumentException`. Lists combine their equality intervals with OR; not-equal lists negate that union.

### Time zones and daylight saving time

Text without an offset is interpreted in `$zone`. A written `Z` or numeric offset identifies an exact fixed offset and takes precedence over `$zone`. Relative values use `$now` converted to `$zone`, or the current time when `$now` is omitted. Capture one `DateTimeImmutable` per request and reuse it for every relative value, including defaults and range ends.

Calendar offsets (`y`, `M`, `w`, `d`) keep the local wall-clock time, and month and year offsets clamp to the last day of the target month: on March 31, `today-1M` is the last day of February, and `today+1y` from February 29 is February 28. So `now-7d` is the same local time seven days earlier, which is 167 or 169 elapsed hours across a DST change. Hours and minutes are elapsed durations: `now-168h` is always 168 hours earlier. A relative value must resolve within the years 0001 to 9999: field validation rejects one that does not, checking in UTC at the time of the request, and `DateValue::parse()` throws `InvalidArgumentException` for it in the zone it resolves.

Every start is the earliest instant whose local time is the written wall-clock time. Days, months, and years run from one local midnight to the next, so a DST day can last 23 or 25 hours. A repeated midnight starts the day at its first occurrence, as on 2021-10-29 in `Asia/Amman`; a skipped midnight starts it at the first instant after the gap, so in `America/Santiago` 2024-09-08 begins at 01:00 and ends at 00:00 the next day. An hour, minute, second, or fraction skipped by a DST change is an empty interval, and a repeated one refers to its first occurrence: in `America/New_York`, `2024-11-03T01` covers 01:00 to 02:00 EDT only. Both absolute days and relative day anchors use these boundaries.

```php
use HosmelQ\SearchSyntaxParser\Date\DateValue;

$day = DateValue::parse('2024-09-08', new DateTimeZone('America/Santiago'));

assert($day->start()->format('Y-m-d H:i:sP') === '2024-09-08 01:00:00-03:00');
assert($day->end()->format('Y-m-d H:i:sP') === '2024-09-09 00:00:00-03:00');
assert($day->end()->getTimestamp() - $day->start()->getTimestamp() === 23 * 3600);
```

Adapters resolve the AST's date strings at request time, translate the bounds into their backend's comparisons, and implement complements and missing-value semantics. The resolver supplies dates and metadata without a framework or clock dependency; neither built-in adapter resolves relative dates automatically.

## Backed enums

`enum()` accepts backing values and case names, case-insensitively. The AST always contains the backing value. For `case Active = 1`, `status:1`, `status:Active`, and `status:active` all produce the integer `1`. If a token matches one case's backing value and another case's name, the backing value wins. Backing values must be unique ignoring case, and case names must separately be unique ignoring case; collisions within either set throw `InvalidArgumentException` when configured. A unit enum or a class that is not a backed enum also throws `InvalidArgumentException`.

```php
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;

enum Status: string
{
    case Draft = 'pending';
    case Published = 'live';
}

assert(SearchParser::query('status:DRAFT')->allowedFields([
    AllowedField::enum('status', Status::class),
])->build()['value'] === 'pending');
```

## UUIDs and ULIDs

`uuid()` accepts any version in the 8-4-4-4-12 hexadecimal form, including nil and max UUIDs. It does not require a particular version or variant. `ulid()` requires 26 Crockford base32 characters (`0`–`9`, `A`–`Z` excluding `I`, `L`, `O`, and `U`), with the first character between `0` and `7`. Both accept either letter case, require string values, and support `each()` and `at()` item rules.

```php
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;

assert(SearchParser::query('id:550E8400-E29B-41D4-A716-446655440000')->allowedFields([
    AllowedField::uuid('id'),
])->parse()->getValue() === '550e8400-e29b-41d4-a716-446655440000');

assert(AllowedField::ulid('id')->tryCast('01arz3ndektsv4rrffq69g5fav') === '01ARZ3NDEKTSV4RRFFQ69G5FAV');
assert(AllowedField::ulid('id')->tryCast('8ZZZZZZZZZZZZZZZZZZZZZZZZZ') === null);
```

## Text match modes

Text fields match exactly by default. `prefix()`, `contains()`, and `words()` configure prefix, substring, and whole-word matching as metadata for adapters; only the last call wins. These methods require `string()`, or an `array()` field with a shared `each()->string()` type. Other cast types, untyped collections, and positional overrides with a non-string type throw `InvalidArgumentException` when configured. On `array()` fields, call these methods and `scale()` after `each()`, because they check the shared item type when called.

`TextMatch` is a string-backed enum with `Exact`, `Prefix`, `Contains`, and `Words` cases. `AllowedField::getTextMatch(null|ComparisonNode|InNode $node = null, int $index = 0): TextMatch` returns the configured mode without a node, or the effective mode for a comparison or one list item. Quoted values force `Exact`; defaults are unquoted. Quoting changes the matching mode, not case sensitivity. Explicit wildcard patterns remain `WildcardNode` instances and are independent of these modes. Text ranges retain their range semantics.

```php
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;
use HosmelQ\SearchSyntaxParser\Validation\TextMatch;

$title = AllowedField::string('title')->contains()->prefix();
$parser = SearchParser::query('title:mug,"cup"')->allowedFields([$title]);
$node = $parser->parse();

assert($title->getTextMatch($node, 0) === TextMatch::Prefix);
assert($title->getTextMatch($node, 1) === TextMatch::Exact);
assert($parser->build()['match'] === ['prefix', 'exact']);
assert($parser->build()['quoted'] === [false, true]);
```

## Storage conversions

`scale(int|string $factor)` multiplies integer, numeric, and decimal field values, or array items with one of those shared types, using `brick/math`. Floats are read from their shortest round-trip text, so no precision is lost. An integral result becomes a PHP integer, or a decimal string when it does not fit in one; a fractional result becomes a decimal string with trailing zeros removed. Decimal fields preserve the user's decimal text until this conversion, so `10.50` scaled by `100` becomes the integer `1050`. An invalid factor or a non-numeric field throws `InvalidArgumentException` when configured. Numeric fields reject non-finite values such as `1e400`.

`map(array $map)` translates cast values to stored scalars, and unmapped values pass through. String keys match ignoring case, like every other value comparison, so `map(['open' => 1])` converts `OPEN` too; keys that collide ignoring case throw `InvalidArgumentException`. Boolean keys use `0` and `1`; fractional numeric keys use strings such as `'1.5'`. A field accepts one storage conversion: calling `scale()` or `map()` a second time throws `InvalidArgumentException`.

`AllowedField::toStorage(bool|float|int|string $value): bool|float|int|string` applies the conversion to an already cast and validated value. The built-in array adapter calls it for comparisons, every list item, and non-null range bounds, including default values. Custom adapters must call it themselves. The AST retains user-facing cast values, and the string adapter never applies storage conversions. Aliases, `ignore()`, validation, and size rules run before conversion.

```php
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;

$price = AllowedField::decimal('price', 2)->max(20)->scale(100);
$parser = SearchParser::query('price:10.50')->allowedFields([$price]);

assert($parser->parse()->getValue() === '10.50');
assert($parser->build()['value'] === 1050);
assert($price->toStorage('10.50') === 1050);

$priority = AllowedField::in('priority', ['low', 'normal', 'high'])
    ->map(['low' => 1, 'normal' => 2, 'high' => 3]);
$result = SearchParser::query('priority:>NORMAL')->allowedFields([$priority])->build();

assert($result['operator'] === '>');
assert($result['value'] === 2);
```

Fields with a storage conversion reject explicit wildcard patterns with `InvalidFieldOperatorException`, exactly like `withoutWildcards()`, because a pattern cannot be converted. Existence checks and quoted literal asterisks remain allowed. Use order-preserving conversions when ordering comparisons or ranges must reflect user-facing order.

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

`ignore(...$values)` removes constraints whose values match an ignored value after alias resolution and casting. Strings compare case-insensitively using `mb_strtolower()` on both sides; other values compare strictly. Storage conversions have not run at this point. Removed values never reach validation. Operator and clause restrictions still run first.

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

All comparison operators and clause kinds are allowed by default, subject to the wildcard restrictions below. `operators()` restricts comparisons, lists, and wildcard patterns to a subset of `=`, `!=`, `>`, `>=`, `<`, and `<=`. Passing no operators permits none of those comparisons; an unknown operator throws `InvalidArgumentException`. Ranges and existence checks have their own controls. Use `withoutLists()`, `withoutRanges()`, `withoutWildcards()`, and `withoutExists()` to reject those forms. Literal asterisks inside quotes are allowed when wildcard patterns are disabled.

Restrictions run before aliases, casting, and value validation. A violation throws `InvalidFieldOperatorException` with the external field name and the explicit comparison operator's position, or the value start when no explicit operator exists. A range starts at `[` or at its first compact bound. Leading-wildcard and minimum-length errors point to the offending pattern item. Defaults bypass these restrictions, which govern what users type.

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

## Wildcard restrictions

Leading wildcard patterns are rejected by default. `allowLeadingWildcards()` opts in for one configured field; `SearchParser::allowLeadingWildcards()` covers free-text terms and fields accepted only through `allowAnyField()`. Configured fields keep their own policy even when the parser-level option is enabled. Trailing and infix wildcards do not require an opt-in.

`minWildcardLength(int $characters)` sets a non-negative minimum number of literal Unicode characters across a field pattern's segments. Wildcard operators do not count, including repeated `*` runs. Literal `*` characters inside a quoted prefix still count as literal text. A negative minimum throws `InvalidArgumentException`; zero removes the minimum. Ordinary comparisons, quoted literal asterisks, and `field:*` existence checks are unaffected.

```php
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;

$name = AllowedField::string('name')->allowLeadingWildcards()->minWildcardLength(3);

assert(SearchParser::query('name:*son*')->allowedFields([$name])->parse()->getSegments() === ['', 'son', '']);
assert(SearchParser::query('name:s*on')->allowedFields([$name])->parse()->getSegments() === ['s', 'on']);
assert(SearchParser::query('name:*')->allowedFields([$name])->parse()->getType() === 'Exists');
```

These checks run alongside `withoutWildcards()` before any alias, cast, or validator, including all items of a list. `withoutWildcards()` still rejects every pattern after an opt-in. Rejected field patterns throw `InvalidFieldOperatorException` with the typed external name, the pattern item's position, and reason `leading_wildcard_not_allowed` or `wildcard_too_short`. Free-text leading patterns throw `ParseException` with `leading_wildcard_not_allowed`.

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
