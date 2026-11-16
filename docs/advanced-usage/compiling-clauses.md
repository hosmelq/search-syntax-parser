---
title: "Compiling Clauses"
description: "Convert validated query leaves into stored values, complemented operators, text patterns, and word metadata for search adapters."
weight: 5
---

Use `SearchParser::compile()` to prepare a backend-independent clause tree. It resolves authorization, term targets, storage values, text modes, and negation before an adapter translates the clauses into its backend.

## Compile a query

Configure fields and term targets on the parser:

```php
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\Field;

$parser = SearchParser::query('coffee price:!=10.50')->allowedFields([
    Field::string('title'),
    Field::decimal('price', 2)->scale(100)->nullable(),
])->terms(['title']);

$tree = $parser->compile();
```

The root is an AND group. Its first clause searches `title` with `TextPattern(['', 'coffee', ''])`; its second uses `Operator::NotEqual`, value `1050`, and `negated: true`. A database adapter includes missing prices because the field is nullable.

`compile()` returns null when `parse()` does. Otherwise the root is always a `Query\ClauseGroup`, wrapping a single clause in an AND group. Directives and sorting remain available through `getDirectiveClauses()` and `getSort()`.

## Traverse groups

`ClauseGroup` is readonly and exposes:

| Property | Meaning |
| --- | --- |
| `and` | True for AND, false for OR. |
| `children` | An ordered list of `Clause` or `ClauseGroup` objects. |
| `field` | The external field name for a group expanded from one field leaf; null otherwise. |

Chains of the same boolean operator flatten into one group. A field leaf that expands into several clauses stays grouped, with OR when positive and AND when negated. Its `field` lets an adapter resolve the alternatives against one target, such as a relation. Never flatten that leaf group into its parent.

An empty AND group matches everything; an empty OR group matches nothing. Emit the corresponding backend predicate even when the group has no children.

There are no NOT nodes. Compilation applies negation normal form and carries set-complement semantics on each clause. Your adapter maps trusted field names, binds values, implements backend comparisons and missing-value behavior, and executes the search.

Look up a clause's definition with `getAllowedField($clause->field)`. Names accepted by `allowAnyField()` have no definition and use value inference. `getInternalFieldName()` maps a public name, including a dynamic key, to its internal name. `getValueType()`, `isDate()`, `isCalendarDate()`, `getDateOptions()`, and `hasCustomDateFormat()` describe a field's values.

`getAllowedValues()` exposes scalar membership or enum backing values, and `comparisonType($value)` selects `number`, `boolean`, or `string` after storage conversion. `keyOf($name)` extracts a dynamic suffix. `getConfiguredFields()` returns every definition, including denied fields, for an adapter's own configuration checks.

## Read a clause

| Property | Meaning |
| --- | --- |
| `field` | External field name, including complete dynamic keys. |
| `operator` | An `Operator`, complemented when negated. |
| `value` | Stored scalar, scalar list, `Bounds`, `TextPattern`, or null for existence. |
| `position` | Leaf offset in Unicode characters, or null without a source span. |
| `negated` | Whether set-complement semantics apply. |
| `text` | Whether a scalar string field compares stored string values. |
| `fullText` | A `Support\FullText` value for word matching, or null. |

`Clause::positive()` returns a negated clause with its operator complemented and `negated: false`, preserving the other properties; an already positive clause is returned unchanged.

`Operator::complement()` handles comparisons, membership, ranges, existence, and LIKE in both directions. The separate `negated` flag lets an adapter include missing values or exclude every matching element of a multi-valued target.

`Query\Bounds` exposes nullable scalar `from` and `to` properties. Written bounds are inclusive. Two open bounds compile to `Exists`, or `NotExists` when negated, with a null value. Date values remain strings until an adapter resolves their intervals.

Compilation applies `toStorage()` without changing the AST. Array list items use their [written positions](../basic-usage/array-fields#preserve-written-positions), including after ignored items are removed, so positional rules match array output.

## Compile text matches

Unquoted prefix and contains equality comparisons become LIKE clauses. Quotes force equality for these modes. Explicit wildcards become patterns from their literal segments. Ordering comparisons and ranges retain their operators.

LIKE clauses carry a readonly `Query\TextPattern` with literal `segments`; wildcards sit between segments. For example, `co*fee` has `['co', 'fee']`, and a contains search has `['', 'coffee', '']`. In-memory and search-engine adapters can use the segments directly. SQL adapters bind `$clause->value->toLike()`, which escapes `%`, `_`, and `!` with `!`; pass another escape to `toLike()` and use the same SQL ESCAPE character.

A `words()` field supplies `fullText` metadata for equality, list alternatives, and wildcard prefixes:

```php
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\Field;

$tree = SearchParser::query('text:"coffee ceramic",espresso')->allowedFields([
    Field::string('text')->words(),
])->compile();

$clauses = $tree->children;
```

The first clause has words `['coffee', 'ceramic']` and `phrase: true`. The second has `['espresso']` and `phrase: false`.

`FullText` splits at punctuation and whitespace, keeping Unicode letters, numbers, and combining marks. `phrase` means ordered adjacent words; `prefix` applies to the final word. A quoted prefix such as `"fresh coff"*` is a word conjunction with a prefix on `coff`, with `phrase: false`.

Text without words compiles to an empty OR group when positive and an empty AND group when negated, including individual list alternatives and free-text terms. The adapter supplies SQL strings and backend language configuration.

## Compile free-text terms

Set `terms()` to the exact public field names to search. `getTermFields()` returns the authorized targets in the order given to `terms()`. Unknown names, dynamic patterns, directives, and date fields or shared date items throw `InvalidArgumentException`, even when denied or unused by the query. `compile()` always validates this configuration.

A term field participates only if it allows equality and accepts the value through casting and validation. Wildcards also require a scalar string field and must satisfy its wildcard permissions and minimum length. Configuration and callback failures propagate.

Ordinary string terms search substrings regardless of exact or prefix mode. Typed terms use equality after conversion. Word fields retain phrase and prefix metadata. `ignore()` applies to named clauses and does not suppress bare terms.

Eligible clauses form an OR group when positive and an AND group when negated, with `field: null`. One clause collapses to that clause; no eligible target produces an empty group. Thus a term with no targets matches nothing, and its negation matches everything.

## Resolve date intervals

Handle `Exists` and `NotExists` directly for date fields. Use `DateBounds::forClause()` for other format-less date clauses. It returns the half-open intervals of the clause's positive form, including scalar comparisons, lists, and ranges. A negated clause first uses its complemented operator; the adapter applies negation to the resulting intervals.

```php
use DateTimeImmutable;
use DateTimeZone;
use HosmelQ\SearchSyntaxParser\Date\DateBounds;
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\Field;

$tree = SearchParser::query('created:today')->allowedFields([
    Field::date('created'),
])->compile();
$intervals = DateBounds::forClause(
    $tree->children[0],
    new DateTimeZone('America/New_York'),
    new DateTimeImmutable('2024-03-15T12:00:00Z'),
);
$stored = $intervals[0]->toStorage(new DateTimeZone('UTC'), 'Y-m-d H:i:s');
```

`$stored->from` is `2024-03-15 04:00:00`, inclusive, and `$stored->to` is `2024-03-16 04:00:00`, exclusive. A date rejected under the supplied clock or zone raises `InvalidFieldValueException` at the clause's position.

`toStorage()` converts zones, rounds endpoints upward to the format's fractional precision, and clamps to years 0001–9999. Escaped `u` and `v` characters do not define precision. It returns `Query\Bounds` with formatted endpoints, or null if no stored value can match. Two null endpoints cover every present value. Use chronologically sortable storage formats, such as `Y-m-d H:i:s.u`.

`DateValue::parse()`, `DateValue::bounds()`, and `DateBounds::range()` remain available for individual date expressions; see [date intervals](../basic-usage/field-validation-and-value-types#resolve-date-intervals).

## Compile individual leaves

`Query\ClauseCompiler::compile($field, $node, $negated)` accepts a field comparison, list, range, existence, or wildcard node and returns a list of clauses or empty groups. `term($field, $node, $negated)` accepts a bare term or wildcard and returns one eligible clause, an empty group, or null; pass it each field from `getTermFields()`. Use these lower-level methods when working with individual AST nodes; backend adapters should consume `SearchParser::compile()` for the complete query semantics.
