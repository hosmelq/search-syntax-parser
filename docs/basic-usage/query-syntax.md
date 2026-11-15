---
title: "Query Syntax"
description: "Write search terms, field comparisons, lists, ranges, and wildcard patterns, and combine them with boolean operators."
weight: 1
---

Create a parser with `SearchParser::query($text)` or `new SearchParser($text)`. `parse()` returns a syntax tree and `build()` returns an array. Both return null when no filters or defaults remain.

Field queries need [allowed fields](field-validation-and-value-types). Call `allowAnyField()` only when the application can handle every field name. Bare words need no configuration.

## Combine search terms

Separate words with spaces to require all of them. Use `OR` for alternatives and `NOT` or a leading `-` to exclude a term:

```php
use HosmelQ\SearchSyntaxParser\SearchParser;

$text = SearchParser::query('(coffee OR tea) -decaf')->build('string');
```

The result is `(coffee OR tea) AND NOT decaf`. Negation binds first, then AND, then OR. Parentheses change that order.

Only uppercase `AND`, `OR`, and `NOT` are operators. `rock and roll` contains three terms, and serializes as `rock AND and AND roll`. Terms keep their text: `007`, `12.50`, and `true` are strings. Unicode, hyphens, and punctuation are allowed in words, including `José`, `t-shirt`, and `john@example.com`.

## Compare field values

Write the field name, a colon, and its value. Add a comparison operator after the colon when needed:

| Query | Meaning |
| --- | --- |
| `price:10` | Equal to 10. |
| `price:!=10` | Not equal to 10. |
| `price:>10` or `price:>=10` | Greater than 10, or at least 10. |
| `price:<10` or `price:<=10` | Less than 10, or at most 10. |
| `title:AND` | The literal value `AND`. |

Comparators also work without a colon, such as `price>10`. A negative value must touch its minus: `temperature:>-5` is valid and `temperature:>- 5` is not.

Field rules cast values and decide whether they are valid. Without a type, unquoted booleans and ordinary numbers are inferred; quoted values and identifiers such as `007` remain strings. See [value inference](field-validation-and-value-types#value-inference).

## Match a list

Separate values with commas after the colon:

```text
status:active,draft
status:!=sold,expired
location:37.7749,-122.4194
```

An equal list matches any value. A not-equal list excludes every listed value, so `status:!=sold,expired` means `status:!=sold AND status:!=expired`.

On a field with several stored values, `tag:a,b` matches either tag. Repeating the field, `tag:a tag:b`, requires both. A literal list stays one `InNode`; a list containing a wildcard expands into comparisons and patterns.

## Match a range

Write an inclusive range as `field:[a TO b]` or `field:a..b`:

```text
price:[10 TO 50]
price:-5.5..20
created:2024-01-01T10:00..2024-01-01T12:00
price:*..20
price:10..*
```

`TO` must be uppercase and is a separator only inside brackets. An unquoted `*` opens that end of the range and skips casting and validation. Open ends are null in the syntax tree and array output. A quoted `"*"` is a literal bound and follows the field's rules.

Compact ranges need one `..`, two non-empty ends, and no slash or adjacent dot. `wait...`, `a...b`, `a..b..c`, and `src/../lib` remain literal text, as does `"1..2"`. A range cannot be a list item or follow an ordering operator: `price:1..5,10` and `price:>1..5` are invalid. Wildcard patterns cannot be range bounds. The string adapter writes both range forms with brackets.

## Check whether a value exists

`category:*` checks whether a stored value exists. Use `NOT category:*` for a missing value. `category:!=*`, `category:>*`, and a standalone `*` are invalid.

## Quote literal text

Single or double quotes preserve spaces and syntax characters:

```text
title:"Cold Brew"
url:"https://example.com"
time:'12:30'
title:"co*fee"
```

Quote values containing parentheses, brackets, colons, commas, comparison punctuation, or whitespace. Quote a standalone term starting with `-` to keep the hyphen literal.

Inside quotes, `\\`, `\"`, and `\'` are unescaped; other backslashes stay. A missing closing quote is an error. ISO datetimes may be unquoted, with or without a timezone, but other values containing colons need quotes.

Terms and comparisons expose `isQuoted()`. Lists expose `isQuoted($index)` for each item. The array adapter includes these flags under `quoted`, and the string adapter preserves explicit quotes. Typed fields still cast quoted numbers and booleans.

## Use wildcards

An unquoted `*` inside a term or field value is a wildcard:

| Query | Literal segments |
| --- | --- |
| `coff*` | `['coff', '']` |
| `title:co*fee` | `['co', 'fee']` |
| `title:a**b` | `['a', 'b']` |
| `title:"a b"*` | `['a b', '']` |

Runs of stars collapse. A quoted string followed immediately by `*` is a prefix pattern; stars inside the quotes stay literal. Patterns have their own `WildcardNode` rather than a scalar comparison value.

Leading wildcards are rejected by default. Enable them on a field with `allowLeadingWildcards()`, or on the parser for bare terms and unconfigured fields. The parser's option does not override a configured field. `minWildcardLength()` sets the minimum number of literal Unicode characters. These rules run before aliases, casts, or validators, including every pattern in a list.

Patterns support equality and inequality. `name:jo*,ann` expands with OR; `name:!=jo*,ann` expands with AND. Every expanded item counts toward the clause limit. Patterns are validated using their original text, stars included. Ordering comparisons such as `name:>jo*` fail with `wildcard.invalid_pattern`.

A `words()` field accepts only trailing word prefixes, such as `coff*` or `"coffee ma"*`. See [text match modes](field-validation-and-value-types#text-match-modes).

## Compare text

Text comparisons are case-insensitive, including exact, prefix, substring, word, and wildcard matches. The adapter implements this with its backend's comparison rules. Field names and alias tokens remain case-sensitive.

Quotes force exact match metadata for a comparison or list item. For prefix and contains fields, the clause compiler uses equality. For `words()` fields, it uses an ordered phrase. Quoting does not make a comparison case-sensitive.

## Include missing values in complements

`NOT x`, `-x`, and `field:!=value` mean set complement. A field marked `nullable()` may have a null column, an absent key, or a missing relation. Its complement includes those records: `NOT price:>10` includes a missing price as well as prices at most 10.

On a multi-valued field, `tag:!=a` means no element equals `a`, including records without elements. `tag:!=a,b` excludes a record with either value, even if it also has other elements.

Adapters must apply those rules explicitly. The [negation transformer](../advanced-usage/custom-adapters-and-visitors#negation-normal-form) and [clause compiler](../advanced-usage/compiling-clauses) preserve the information they need.
