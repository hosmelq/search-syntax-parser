---
title: "Query syntax"
description: "Write terms, comparisons, lists, ranges, existence checks, and wildcard patterns with explicit boolean precedence."
weight: 1
---

Create a parser with `SearchParser::query($text)` or `new SearchParser($text)`. Call `parse()` for the AST or `build()` for the default array output.

## Terms and boolean operators

`AND`, `OR`, and `NOT` are operators only in uppercase. Other casing is ordinary text: `rock and roll` contains three terms joined by implicit AND. `NOT` and a leading `-` bind first, then explicit or implicit `AND`, then `OR`. Parentheses change grouping.

```php
use HosmelQ\SearchSyntaxParser\SearchParser;

assert(SearchParser::query('coffee tea')->build('string') === 'coffee AND tea');
assert(SearchParser::query('(coffee OR tea) -decaf')->build('string') === '(coffee OR tea) AND NOT decaf');
assert(SearchParser::query('this is not good')->build('string') === 'this AND is AND not AND good');
```

Terms preserve their text, including `007`, `12.50`, and `true`. `TermNode::isQuoted()` records whether a term used quotes, and array output includes that boolean as `quoted`. Words support Unicode, hyphens, and punctuation: `José`, `t-shirt`, and `john@example.com` are complete terms. A bare `*` is invalid.

## Field comparisons and lists

Use `field:value`, `field:>value`, `field:>=value`, `field:<value`, `field:<=value`, or `field:!=value`. Comparators also work without a colon, such as `price>10`. Logical keywords are literal text when used as field values, even in uppercase.

Comma-separated values require the colon form. An equal list means OR across its values; a not-equal list means AND across its exclusions. Lists without wildcard patterns remain one `InNode`.

```php
use HosmelQ\SearchSyntaxParser\SearchParser;

assert(SearchParser::query('price:>=10')->build()['operator'] === '>=');
assert(SearchParser::query('status:ACTIVE,DRAFT')->build()['values'] === ['ACTIVE', 'DRAFT']);
assert(SearchParser::query('status:!=SOLD,EXPIRED')->build()['operator'] === '!=');
assert(SearchParser::query('title:AND')->build()['value'] === 'AND');
assert(SearchParser::query('location:37.7749,-122.4194')->build()['values'] === [37.7749, -122.4194]);
```

A leading `-` after a field comparator joins an adjacent word as a negative value. `temp:>-5`, `temp:-5,-1`, and `temp:[-5 TO 5]` are supported; `temp:- 5` is invalid.

## Ranges and existence

Ranges include both endpoints. `TO` must be uppercase and is a separator only inside ranges. An unquoted bound exactly equal to `*` is open and bypasses casting and validation. Open bounds are `null` in the AST and array output. Other bounds use the field rules. Quoted `"*"` is the literal string `*`, and quoted bounds can contain literal asterisks; unquoted wildcard patterns are rejected.

`field:*` checks existence. Use `NOT field:*` for missing values; `field:!=*` and `field:>*` are invalid.

```php
use HosmelQ\SearchSyntaxParser\SearchParser;

assert(SearchParser::query('price:[10 TO 50]')->build()['from'] === 10);
assert(SearchParser::query('price:[* TO 50]')->build()['from'] === null);
assert(SearchParser::query('title:["*" TO "z*"]')->build()['from'] === '*');
assert(SearchParser::query('category:*')->build()['type'] === 'exists');
assert(SearchParser::query('NOT discount:*')->build()['operator'] === 'NOT');
```

## Quoted text

Single or double quotes preserve literal text. Quote values with whitespace or reserved punctuation: `(`, `)`, `[`, `]`, `:`, `,`, `<`, `>`, and `!=`. A leading `-` negates a standalone term, so quote it to search for literal text beginning with a hyphen.

Inside quotes, only `\\`, `\"`, and `\'` are unescaped. Other backslashes remain literal. Unterminated quotes throw a parse error. ISO datetimes such as `2025-01-01T12:30:45.123Z` may be unquoted, with or without a timezone; quote other values containing colons, such as URLs and times.

```php
use HosmelQ\SearchSyntaxParser\SearchParser;

assert(SearchParser::query('url:"https://example.com"')->build()['value'] === 'https://example.com');
assert(SearchParser::query('time:"12:30"')->build()['value'] === '12:30');
assert(SearchParser::query('title:"co*fee"')->build()['value'] === 'co*fee');
```

## Wildcards

Unquoted terms and field values containing `*` produce `WildcardNode` leaves. Their segments are the literal text between wildcards: `nike*` gives `['nike', '']`, `*fee` gives `['', 'fee']`, and `a**b` gives `['a', 'b']`. Consecutive wildcards collapse. A quoted string immediately followed by `*`, such as `"a b"*`, creates a prefix pattern with segments `['a b', '']`; asterisks inside the quotes stay literal.

Field patterns support `:` and `:!=`/`!=`, and reject ordering comparisons. A list containing any pattern expands into OR for equality or AND for inequality. Each expanded leaf counts toward the clause limit. Patterns are cast and validated using the original pattern text, including its asterisks.

```php
use HosmelQ\SearchSyntaxParser\SearchParser;

assert(SearchParser::query('co*fee')->build()['segments'] === ['co', 'fee']);
assert(SearchParser::query('name:"a b"*')->build()['segments'] === ['a b', '']);
assert(SearchParser::query('name:jo*,ann')->build()['operator'] === 'OR');
assert(SearchParser::query('name:!=jo*,ann')->build()['operator'] === 'AND');
```

See the [array reference](../advanced-usage/adapters) for output shapes and the [string adapter](../advanced-usage/string-adapter) for normalized query text.
