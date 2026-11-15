---
title: "String adapter"
description: "Serialize syntax trees into normalized query text with explicit operators, safe quoting, and preserved external field names."
weight: 2
---

Call `build('string')` to serialize the AST into normalized query text. An AST containing only defaults serializes to `''`; a query with no AST returns `null` from `build('string')` unless explicit directives or a typed sort remain. The string adapter omits default-origin clauses and keeps external field names and user-facing cast values, even when the array adapter maps names or converts values for storage. It never calls `toStorage()`.

```php
use HosmelQ\SearchSyntaxParser\SearchParser;

$text = SearchParser::query('(coffee OR tea) -status:draft')->allowAnyField()->build('string');

assert($text === '(coffee OR tea) AND NOT status:draft');
$original = SearchParser::query('(coffee OR tea) -status:draft')->allowAnyField()->build();
assert(SearchParser::query($text)->allowAnyField()->build() === $original);
```

```php
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;

assert(SearchParser::query('title:mug')->allowedFields([
    'title',
    AllowedField::string('status')->default('open'),
])->build('string') === 'title:mug');
```

## Normalization

Output uses explicit `AND` and `OR`, and `NOT` instead of `-`. Parentheses preserve precedence and the tree's grouping, including right-nested expressions such as `a AND (b AND c)`.

Comparisons use `field:value` or `field:>value`, `field:>=value`, `field:<value`, `field:<=value`, and `field:!=value`. Lists use `field:a,b` or `field:!=a,b`. Ranges use `field:[a TO b]` with `*` for null open bounds and `"*"` for literal asterisk bounds. Compact input such as `field:a..b` also serializes to the bracket form. Literal comparison text containing a range-shaped `..` expression is quoted. Exists queries use `field:*`.

Booleans become `true` or `false`. Integers use digits, and floats retain a decimal point so reparsing infers a float. Exponent notation is expanded to a decimal literal. An unquoted string value is written bare when it reads back as the same value: through the field's own casting and aliases when the adapter has the parser, as `SearchParser::build('string')` does, otherwise through untyped inference. So `title:123` stays bare on a `string()` field, while an untyped `code:"123"` keeps its quotes to remain a string. Strings are quoted when needed for syntax too, including empty strings, whitespace, and literal asterisks; a leading hyphen stays bare after a field colon (`title:-x`). Logical keywords can remain unquoted as field values. A space separates a leading `=` value from `<` or `>` so it cannot merge into another comparator. An alias is written as its resolved value; when that value needs quotes, such as `John Smith`, the reparsed clause is quoted and therefore matches exactly. Double quotes and backslashes are escaped. Terms retain their `isQuoted()` flag: quoted terms remain quoted, while unquoted terms such as `123`, `007`, and `true` remain unquoted. Comparison values and individual list items retain their explicit quotes too: `title:"mug"` stays quoted, and `title:mug,"cup"` retains its mixed quoting. Quoted typed numbers and booleans remain quoted after casting. Source spans are omitted from the output.

```php
use HosmelQ\SearchSyntaxParser\SearchParser;

assert(SearchParser::query('"coffee"')->build('string') === '"coffee"');
assert(SearchParser::query('007')->build('string') === '007');
assert(SearchParser::query('title:"mug"')->allowAnyField()->build('string') === 'title:"mug"');
assert(SearchParser::query('title:mug,"cup"')->allowAnyField()->build('string') === 'title:mug,"cup"');
assert(SearchParser::query('value:1.00')->allowAnyField()->build('string') === 'value:1.0');
assert(SearchParser::query('value:"123"')->allowAnyField()->build('string') === 'value:"123"');
assert(SearchParser::query('title:"a b"*')->allowAnyField()->build('string') === 'title:"a b"*');
assert(SearchParser::query('name:a**b')->allowAnyField()->build('string') === 'name:a*b');
```

## Wildcards and programmatic trees

Wildcard segments join with `*`. When the leading literal segment needs quotes, a prefix pattern uses the `"text"*` form. Patterns whose segment lists cannot be represented by the grammar throw `LogicException`. Non-finite floats also cannot be represented as query values and throw `LogicException`.

You can serialize a programmatic tree directly.

```php
use HosmelQ\SearchSyntaxParser\Adapter\StringAdapter;
use HosmelQ\SearchSyntaxParser\AST\Node\ComparisonNode;

assert(new StringAdapter()->build(new ComparisonNode('price', '>=', 10.0)) === 'price:>=10.0');
```

For direct serialization of configured fields, construct `new StringAdapter($parser)` so values are written bare whenever the field reads them back unchanged; quotes would otherwise force an exact match. Values equal to an alias token stay quoted, and alias closures never run while serializing. Reapply the same field schema when reparsing typed queries: schemas can cast quoted numeric text, decimal strings, and enum values. Reapply `allowLeadingWildcards()` on the configured field or parser when the serialized pattern requires it. Dynamic field names remain fully typed, such as `meta.color`, and need the same exact or pattern field rules on reparse. Field queries still require an allowlist or `allowAnyField()` when reparsed. Defaults are validated when applied but omitted from serialized text. A singleton list left after ignoring items serializes as one value; reparsing that text creates a comparison because the query grammar has no singleton-list notation. Ignoring items also renumbers the list, so on array fields with `at()` rules the remaining items are written and reparsed at their new positions, which can change how a value is quoted or cast.

Default-origin clauses and removed clauses are absent from serialized text, including mentions that suppressed defaults. Reapplying defaults when reparsing that text can therefore restore filters that the original query opted out of.

Serialization preserves the remaining tree rather than its original validation context. Reparsing an expanded wildcard list validates separate field clauses, so collection size constraints may reject that text. Collapsing wildcard runs can also change size checks on the original pattern text.

## Directives and sorting

After `parse()` or `build()`, the string adapter reads the parser's resolved [directives and sort](../basic-usage/directives-and-sorting) without parsing again. It appends explicitly supplied directives in configuration order, then `sort:key-direction`, using top-level AND joins. An OR filter root is parenthesized before appending settings. Directive values retain their cast scalar types and public names, and sort output uses the configured public name with a lowercase explicit direction. Reapply the same field and sort declarations when reparsing.

`StringAdapter::build()` accepts `null|NodeInterface`; `new StringAdapter($parser)->build(null)` serializes the parser's explicit settings. `SearchParser::build('string')` invokes it with a null tree only when explicit, non-ignored directives or a typed sort exist. Array and custom adapters keep their non-null AST contract and are not invoked for null trees. Default directives and default sorts are omitted. Without explicit settings or a tree, `SearchParser::build('string')` returns null; with a tree consisting only of default filters, it returns an empty string.

Ignored directive mentions are also omitted. Reparsing with the same defaults can therefore restore a directive default that the ignored input suppressed, just as it can for ignored filters.
