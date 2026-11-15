---
title: "Error Handling"
description: "Handle query failures with structured reasons and parameters, and locate invalid input using Unicode character positions."
weight: 6
---

Catch `SearchSyntaxException` to handle query errors. It exposes `getReason()`, `getParameters()`, and `getPosition()`:

```php
use HosmelQ\SearchSyntaxParser\Exception\SearchSyntaxException;
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;

try {
    SearchParser::query('  age:old')->allowedFields([
        AllowedField::integer('age', 'user_age'),
    ])->build();
} catch (SearchSyntaxException $exception) {
    $reason = $exception->getReason()->value;
    $parameters = $exception->getParameters();
    $position = $exception->getPosition();
}
```

The reason is `field.invalid_value`, the parameters contain `['field' => 'age']`, and the position is `6`.

`ParseException`, `InvalidFieldException`, `InvalidFieldValueException`, and `InvalidFieldOperatorException` extend this base. Field exceptions also expose `getField()` with the external query name. The base extends `RuntimeException`.

## Locate a failure

Positions are zero-based Unicode code point offsets in the exact input, including leading whitespace. They are neither byte offsets nor rendered grapheme indices. The parser does not trim the query.

| Failure | Position |
| --- | --- |
| Unterminated quote | Opening quote. |
| Unexpected or missing token | Offending token, or input length at the end. |
| Invalid wildcard | Offending pattern or bound; lists identify the item. |
| Maximum length | First character past the configured limit. |
| Maximum depth | Group or negation that exceeds the limit. |
| Maximum clauses | First token of the excess clause; expanded lists identify the item. |
| Disallowed field | Field token. |
| Disallowed operator or clause | Explicit operator, otherwise the value start. |
| Misplaced or duplicate directive | Field token. |
| Invalid directive shape | Operator or value start. |
| Invalid value | First value token; `[` for bracket ranges or the first compact bound. |
| Invalid UTF-8 or tokenization failure | Null. |

An invalid list value points to the first item. Leading-wildcard and minimum-length failures point to the offending pattern item. Invalid sort values point to the sort value.

An empty query, or a query whose filters are all ignored, is not an error. Without applicable defaults it returns a null tree.

## Translate errors

`getReason()` returns `ErrorReason`. Its backed value is a dotted code grouped by topic. `getParameters()` supplies null, scalar, or string-list values keyed by strings, so you can translate errors without parsing English messages.

| Reason | Parameters |
| --- | --- |
| `directive.duplicate` | `field` |
| `directive.invalid_clause` | `field` |
| `directive.not_top_level` | `field` |
| `field.invalid_value` | `field` |
| `field.not_allowed` | `field`, `allowed` |
| `field.operator_not_allowed` | `field`, and `operator` or `clause` |
| `query.invalid_utf8` | None. |
| `query.maximum_clauses` | `limit` |
| `query.maximum_depth` | `limit` |
| `query.maximum_length` | `limit` |
| `query.tokenization_failed` | None. |
| `syntax.expected_token` | `expected`, `actual` |
| `syntax.expected_value` | None. |
| `syntax.unexpected_end_of_input` | None. |
| `syntax.unexpected_token` | `token` |
| `syntax.unterminated_quote` | None. |
| `wildcard.invalid_pattern` | None. |
| `wildcard.invalid_usage` | `field` |
| `wildcard.leading_not_allowed` | `field` for a field pattern; none for a bare term. |
| `wildcard.too_short` | `field`, `minimum` |
| `wildcard.word_not_allowed` | `field` |

Rejected dynamic keys use `field.not_allowed` with the complete typed name and configured patterns in `allowed`. With no configured fields, `allowed` is empty and the message says "No fields are allowed."

Directive placement, duplicates, and shape errors use `InvalidFieldOperatorException`, including `sort:`. Unknown sort keys and directions use `InvalidFieldValueException` for `sort`. Free-text leading wildcard failures use `ParseException`; field wildcard failures use `InvalidFieldOperatorException`.

## Fix configuration errors

Invalid application configuration throws `InvalidArgumentException` without a query reason or position. Examples include:

- Unknown adapters, non-positive limits, or invalid defaults.
- Unsupported configured operators, invalid alias results, or ambiguous allowed values and enum names.
- Invalid storage conversions, colliding map keys, or text modes on non-text fields.
- Dynamic patterns without key rules, invalid key expressions, or defaults on patterns.
- Invalid directive declarations, conflicting sort names, or invalid sort directions.
- Invalid LIKE escape characters or whole-word matching requested from `LikePattern`.

A default that fails validation throws when applied, naming its field. A default sort with an unknown key or direction also fails when applied. These are configuration problems rather than invalid user input.

The string adapter throws `LogicException` for values it cannot represent, such as non-finite floats or programmatic wildcard segments outside the grammar.

Use [query limits](../advanced-usage/query-limits-and-untrusted-input) to bound parser work before adapting user input.
