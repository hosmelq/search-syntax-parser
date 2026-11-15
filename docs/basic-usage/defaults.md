---
title: "Field Defaults"
description: "Add validated filters when a field is absent, distinguish generated nodes, and keep default values out of serialized queries."
weight: 4
---

Call `default()` on an allowed field to add a filter when the query does not mention that field. Defaults join the filter tree with AND in the order passed to `allowedFields()`.

```php
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;

$parser = SearchParser::query('coffee')->allowedFields([
    AllowedField::string('status')->default('active'),
    AllowedField::in('region', ['north', 'south'])->default(['north', 'south']),
    AllowedField::string('deleted_at')->default(null),
]);

$filters = $parser->build();
$text = $parser->build('string');
```

`$filters` includes all three defaults. `$text` contains only `coffee`: the string adapter omits generated defaults.

## Choose a default value

| Value | Filter |
| --- | --- |
| Scalar | Equality comparison. |
| Non-empty scalar list | Membership list, including a one-item list. |
| Null | `NOT field:*`; also makes the field nullable. |

Scalars can be booleans, integers, floats, or strings. Empty, associative, or nested arrays, objects, and lists containing null are configuration errors. Pattern fields such as `meta.*` cannot have defaults; configure an exact field for a specific key.

Strings follow the same aliases and casting as unquoted query values. For example, `AllowedField::integer('age')->default('18')` produces the integer `18`. Other scalar defaults must already have the type the field accepts. A string containing `*` stays literal text.

When applied, defaults pass through the field's validation rules. An invalid default throws `InvalidArgumentException` naming the field. Scalar fields validate every member of a list. Array fields validate the whole collection and its shared and positional item rules. Null defaults skip value validation.

Defaults bypass `ignore()`, operator and clause restrictions, and depth and clause limits. The original query still obeys the length limit. Relative date defaults remain text and are resolved at request time by the backend adapter.

## Override a default

Any reference to the external field suppresses its default, including a reference inside NOT or a group. A bare term with the same name does not count.

A clause removed by `ignore()` still counts as a reference:

```php
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;

$parser = SearchParser::query('coffee status:ALL')->allowedFields([
    AllowedField::string('status')->ignore('ALL')->default('active'),
]);

$text = $parser->build('string');
```

The result is `coffee`, and no status filter is added. Defaults are optional search settings; apply mandatory access filters separately.

Empty queries contain only the applicable defaults. Without filter defaults, an empty query or a query whose filters are all ignored returns null from `parse()` and array or custom `build()` calls. Custom adapters are not called for a null tree.

## Identify generated nodes

`ComparisonNode`, `InNode`, `ExistsNode`, and `UnaryOperatorNode` expose `isDefault()`. Both the NOT wrapper and exists leaf of a null default return true. Parsed nodes and ordinary programmatic nodes return false.

Generated nodes and their AND joins have null spans. A null span alone does not identify a default because programmatic trees can also have no span. Parsed clauses retain their original spans.

```php
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;

$tree = SearchParser::query('coffee')->allowedFields([
    AllowedField::string('status')->default('active'),
])->parse();

$default = $tree->getRight();
```

`$default->isDefault()` is true and `$default->getSpan()` is null. Array output includes the `default` flag on these node types. Compiled clauses keep a null `position` for generated leaves.

On the field definition, `hasDefault()` distinguishes an explicit null default from no default; `getDefault()` returns the configured value.

## Set directive and sort defaults

A [directive](directives-and-sorting) default must be scalar. It appears in `getDirectives()`, never in the filter tree or `getTypedDirectives()`. Aliases, casting, and validation apply. An explicitly ignored directive suppresses its default.

`defaultSort('created-desc')` supplies an allowed sort when no `sort:` clause was typed. Check `getSort()->isDefault()` to identify it.

The string adapter omits both kinds of default. If only default settings remain and there is no filter tree, `build('string')` returns null. If a tree consists entirely of filter defaults, it returns an empty string. Explicit directives and sorts can still produce text when the filter tree is null.
