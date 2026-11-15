---
title: "Adapters and Array Output"
description: "Build structured arrays from syntax trees, map field names and storage values, and select an adapter for another output format."
weight: 1
---

Call `build()` to produce structured array output. `build('array')` selects the same adapter explicitly, while `parse()` returns the syntax tree.

```php
use HosmelQ\SearchSyntaxParser\SearchParser;

$filters = SearchParser::query('title:Coffee')->allowedFields(['title'])->build();
```

The result is:

```php
[
    'default' => false,
    'field' => 'title',
    'match' => 'exact',
    'operator' => '=',
    'quoted' => false,
    'type' => 'comparison',
    'value' => 'Coffee',
]
```

Array output describes filters. [Directives and sorting](../basic-usage/directives-and-sorting) remain separate parser results, and the adapter does not execute a search.

## Read node output

Each node has a lowercase `type`. Child nodes use the same array representation:

| Node | Keys |
| --- | --- |
| Comparison | `default`, `field`, `match`, `operator`, `quoted`, `type`, `value` |
| Term | `quoted`, `type`, `value` |
| Binary operator | `left`, `operator`, `right`, `type` |
| Unary operator | `default`, `operand`, `operator`, `type` |
| List | `default`, `field`, `match`, `operator`, `quoted`, `type`, `values` |
| Range | `field`, `from`, `to`, `type` |
| Exists | `default`, `field`, `type` |
| Wildcard | `field`, `operator`, `segments`, `type` |

Binary operators are `AND` or `OR`; unary operators are `NOT`. Literal lists remain one node. Lists with patterns expand into trees joined by OR for equality and AND for inequality.

Ranges use inclusive written bounds, with null for an open end. Bracket and compact syntax produce the same output. A quoted `"*"` remains a literal string.

Wildcard segments are the literal text between stars. A bare wildcard has a null field. Default flags identify generated clauses, including both nodes of a null default. Parsed clauses use false. Source spans are available on the AST and are omitted from arrays.

## Read match metadata

Comparisons expose `match` as `exact`, `prefix`, `contains`, or `words`. Lists expose one mode per value. The `quoted` flag is a boolean on comparisons and a list of booleans on lists.

Modes and quote flags follow the surviving values after ignored items are removed. Quoted values use exact metadata. Unconfigured fields also use exact metadata. These values describe matching; the backend adapter implements it.

## Map names and stored values

With a parser, the array adapter uses internal field names and applies `toStorage()` to comparison values, list items, and non-null range bounds. Array items retain their written positions for conversion. The syntax tree remains unchanged.

```php
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\Field;

$parser = SearchParser::query('price:10.50')->allowedFields([
    Field::decimal('price', 2, internalName: 'price_cents')->scale(100),
]);

$filters = $parser->build();
```

The output field is `price_cents` and its value is `1050`. Without a conversion, decimal and date fields retain text. UUIDs and ULIDs use their normalized case.

Dynamic mappings also apply: a declaration from `meta.*` to `attributes.*` turns `meta.color` into `attributes.color`. Exact fields take precedence, then the longest matching pattern. Always map accepted names to trusted backend identifiers.

## Build a tree directly

Pass the parser to an adapter when the tree needs field metadata:

```php
use HosmelQ\SearchSyntaxParser\Adapter\ArrayAdapter;
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\Field;

$parser = SearchParser::query('title:Coffee')->allowedFields([
    Field::string('title', 'product_title')->prefix(),
]);

$filters = new ArrayAdapter($parser)->build($parser->parse());
```

A direct `new ArrayAdapter()` has no mappings, match modes, or storage conversions. It still serializes the tree.

Array and custom adapters require a non-null tree. `SearchParser::build()` resolves the adapter but returns null without calling it when no filters remain. An unknown adapter name still fails. The string adapter also supports null trees with explicit settings.

## Select another adapter

`adapter($name)` returns an instance cached per parser and name. `getDefaultAdapter()` returns `array`. Register a creator with `extend()` to add an output format.

Use the [string adapter](string-adapter) for normalized query text, or build [custom adapters and visitors](custom-adapters-and-visitors). For backend operators, storage values, text patterns, and words, use the [clause compiler](compiling-clauses).
