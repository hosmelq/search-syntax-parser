---
title: "Adapters and array output"
description: "Build structured arrays from every AST node and map external field names to internal names for your search backend."
weight: 1
---

`build()` uses the built-in array adapter. `build('array')` selects it explicitly, while `parse()` returns a filter-only `NodeInterface` tree or `null` when no filters or filter defaults remain. [Directives and sorting](../basic-usage/directives-and-sorting) are separate results in `getDirectives()`, `getTypedDirectives()`, and `getSort()`. `build()` still resolves the adapter, so an unknown name fails, but returns `null` without calling it in that case. Pass a non-null tree to an adapter directly, except that `StringAdapter::build()` also accepts null and can serialize explicit settings. `SearchParser::build('string')` calls that adapter for a null tree when typed directives or a typed sort remain. Array output always contains only filters.

```php
use HosmelQ\SearchSyntaxParser\Adapter\ArrayAdapter;
use HosmelQ\SearchSyntaxParser\SearchParser;

$parser = SearchParser::query('title:Coffee')->allowAnyField();

assert(new ArrayAdapter()->build($parser->parse()) === $parser->build());
```

## Array output reference

Each node has a lowercase `type`. Field nodes use the configured internal field name. Bare wildcard terms have a null field. Values use their cast types unless a configured storage conversion changes them. Decimal and date fields retain text without a conversion. UUIDs normalize to lowercase and ULIDs to uppercase.

| Node | Array keys |
| --- | --- |
| Comparison | `default: bool`, `field`, `match: string`, `operator`, `quoted: bool`, `type: comparison`, `value` |
| Term | `quoted: bool`, `type: term`, `value` |
| Binary operator | `left`, `operator: AND/OR`, `right`, `type: binary` |
| Unary operator | `default: bool`, `operand`, `operator: NOT`, `type: unary` |
| List | `default: bool`, `field`, `match: list<string>`, `operator`, `quoted: list<bool>`, `type: in`, `values` |
| Range | `field`, `from`, `to`, `type: range` |
| Exists | `default: bool`, `field`, `type: exists` |
| Wildcard | `field`, `operator: =/!=`, `segments`, `type: wildcard` |

`left`, `right`, and `operand` contain nested node arrays. Bracket ranges and compact `a..b` ranges produce the same output. Range bounds are inclusive, and an open bound is `null`. A quoted `"*"` bound remains the literal string `*`. Source spans are available on AST nodes and are omitted from array output. Wildcard segments contain literal text between wildcard operators, including empty trailing segments and, when explicitly enabled, empty leading segments.

```php
use HosmelQ\SearchSyntaxParser\SearchParser;

assert(SearchParser::query('title:Coffee')->allowAnyField()->build() === [
    'default' => false,
    'field' => 'title',
    'match' => 'exact',
    'operator' => '=',
    'quoted' => false,
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

## Match metadata and storage values

Comparison and list output always includes `match` and `quoted`, with all keys in alphabetical order. A comparison's `match` is `exact`, `prefix`, `contains`, or `words`; a list's `match` is a list of those strings aligned with `values`. Quoted values use `exact`, and list quote flags are also aligned with the surviving values after ignored items are removed. Unconfigured fields use `exact`, including direct `new ArrayAdapter()` builds without a parser. These modes describe matching; the array adapter does not execute a search.

Pass the parser to `new ArrayAdapter($parser)` when building a tree directly with field mappings, match modes, or storage conversions. This is how `SearchParser::build()` creates its array adapter. It applies `AllowedField::toStorage()` to comparison values, list items, and non-null range bounds. The AST remains unchanged. Applications and custom adapters can read directives and sorting from the parser after parsing. A null filter tree does not prevent applications from applying these settings, but `SearchParser::build()` does not invoke an array or custom adapter for that tree. The core package does not execute settings or backend ordering. Custom adapters must honor [case-insensitive text comparison semantics](../basic-usage/query-syntax#value-comparison-semantics), effective match modes, and storage conversions themselves.

## Dynamic keys and LIKE patterns

For a pattern field such as `AllowedField::string('meta.*', 'attributes.*')->keys('/^[a-z]+$/D')`, `meta.color` remains the AST name and becomes `attributes.color` in array output. `getAllowedField('meta.color')` returns the configured pattern field, so its effective match mode and `toStorage()` apply normally. Exact fields take precedence over patterns; among patterns, the longest prefix wins. Validate dynamic keys and map them to trusted backend identifiers rather than interpolating a query field into SQL.

`HosmelQ\SearchSyntaxParser\Support\LikePattern` supplies framework-independent escaping for SQL adapters. `escape()` protects literal `%`, `_`, and the escape character; `fromWildcard()` builds a pattern from a `WildcardNode`; `forMatch()` handles exact, prefix, and contains modes. Use the result as a bound parameter and emit `LIKE ? ESCAPE '!'` with the default escape. Whole-word matching needs a backend-specific operation: `TextMatch::Words` throws `InvalidArgumentException` in this helper. See the [custom adapter example](custom-adapters-and-visitors#wildcards-to-like).

## Adapter selection

`adapter()` returns an adapter instance, memoized per parser and name. `getDefaultAdapter()` returns `array`. Register custom creators with `extend($name, $callback)`.

See the [string adapter](string-adapter) for query text or [custom adapters and visitors](custom-adapters-and-visitors) for other output formats.
