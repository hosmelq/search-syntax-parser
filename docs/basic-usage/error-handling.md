---
title: "Error handling"
description: "Catch parser and field errors through one exception base and locate failures with Unicode character offsets in the original query."
weight: 5
---

Catch `SearchSyntaxException` to handle all query errors. It extends `RuntimeException` and exposes `getPosition()`, `getReason()`, and `getParameters()`. `ParseException`, `InvalidFieldException`, `InvalidFieldValueException`, and `InvalidFieldOperatorException` extend this common base; the field exceptions also expose `getField()` using the external query name.

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
| Disallowed operator or clause | Explicit comparison operator token, or the value start when no explicit operator exists. |
| Invalid field value | First value token; the first list item for lists, or `[` for ranges. |
| Invalid UTF-8 or a PCRE failure | Null because no position applies. |

Empty input, or input whose constraints are all ignored, returns `null` when no default applies. It is not a query error.

Exception messages remain descriptive and can be displayed alongside a highlighted query location.

## Error reasons and parameters

`getReason()` returns the backed `ErrorReason` enum; its snake_case `value` is suitable for application error codes. `getParameters()` returns an array keyed by strings with null, scalar, or string-list values, so applications can translate messages without parsing their English text.

`field_not_allowed` includes an empty `allowed` list when no fields are configured; its message then says "No fields are allowed."

| Reason value | Parameters |
| --- | --- |
| `unexpected_token` | `token` |
| `expected_token` | `expected`, `actual` token names |
| `expected_value` | None |
| `unexpected_end_of_input` | None |
| `unterminated_quote` | None |
| `invalid_utf8` | None |
| `tokenization_failed` | None |
| `invalid_wildcard_usage` | `field` |
| `invalid_wildcard_pattern` | None |
| `maximum_length`, `maximum_depth`, `maximum_clauses` | `limit` |
| `field_not_allowed` | `field`, `allowed` field-name list |
| `invalid_field_value` | `field` |
| `operator_not_allowed` | `field` and either `operator` or `clause` |
| `unsupported` | Defined by a third-party adapter |

```php
use HosmelQ\SearchSyntaxParser\Exception\ErrorReason;
use HosmelQ\SearchSyntaxParser\Exception\SearchSyntaxException;
use HosmelQ\SearchSyntaxParser\SearchParser;

try {
    SearchParser::query('coffee )')->parse();
} catch (SearchSyntaxException $exception) {
    assert($exception->getReason() === ErrorReason::UnexpectedToken);
    assert($exception->getParameters() === ['token' => ')']);
}
```

## Programmer errors

Unknown adapters, non-positive limits, defaults that have an invalid shape or fail the field's rules when applied, unknown configured operators, nonscalar alias closure results, and non-backed enum rules throw `InvalidArgumentException`. So do `in()` strings, enum values or case names, and `map()` keys that collide ignoring case; a second storage conversion; an invalid `scale()` factor or a non-numeric scaled field; and a text match mode on a field whose values are not text. They indicate invalid application configuration rather than a user's query error. A string adapter asked to serialize an unrepresentable wildcard throws `LogicException`.

Read [query limits and untrusted input](../advanced-usage/query-limits-and-untrusted-input) for limits that bound parser work.
