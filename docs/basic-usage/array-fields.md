---
title: "Array fields"
description: "Validate collections and tuples with shared item rules, index-specific casting, and collection size constraints."
weight: 3
---

Use `AllowedField::array()` for fields whose values form a collection. Collection size rules count the items remaining after [ignored values](field-validation-and-value-types#ignored-values) are removed. A single query value is treated as a one-item array during validation, but its AST remains a comparison.

A list such as `tags:alpha,beta` matches either value. Repeating the field, `tags:alpha tags:beta`, requires both. `tags:!=alpha` means no element equals `alpha`, including records with no elements. See [query semantics](query-syntax#complements-and-missing-values).

## Shared item rules

`each()` configures rules for every item. It supports the same scalar types as fields, including `date()` and `enum()`, as well as callbacks and size constraints.

```php
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;
use HosmelQ\SearchSyntaxParser\Validation\AllowedFieldItemRules;

$result = SearchParser::query('tags:alpha,beta')->allowedFields([
    AllowedField::array('tags')->max(5)->each(
        fn (AllowedFieldItemRules $rules): AllowedFieldItemRules => $rules->string()->max(10),
    ),
])->build();

assert($result['values'] === ['alpha', 'beta']);
```

## Positional rules

`at($index, $callback)` configures a zero-based position. An item's `at()` type takes precedence over its `each()` type; without either, it uses ordinary value inference. Validators from both scopes still apply. Size rules use the item's effective type regardless of the order in which rules were configured.

Positions are the ones written in the query. When an ignored item is removed, the remaining items are still cast and validated with the `at()` rules of their written positions, but the list in the AST is renumbered, so a later item can appear at a lower index.

```php
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;
use HosmelQ\SearchSyntaxParser\Validation\AllowedFieldItemRules;

$result = SearchParser::query('location:37.7749,-122.4194')->allowedFields([
    AllowedField::array('location')->size(2)
        ->at(0, fn (AllowedFieldItemRules $rules): AllowedFieldItemRules => $rules->numeric()->between(-90, 90))
        ->at(1, fn (AllowedFieldItemRules $rules): AllowedFieldItemRules => $rules->numeric()->between(-180, 180)),
])->build();

assert($result['values'] === [37.7749, -122.4194]);
```

Dates retain their input text at any position.

```php
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;
use HosmelQ\SearchSyntaxParser\Validation\AllowedFieldItemRules;

$result = SearchParser::query('dates:2024-02-29,2025-01-01')->allowedFields([
    AllowedField::array('dates')->each(
        fn (AllowedFieldItemRules $rules): AllowedFieldItemRules => $rules->date(),
    ),
])->build();

assert($result['values'] === ['2024-02-29', '2025-01-01']);
```

A list containing patterns removes ignored literal items, then validates the remaining collection and its items before expanding into wildcard and comparison leaves. Patterns are never removed by `ignore()`. Defaults for array fields pass through the same collection and item validation; see [defaults](defaults).
