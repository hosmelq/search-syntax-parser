---
title: "Error handling"
description: "Catch parser and field errors through one exception base and locate failures with Unicode character offsets in the original query."
weight: 5
---

Catch `SearchSyntaxException` to handle all query errors. It extends `RuntimeException` and exposes `getPosition()`. `ParseException`, `InvalidFieldException`, and `InvalidFieldValueException` extend this common base; the field exceptions also expose `getField()` using the external query name.

```php
use HosmelQ\SearchSyntaxParser\Exception\SearchSyntaxException;
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;

try {
    SearchParser::query('  age:old')->allowedFields([
        AllowedField::integer('age', 'user_age'),
    ])->build();
} catch (SearchSyntaxException $exception) {
    assert($exception->getPosition() === 6);
    assert($exception->getField() === 'age');
}
```

## Positions

Positions are zero-based Unicode code point offsets in the exact original string, including leading whitespace. `query()` does not trim input. A position is a character offset, not a byte offset or a rendered grapheme index.

| Failure | Position |
| --- | --- |
| Unterminated quote | Opening quote. |
| Unexpected or missing token/value | Offending token, or the query's character length at end of input. |
| Invalid wildcard | Start of its token. |
| Maximum length | Configured character limit, the first character past the limit. |
| Maximum depth | The `(`, `NOT`, or `-` token that exceeds the limit. |
| Too many clauses | First token of the clause that exceeds the limit; an expanded list uses the item's token. |
| Field not allowed | Field token. |
| Invalid field value | First value token; the first list item for lists, or `[` for ranges. |
| Empty query, invalid UTF-8, or a PCRE failure | Null because no position applies. |

Exception messages remain descriptive and can be displayed alongside a highlighted query location.

## Programmer errors

Unknown adapters, non-positive limits, invalid defaults, and non-backed enum rules throw `InvalidArgumentException`. They indicate invalid application configuration rather than a user's query error. A string adapter asked to serialize an unrepresentable wildcard throws `LogicException`.

Read [query limits and untrusted input](../advanced-usage/query-limits-and-untrusted-input) for limits that bound parser work.
