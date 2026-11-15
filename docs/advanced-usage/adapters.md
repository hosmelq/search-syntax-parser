---
title: "Adapters and array output"
description: "Build structured arrays from every AST node and map external field names to internal names for your search backend."
weight: 1
---

`build()` uses the built-in array adapter. `build('array')` selects it explicitly, while `parse()` returns a `NodeInterface` tree or `null` when there are no constraints and no default applies. `build()` still resolves the adapter, so an unknown name fails, but returns `null` without calling it in that case. Pass a non-null tree to an adapter directly.

```php
use HosmelQ\SearchSyntaxParser\Adapter\ArrayAdapter;
use HosmelQ\SearchSyntaxParser\SearchParser;

$parser = SearchParser::query('title:Coffee')->allowAnyField();

assert(new ArrayAdapter()->build($parser->parse()) === $parser->build());
```

## Array output reference

Each node has a lowercase `type`. Field nodes use the configured internal field name. Bare wildcard terms have a null field. Typed scalars retain their PHP types; decimal and date fields retain text.

| Node | Array keys |
| --- | --- |
| Comparison | `default: bool`, `field`, `operator`, `type: comparison`, `value` |
| Term | `quoted: bool`, `type: term`, `value` |
| Binary operator | `left`, `operator: AND/OR`, `right`, `type: binary` |
| Unary operator | `default: bool`, `operand`, `operator: NOT`, `type: unary` |
| List | `default: bool`, `field`, `operator: =/!=`, `type: in`, `values` |
| Range | `field`, `from`, `to`, `type: range` |
| Exists | `default: bool`, `field`, `type: exists` |
| Wildcard | `field`, `operator: =/!=`, `segments`, `type: wildcard` |

`left`, `right`, and `operand` contain nested node arrays. Range bounds are inclusive, and an open bound is `null`. A quoted `"*"` bound remains the literal string `*`. Source spans are available on AST nodes and are omitted from array output. Wildcard segments contain literal text between wildcard operators, including empty leading or trailing segments.

```php
use HosmelQ\SearchSyntaxParser\SearchParser;

assert(SearchParser::query('title:Coffee')->allowAnyField()->build() === [
    'default' => false,
    'field' => 'title',
    'operator' => '=',
    'type' => 'comparison',
    'value' => 'Coffee',
]);

assert(SearchParser::query('title:co*fee')->allowAnyField()->build() === [
    'field' => 'title',
    'operator' => '=',
    'segments' => ['co', 'fee'],
    'type' => 'wildcard',
]);
```

Literal lists stay one node. Lists containing any pattern expand into binary trees: equality joins leaves with OR and inequality joins them with AND. Defaults appear in the same tree as explicit query filters. Comparison, list, exists, and unary arrays always include `default: bool`, mirroring `isDefault()`. It is true on default comparisons, default lists, and both the NOT wrapper and the exists check of a null default; parsed clauses use false. Other node types do not carry this key.

## Adapter selection

`adapter()` returns an adapter instance, memoized per parser and name. `getDefaultAdapter()` returns `array`. Register custom creators with `extend($name, $callback)`.

See the [string adapter](string-adapter) for query text or [custom adapters and visitors](custom-adapters-and-visitors) for other output formats.
