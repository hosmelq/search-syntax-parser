---
title: "Field defaults"
description: "Apply trusted field defaults directly to the syntax tree whenever the query does not reference those external fields."
weight: 4
---

Set `default()` on an allowed field to add a filter when that external field is absent from the parsed query. Defaults are appended with AND in the order given to `allowedFields()`, so all adapters receive them.

```php
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;

$parser = SearchParser::query('coffee')->allowedFields([
    AllowedField::string('status')->default('active'),
    AllowedField::in('region', ['north', 'south'])->default(['north', 'south']),
    AllowedField::string('deleted_at')->default(null),
]);

assert($parser->build('string') === 'coffee AND status:active AND region:north,south AND NOT deleted_at:*');
```

## Accepted values

A default must be null, a boolean, an integer, a float, a string, or a non-empty list of those scalar types. Empty arrays, associative arrays, nested arrays, objects, and lists containing null throw `InvalidArgumentException` immediately.

A scalar produces a comparison with `=`. A list produces an `InNode` with `=`, including singleton lists and defaults on `array()` fields. A null produces `NOT field:*` and also makes the field nullable. `getDefault()` returns the configured value and `hasDefault()` distinguishes an explicit null default from no default.

## Referenced fields and empty queries

Any reference to the external field suppresses its default, including comparisons, lists, ranges, existence checks, wildcards, and references inside NOT or nested groups. A bare term matching the field's name does not count as a field reference.

A clause removed by `ignore()` still counts as a reference. For example, `status:ALL` opts out of a status default when `ALL` is ignored.

```php
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;

assert(SearchParser::query('NOT status:draft')->allowedFields([
    AllowedField::string('status')->default('active'),
])->build('string') === 'NOT status:draft');

assert(SearchParser::query('   ')->allowedFields([
    AllowedField::string('status')->default('active'),
])->build('string') === 'status:active');
```

```php
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;

assert(SearchParser::query('coffee status:ALL')->allowedFields([
    AllowedField::string('status')->ignore('ALL')->default('active'),
])->build('string') === 'coffee');
```

Empty and whitespace-only queries return just the defaults when any exist. Without applicable defaults, an empty query or a query whose clauses are all ignored has no constraints. Both `parse()` and `build()` throw `Empty search query.` with reason `empty_query`.

Defaults are trusted application configuration: they bypass field casting, aliases, ignored values, validation, per-field operator and clause restrictions, and clause/depth limits. The raw input still obeys the query length limit. A default string containing `*` is literal text rather than a wildcard pattern. Defaults can be overridden by a query reference, so enforce mandatory access filters separately from defaults.

## Source spans

Every node created while applying defaults has a null `getSpan()`, including generated AND joins and the NOT/exists pair for a null default. Nodes parsed from typed clauses retain their original spans. Consumers can use the null span to distinguish generated defaults from typed clauses; spans are omitted from array and string output.

```php
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;

$node = SearchParser::query('coffee')->allowedFields([
    AllowedField::string('status')->default('active'),
])->parse();

assert($node->getSpan() === null);
assert($node->getLeft()->getSpan()->length === 6);
assert($node->getRight()->getSpan() === null);
```
