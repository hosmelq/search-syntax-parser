---
title: "Introduction"
description: "Parse search queries into structured data with field searches, boolean logic, ranges, lists, and adapters."
weight: 1
---

Search Syntax Parser turns search text into a syntax tree. Queries can contain words, field comparisons, boolean groups, lists, ranges, and wildcard patterns. Field rules decide which names and values are allowed, how values are cast, and which defaults apply.

The package runs on PHP without a framework. It returns structured arrays or normalized query text, provides a clause compiler for adapters that execute searches, and filters records in memory with `Query\Matcher`. Field and sort definitions can be exported as JSON for autocomplete.

## Quick start

After [installing the package](installation), pass a query and its allowed fields to `SearchParser`:

```php
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\Field;

$parser = SearchParser::query('title:Coffee price:<10')->allowedFields([
    Field::string('title'),
    Field::numeric('price')->min(0),
]);

$filters = $parser->build();
$text = $parser->build('string');
```

`$filters` contains two comparisons joined with AND. `$text` is `title:Coffee AND price:<10`. Call `parse()` to work with the syntax tree directly.

No fields are allowed until you configure them. Bare terms work without field rules. An empty query returns null unless a [default](basic-usage/defaults) supplies a filter.

## Next steps

- [Query syntax](basic-usage/query-syntax) covers the forms a search can contain.
- [Field validation](basic-usage/field-validation-and-value-types) configures types, aliases, and storage values.
- [Directives and sorting](basic-usage/directives-and-sorting) keeps query settings separate from filters.
- [Matching records](basic-usage/matching-records) filters arrays and objects in memory.
- [Compiling clauses](advanced-usage/compiling-clauses) prepares filters for a backend adapter.
