---
title: "Query Limits and Untrusted Input"
description: "Limit query length, nesting, and clause counts before converting user input into search operations in your backend."
weight: 4
---

Configure allowed fields and query limits before accepting user input. Unknown fields fail with `field.not_allowed`; bare terms need no field configuration.

```php
use HosmelQ\SearchSyntaxParser\SearchParser;

$filters = SearchParser::query('NOT (title:Coffee OR title:Tea)')
    ->allowedFields(['title'])
    ->maxLength(500)
    ->maxDepth(16)
    ->maxClauses(100)
    ->build();
```

The limits are checked before the adapter runs. Exceeding one throws `ParseException` with a reason and source position.

## Set limits

| Method | Default | Counts |
| --- | --- | --- |
| `maxLength()` | 10,000 | Unicode characters in the raw input, including whitespace. |
| `maxDepth()` | 32 | Groups and NOT or minus operators along a nested path. |
| `maxClauses()` | 1,024 | Leaf clauses, including directives and sorting. |

Length is checked before tokenization. A literal list counts as one clause. A list with wildcard patterns counts every expanded item, including items removed by `ignore()`.

Ignored clauses still count toward input limits. Defaults bypass depth and clause limits, while the original input still obeys the length limit.

All limits must be positive integers. Choose bounds appropriate for the application; very large limits can exhaust memory or the PHP stack when building or releasing a tree.

## Apply backend rules

Use [field definitions](../basic-usage/field-validation-and-value-types) to validate names and values. `allowAnyField()` accepts arbitrary field names, so enable it only when the backend can handle them.

Map accepted names to trusted backend identifiers and bind values as parameters. The [clause compiler](compiling-clauses) prepares operators, storage values, and text patterns. Your adapter still applies backend comparisons, ranges, complements, and query execution.

[Defaults](../basic-usage/defaults) can be overridden by field references. Apply mandatory authorization filters independently.

## Handle an empty result

An ignored clause suppresses its field's default. If every filter disappears and no default applies, `parse()` and array or custom `build()` return null. The adapter is resolved but is not called for that tree.

Decide whether an unconstrained search is allowed in your application. Use [structured errors and positions](../basic-usage/error-handling) to report invalid input without exposing backend details.
