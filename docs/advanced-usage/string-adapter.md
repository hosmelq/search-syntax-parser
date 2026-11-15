---
title: "String Adapter"
description: "Serialize syntax trees into normalized query text while preserving external names, cast values, explicit quotes, and grouping."
weight: 2
---

Call `build('string')` to write normalized query text:

```php
use HosmelQ\SearchSyntaxParser\SearchParser;

$text = SearchParser::query('(coffee OR tea) -status:draft')
    ->allowedFields(['status'])
    ->build('string');
```

The result is `(coffee OR tea) AND NOT status:draft`. The adapter keeps external field names and user-facing cast values. It omits default filters and never calls `toStorage()`.

## Normalize query syntax

Output uses explicit AND and OR, with NOT instead of a leading minus. Parentheses preserve precedence and the tree's grouping, including right-nested expressions.

| Input | Output |
| --- | --- |
| `coffee tea` | `coffee AND tea` |
| `-status:draft` | `NOT status:draft` |
| `price:10..20` | `price:[10 TO 20]` |
| `price:*..20` | `price:[* TO 20]` |
| `name:a**b` | `name:a*b` |
| `value:1.00` | `value:1.0` |

Comparisons and lists keep their operators. Ranges always use brackets, with `*` for open ends and `"*"` for literal asterisk bounds. Existence checks use `field:*`.

Booleans use `true` or `false`. Integers use digits, and floats retain a decimal point so they reparse as floats. Exponent notation expands to decimal text. Non-finite floats cannot be serialized and throw `LogicException`.

## Preserve literal values

The adapter quotes strings when syntax or casting requires it, including empty strings, whitespace, literal stars, and range-shaped text. Double quotes and backslashes are escaped. A leading hyphen can remain bare after a field colon, as in `title:-x`.

With field metadata, bare values are checked against that field's casting and aliases. A `string()` field can therefore keep `title:123` bare, while an untyped `code:"123"` keeps its quotes. Alias closures do not run during serialization. Values equal to alias tokens stay quoted.

Explicit quotes are preserved on terms, comparison values, and list items. Typed numbers and booleans keep their quote flag after casting. Unquoted terms such as `007` and `true` remain unquoted text.

```php
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;

$text = SearchParser::query('title:mug,"cup"')->allowedFields([
    AllowedField::string('title')->prefix(),
])->build('string');
```

The result remains `title:mug,"cup"`. The first item uses prefix matching and the quoted item uses exact matching.

An alias is written as its resolved value. If that value needs quotes, reparsing treats it as quoted and therefore uses exact match metadata. A leading `=` value is separated from `<` or `>` by a space so it cannot form a different operator.

## Serialize programmatic trees

Pass a tree directly to `StringAdapter`:

```php
use HosmelQ\SearchSyntaxParser\Adapter\StringAdapter;
use HosmelQ\SearchSyntaxParser\AST\Node\ComparisonNode;

$text = new StringAdapter()->build(new ComparisonNode('price', '>=', 10.0));
```

The result is `price:>=10.0`. Construct `new StringAdapter($parser)` when field rules should determine whether values need quotes.

Wildcard segments join with `*`. A prefix whose literal text needs quotes uses the `"text"*` form. A programmatic pattern that the grammar cannot represent throws `LogicException`.

## Reparse serialized text

Reapply the same fields, sort declarations, and leading-wildcard permissions when parsing the result. The output preserves the remaining tree, but it does not preserve every validation context:

- A one-item list serializes as a comparison because the grammar has no one-item list notation.
- Ignored array items are absent, so remaining items reparse at their new positions.
- Expanded wildcard lists reparse as separate clauses and can fail collection-size rules.
- Collapsed star runs can change length checks on pattern text.
- Ignored field or directive mentions are absent, so reparsing can restore defaults they originally suppressed.

Serialize the original AST when you need query text. Backend clauses from the [clause compiler](compiling-clauses) contain storage values and are not a replacement for that tree.

## Include directives and sorting

After parsing, the adapter reads resolved settings without parsing again. It writes explicit directives in configuration order, then the public sort name and lowercase direction. It joins settings with AND and parenthesizes an OR filter root.

Default directives and default sorts are omitted. Explicit settings can produce text even when the filter tree is null. `StringAdapter::build()` accepts a null tree for this purpose when constructed with the parser.

With no tree or explicit settings, `SearchParser::build('string')` returns null. A tree consisting only of default filters produces an empty string. See [directives and sorting](../basic-usage/directives-and-sorting#serialize-settings).
