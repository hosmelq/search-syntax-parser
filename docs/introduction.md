---
title: "Introduction"
description: "Parse expressive search queries into a typed syntax tree, structured arrays, or normalized query text in PHP."
weight: 1
---

Search Syntax Parser turns a compact query language into an abstract syntax tree (AST). Build a structured array for your search backend, serialize the tree to query text, or provide your own adapter.

```php
use HosmelQ\SearchSyntaxParser\SearchParser;

$result = SearchParser::query('title:Coffee AND price:<10')
    ->allowedFields(['title', 'price'])
    ->build();

assert($result['operator'] === 'AND');
assert($result['right']['value'] === 10);
```

The language supports free-text terms, field comparisons, uppercase boolean operators, lists, inclusive ranges, existence checks, and explicit wildcard patterns. Field schemas control casting, validation, internal names, and defaults.

No fields are allowed until you configure `allowedFields()` or explicitly call `allowAnyField()`. Bare terms work without configuration.

The package is framework-independent. It parses queries; your adapter decides how those queries are executed.

Start by [installing the package](installation), then read the [query syntax](basic-usage/query-syntax) and [field validation guide](basic-usage/field-validation-and-value-types).
