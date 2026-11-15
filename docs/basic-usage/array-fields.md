---
title: "Array Fields"
description: "Validate collections and tuples with shared item rules, position-specific types, and limits on the number of values."
weight: 3
---

Use `AllowedField::array()` when a field accepts a collection of values. Apply size rules to the collection, then use `each()` or `at()` to configure its items.

## Validate every item

Pass a callback to `each()` to apply the same rules to every value:

```php
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;
use HosmelQ\SearchSyntaxParser\Validation\AllowedFieldItemRules;

$filters = SearchParser::query('tags:alpha,beta')->allowedFields([
    AllowedField::array('tags')->max(5)->each(
        fn (AllowedFieldItemRules $rules): AllowedFieldItemRules => $rules->string()->max(10),
    ),
])->build();
```

This accepts up to five strings, each at most ten characters long. `$filters['values']` contains `['alpha', 'beta']`. Collection size is checked after [ignored values](field-validation-and-value-types#ignore-values) are removed.

A single value such as `tags:alpha` is validated as a one-item collection. Its syntax tree still contains a comparison. A list matches any listed value; repeating a field requires both values. See [query syntax](query-syntax#match-a-list).

Item rules support scalar types, callbacks, and size constraints. For example, accept full dates, partial dates, and relative expressions with:

```php
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;
use HosmelQ\SearchSyntaxParser\Validation\AllowedFieldItemRules;

$dates = AllowedField::array('dates')->each(
    fn (AllowedFieldItemRules $rules): AllowedFieldItemRules => $rules->date()->allowPartialDates(),
);
```

Date items retain their input text. See [dates](field-validation-and-value-types#validate-dates) for accepted formats and backend conversion.

## Validate a position

Use `at()` for a zero-based position. This example accepts a latitude and longitude:

```php
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;
use HosmelQ\SearchSyntaxParser\Validation\AllowedFieldItemRules;

$filters = SearchParser::query('location:37.7749,-122.4194')->allowedFields([
    AllowedField::array('location')->size(2)
        ->at(0, fn (AllowedFieldItemRules $rules): AllowedFieldItemRules => $rules->numeric()->between(-90, 90))
        ->at(1, fn (AllowedFieldItemRules $rules): AllowedFieldItemRules => $rules->numeric()->between(-180, 180)),
])->build();
```

The two values become floats. A position's type overrides the shared `each()` type. Without either type, ordinary value inference applies. Validators from both scopes run, and size rules use the effective type regardless of configuration order.

## Preserve written positions

Ignoring an item does not change which `at()` rule validates the remaining items. Their positions still refer to the original query, even though the resulting list is renumbered.

`InNode::getPositions()` returns those written positions. Custom adapters must pass the corresponding position to `AllowedField::toStorage($value, $index)` when converting an item. The array adapter does this automatically. The clause compiler targets scalar field schemas and does not apply positional item rules.

A list containing wildcards first removes ignored literal items and validates the remaining collection. It then expands into comparisons and wildcard leaves. Patterns are never removed by `ignore()`.

[Defaults](defaults) use the same collection and item rules. A list default is validated as a collection, and a scalar default as one item.
