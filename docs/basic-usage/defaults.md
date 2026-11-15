---
title: "Field defaults"
description: "Apply validated field defaults to the syntax tree, track their origin, and keep them out of serialized query text."
weight: 4
---

Set `default()` on an allowed field to add a filter when that external field is absent from the parsed query. Defaults are appended with AND in the order given to `allowedFields()`, so backend adapters receive them. The string adapter omits default clauses.

```php
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;

$parser = SearchParser::query('coffee')->allowedFields([
    AllowedField::string('status')->default('active'),
    AllowedField::in('region', ['north', 'south'])->default(['north', 'south']),
    AllowedField::string('deleted_at')->default(null),
]);

assert($parser->build('string') === 'coffee');
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
])->build('string') === '');
```

```php
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;

assert(SearchParser::query('coffee status:ALL')->allowedFields([
    AllowedField::string('status')->ignore('ALL')->default('active'),
])->build('string') === 'coffee');
```

Empty and whitespace-only queries return just the defaults when any exist. Without applicable defaults, an empty query or a query whose clauses are all ignored has no constraints. Both `parse()` and `build()` return `null`, and `build()` does not call the adapter. When defaults alone remain, `parse()` returns their tree and `build('string')` returns `''`.

When applying a default, `parse()` validates it like the equivalent query clause. A failing default throws `InvalidArgumentException` naming the field because it is a configuration error. On scalar fields, every item of a list default must be valid, as in `field:a,b`. On `array()` fields, a list default is validated as a whole collection, including collection size, shared item rules, and positional rules, and a scalar default as a one-item collection. Null defaults skip value rules, like the `NOT field:*` clause they produce.

String defaults are read like unquoted query text: aliases resolve, typed fields cast them, and untyped fields infer booleans and numbers, so `AllowedField::integer('age')->default('18')` produces `18`. Other scalars must already have the type the field casts text to, such as an `int` for `integer()` or an allowed value for `in()`. Defaults bypass ignored values and per-field operator and clause restrictions, which govern what users type, as well as clause and depth limits. The raw input still obeys the query length limit. A default string containing `*` is literal text rather than a wildcard pattern. Defaults can be overridden by a query reference, so enforce mandatory access filters separately from defaults.

Relative date defaults remain text: `AllowedField::date('due')->default('today')` produces the string `'today'` in the AST. The backend adapter resolves it with the request's time and zone using [DateValue](field-validation-and-value-types#resolving-date-intervals), so configuring a default does not freeze its date.

## Default origin and source spans

Every node created while applying defaults has a null `getSpan()`, including generated AND joins and the NOT/exists pair for a null default. Nodes parsed from typed clauses retain their original spans. `ComparisonNode`, `InNode`, and both nodes of a null default's NOT/exists pair expose `isDefault()`. It is true only for default-origin clauses; parsed nodes and ordinary hand-built nodes return false. A null span alone does not identify a default because hand-built ASTs also have null spans. Array output includes `default: bool` on comparison, list, exists, and unary nodes. String output omits default clauses, including a null default's entire NOT/exists pair. Neither adapter includes spans.

```php
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;

$node = SearchParser::query('coffee')->allowedFields([
    AllowedField::string('status')->default('active'),
])->parse();

assert($node->getSpan() === null);
assert($node->getLeft()->getSpan()->length === 6);
assert($node->getRight()->getSpan() === null);
assert($node->getRight()->isDefault());
```
