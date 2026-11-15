---
title: "Query limits and untrusted input"
description: "Bound raw input length, group depth, and leaf clauses before adapting user queries into backend search operations."
weight: 4
---

No fields are allowed by default. Configure `allowedFields()` before parsing field queries from untrusted input; unknown fields throw `InvalidFieldException` with reason `field_not_allowed`. Bare terms need no field configuration. `allowAnyField()` explicitly accepts every field, so use it only when your backend is prepared to handle arbitrary field names.

Default limits cap a query at 10,000 Unicode characters, 32 nested groups or negations, and 1,024 leaf clauses. Excess input throws `ParseException` before the adapter runs.

```php
use HosmelQ\SearchSyntaxParser\SearchParser;

$result = SearchParser::query('NOT (title:Coffee OR title:Tea)')
    ->allowedFields(['title'])
    ->maxLength(500)
    ->maxDepth(16)
    ->maxClauses(100)
    ->build();

assert($result['operator'] === 'NOT');
```

## What each limit counts

Length counts the raw string, including leading and trailing whitespace, and is checked before tokenizing. Depth counts parenthesized groups and `NOT`/`-` operators along a nested path. Leaf clauses include comparisons, literal lists, ranges, existence checks, terms, and wildcard nodes.

A list without patterns counts once. When a list contains a wildcard, every expanded item counts separately, including items removed by `ignore()`. Ignored clauses still count toward input limits. Defaults bypass the clause and depth limits; the original input still obeys the length limit.

Limits must be positive integers; non-positive values throw `InvalidArgumentException`. Very large custom limits can exhaust memory or the PHP stack while building or releasing an AST, so keep them bounded for untrusted queries.

## Backend integration

Configure [allowed fields and their value rules](../basic-usage/field-validation-and-value-types) before adapting untrusted input. Bind values as parameters, and map field names to trusted backend identifiers. Adapters own wildcard semantics, backend-specific range behavior, and actual query execution.

[Defaults](../basic-usage/defaults) supply optional filters and can be overridden by field references. Enforce mandatory authorization filters independently. Use [error positions](../basic-usage/error-handling) to report invalid input without exposing backend details.

An ignored clause also suppresses its field's default. When every clause disappears and no default applies, the query has no constraints and returns `null` from both `parse()` and `build()`; `build()` resolves the adapter but does not call it. Decide how your application should handle that result before executing a search.
