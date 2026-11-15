---
title: "Query syntax"
description: "Write terms, comparisons, lists, ranges, existence checks, and wildcard patterns with explicit boolean precedence."
weight: 1
---

Create a parser with `SearchParser::query($text)` or `new SearchParser($text)`. Call `parse()` for the AST or `build()` for the default array output. When no constraint remains and no default applies, both return `null`; defaults still apply to empty input.

Field queries require `allowedFields()` or an explicit `allowAnyField()`. The examples below use `allowAnyField()` to focus on syntax; use an [allowlist](field-validation-and-value-types) for untrusted input. Bare terms need no configuration.

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

Comma-separated values require the colon form. An equal list means OR across its values; a not-equal list means AND across its exclusions. Lists without wildcard patterns remain one `InNode`. On multi-valued fields, `tag:a,b` matches any listed value, while repeating the field (`tag:a tag:b`) requires all values.

```php
use HosmelQ\SearchSyntaxParser\SearchParser;

assert(SearchParser::query('price:>=10')->allowAnyField()->build()['operator'] === '>=');
assert(SearchParser::query('status:ACTIVE,DRAFT')->allowAnyField()->build()['values'] === ['ACTIVE', 'DRAFT']);
assert(SearchParser::query('status:!=SOLD,EXPIRED')->allowAnyField()->build()['operator'] === '!=');
assert(SearchParser::query('title:AND')->allowAnyField()->build()['value'] === 'AND');
assert(SearchParser::query('location:37.7749,-122.4194')->allowAnyField()->build()['values'] === [37.7749, -122.4194]);
```

A leading `-` after a field comparator joins an adjacent word as a negative value. `temp:>-5`, `temp:-5,-1`, and `temp:[-5 TO 5]` are supported; `temp:- 5` is invalid.

## Ranges and existence

Ranges include both endpoints. `TO` must be uppercase and is a separator only inside ranges. An unquoted bound exactly equal to `*` is open and bypasses casting and validation. Open bounds are `null` in the AST and array output. Other bounds use the field rules. Quoted `"*"` is the literal string `*`, and quoted bounds can contain literal asterisks; unquoted wildcard patterns are rejected.

`field:*` checks existence. Use `NOT field:*` for missing values; `field:!=*` and `field:>*` are invalid.

```php
use HosmelQ\SearchSyntaxParser\SearchParser;

assert(SearchParser::query('price:[10 TO 50]')->allowAnyField()->build()['from'] === 10);
assert(SearchParser::query('price:[* TO 50]')->allowAnyField()->build()['from'] === null);
assert(SearchParser::query('title:["*" TO "z*"]')->allowAnyField()->build()['from'] === '*');
assert(SearchParser::query('category:*')->allowAnyField()->build()['type'] === 'exists');
assert(SearchParser::query('NOT discount:*')->allowAnyField()->build()['operator'] === 'NOT');
```

## Value comparison semantics

Values compare case-insensitively. Adapters must compare text that way for exact, prefix, substring, whole-word, and explicit wildcard searches, including exclusions. MySQL integrations can use case-insensitive collations; PostgreSQL integrations can use `ILIKE`, `lower()`, or `citext` as appropriate. Quoting makes a value literal and overrides configured text match modes to exact, but does not make comparisons case-sensitive. Field names and alias tokens remain exact and case-sensitive.

`in()` returns its configured canonical string, and `enum()` accepts either backing values or case names, ignoring case. Enum backing values take precedence when a token also matches another case's name. Non-string allowed members retain their existing matching rules. See [field validation and value types](field-validation-and-value-types) for configuration errors and storage conversions.

`prefix()`, `contains()`, and `words()` select how unquoted text matches; the last configured mode wins. `words()` requests whole-word matching; the adapter implements word boundaries appropriate to its backend. A quoted value forces exact matching for that comparison or list item. Explicit wildcard patterns keep their own meaning regardless of the field's configured mode.

## Complements and missing values

`NOT x`, `-x`, and `field:!=v` mean set complement. When a field is `nullable()`, its stored value can be missing: a NULL column, an optional relation, or an absent key. Adapters must include those records in the complement. For example, `NOT price:>10` includes a missing price as well as prices at most 10; replacing it with `price:<=10` would lose missing values.

On multi-valued fields, `tag:!=a` means "no element equals a" and includes records without elements. `tag:!=a,b` excludes records with either value, including records that also have other elements. A positive list matches any value; repeated field clauses require all of them.

These semantics apply to custom adapters too. SQL integrations must account for missing values explicitly rather than relying on SQL's three-valued logic. The optional [negation normal form transformer](../advanced-usage/custom-adapters-and-visitors#negation-normal-form) keeps complements explicit.

## Quoted text

Single or double quotes preserve literal text. `ComparisonNode::isQuoted()` and `InNode::isQuoted(int $index)` retain field value quoting, including each item of a mixed list; array output exposes the same flags under `quoted`. Default values are unquoted. The string adapter retains explicit value quotes, so `title:"mug"` remains `title:"mug"`. Quote values with whitespace or reserved punctuation: `(`, `)`, `[`, `]`, `:`, `,`, `<`, `>`, and `!=`. A leading `-` negates a standalone term, so quote it to search for literal text beginning with a hyphen.

Inside quotes, only `\\`, `\"`, and `\'` are unescaped. Other backslashes remain literal. Unterminated quotes throw a parse error. ISO datetimes such as `2025-01-01T12:30:45.123Z` may be unquoted, with or without a timezone; quote other values containing colons, such as URLs and times.

```php
use HosmelQ\SearchSyntaxParser\SearchParser;

assert(SearchParser::query('url:"https://example.com"')->allowAnyField()->build()['value'] === 'https://example.com');
assert(SearchParser::query('time:"12:30"')->allowAnyField()->build()['value'] === '12:30');
assert(SearchParser::query('title:"co*fee"')->allowAnyField()->build()['value'] === 'co*fee');
```

## Wildcards

Unquoted terms and field values containing `*` produce `WildcardNode` leaves. Their segments are the literal text between wildcards: `nike*` gives `['nike', '']`, `*fee` gives `['', 'fee']`, and `a**b` gives `['a', 'b']`. Consecutive wildcards collapse. A quoted string immediately followed by `*`, such as `"a b"*`, creates a prefix pattern with segments `['a b', '']`; asterisks inside the quotes stay literal.

Field patterns support `:` and `:!=`/`!=`, and reject ordering comparisons. A list containing any pattern expands into OR for equality or AND for inequality. Each expanded leaf counts toward the clause limit. Patterns are cast and validated using the original pattern text, including its asterisks.

```php
use HosmelQ\SearchSyntaxParser\SearchParser;

assert(SearchParser::query('co*fee')->build()['segments'] === ['co', 'fee']);
assert(SearchParser::query('name:"a b"*')->allowAnyField()->build()['segments'] === ['a b', '']);
assert(SearchParser::query('name:jo*,ann')->allowAnyField()->build()['operator'] === 'OR');
assert(SearchParser::query('name:!=jo*,ann')->allowAnyField()->build()['operator'] === 'AND');
```

See the [array reference](../advanced-usage/adapters) for output shapes and the [string adapter](../advanced-usage/string-adapter) for normalized query text.
