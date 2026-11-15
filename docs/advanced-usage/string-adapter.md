---
title: "String adapter"
description: "Serialize syntax trees into normalized query text with explicit operators, safe quoting, and preserved external field names."
weight: 2
---

Call `build('string')` to serialize the AST into normalized query text. An AST containing only defaults serializes to `''`; a query with no AST returns `null` from `build('string')`. The string adapter omits default-origin clauses and keeps external field names, even when the array adapter maps them to internal names.

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

Comparisons use `field:value` or `field:>value`, `field:>=value`, `field:<value`, `field:<=value`, and `field:!=value`. Lists use `field:a,b` or `field:!=a,b`. Ranges use `field:[a TO b]` with `*` for null open bounds and `"*"` for literal asterisk bounds. Exists queries use `field:*`.

Booleans become `true` or `false`. Integers use digits, and floats retain a decimal point so reparsing infers a float. Exponent notation is expanded to a decimal literal. Strings are quoted when needed to preserve their type or syntax, including numeric-looking strings, logical keywords, empty strings, leading hyphens, whitespace, and literal asterisks. Double quotes and backslashes are escaped. Terms retain their `isQuoted()` flag: quoted terms remain quoted, while unquoted terms such as `123`, `007`, and `true` remain unquoted. Source spans are omitted from the output.

```php
use HosmelQ\SearchSyntaxParser\SearchParser;

assert(SearchParser::query('"coffee"')->build('string') === '"coffee"');
assert(SearchParser::query('007')->build('string') === '007');
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

Reapply the same field schema when reparsing typed queries: schemas can cast quoted numeric text, decimal strings, and enum values. Field queries still require an allowlist or `allowAnyField()` when reparsed. Defaults are validated when applied but omitted from serialized text. A singleton list left after ignoring items serializes as one value; reparsing that text creates a comparison because the query grammar has no singleton-list notation.

Default-origin clauses and removed clauses are absent from serialized text, including mentions that suppressed defaults. Reapplying defaults when reparsing that text can therefore restore filters that the original query opted out of.

Serialization preserves the remaining tree rather than its original validation context. Reparsing an expanded wildcard list validates separate field clauses, so collection size constraints may reject that text. Collapsing wildcard runs can also change size checks on the original pattern text.
