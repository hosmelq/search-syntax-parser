---
title: "Compiling Clauses"
description: "Convert validated query leaves into stored values, complemented operators, text patterns, and word metadata for search adapters."
weight: 5
---

Use `Query\ClauseCompiler` to prepare filter leaves and free-text candidates for a backend adapter. It converts values and operators without a database or framework dependency.

Your adapter traverses boolean groups, selects term fields, maps trusted identifiers, binds values, and executes the search. It also implements case-insensitive comparisons and missing-value behavior.

## Compile filter leaves

Pass a field leaf directly from `parse()` to `compile()`. The compiler normalizes leaf inequalities and combines them with the optional `negated` argument:

```php
use HosmelQ\SearchSyntaxParser\Query\ClauseCompiler;
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\Field;

$field = Field::decimal('price', 2)->scale(100)->nullable();
$node = SearchParser::query('price:!=10.50')->allowedFields([$field])->parse();
$clauses = new ClauseCompiler()->compile($field, $node);
$clause = $clauses[0];
```

The clause uses `Operator::NotEqual`, value `1050`, position `0`, and `negated: true`. An adapter includes missing prices because the field is nullable. A plain SQL `<>` predicate would omit them.

Likewise, `status:!=sold,expired` produces `Operator::NotIn`, and `title:!=co*` produces `Operator::NotLike`. Passing `negated: true` cancels the inequality and produces a positive clause.

For a boolean tree, use `NegationNormalFormTransformer` to move NOT onto leaves before traversal. Unwrap each `UnaryOperatorNode` and pass its operand with `negated: true`; `compile()` does not traverse groups.

`compile()` accepts `ComparisonNode`, `ExistsNode`, `InNode`, `RangeNode`, or a field `WildcardNode` and returns a list of readonly `Clause` objects. Ordinary leaves produce one clause. Lists using prefix, contains, or word matching expand into one clause per item. Fully quoted prefix and contains lists remain exact membership clauses.

Combine expanded alternatives with OR when their `Clause::negated` flag is false and AND when it is true. This flag includes both leaf inequality and surrounding negation. Keep that group within the surrounding boolean tree.

## Read a clause

| Property | Meaning |
| --- | --- |
| `field` | External field name, including complete dynamic keys. |
| `operator` | An `Operator`, complemented when negated. |
| `value` | Stored scalar, scalar list, `Bounds`, or null for existence. |
| `position` | Leaf offset in Unicode characters, or null without a source span. |
| `negated` | Whether set-complement semantics apply. |
| `text` | Whether a scalar string field compares stored string values. |
| `fullText` | A `Support\FullText` value for word matching, or null. |

`Operator::complement()` handles comparisons, membership, ranges, existence, and LIKE in both directions. The separate `negated` flag lets an adapter include missing values or exclude every matching element of a multi-valued target.

`Bounds` exposes nullable scalar `from` and `to` properties. Written bounds are inclusive. Date values remain strings; resolve them with [DateValue](../basic-usage/field-validation-and-value-types#resolve-date-intervals) when the backend needs half-open intervals.

Compilation applies `toStorage()` without changing the AST. It targets scalar field schemas, including schemas mapped to multi-valued backend targets. Array fields with positional rules need the index-aware conversion described in [array fields](../basic-usage/array-fields#preserve-written-positions).

## Compile text matches

Unquoted prefix and contains equality comparisons become LIKE clauses. Quotes force equality for these modes. Explicit wildcards become patterns from their literal segments. Ordering comparisons and ranges retain their operators.

Patterns use `!` as the escape character. Bind the pattern and configure the backend to use the same escape.

A `words()` field supplies `fullText` metadata for equality, list alternatives, and wildcard prefixes:

```php
use HosmelQ\SearchSyntaxParser\Query\ClauseCompiler;
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\Field;

$field = Field::string('text')->words();
$node = SearchParser::query('text:"coffee ceramic",espresso')->allowedFields([$field])->parse();
$clauses = new ClauseCompiler()->compile($field, $node);
```

The first clause has words `['coffee', 'ceramic']` and `phrase: true`. The second has `['espresso']` and `phrase: false`.

`FullText` splits at punctuation and whitespace, keeping Unicode letters, numbers, and combining marks. `phrase` means ordered adjacent words; `prefix` applies to the final word. A quoted prefix such as `"fresh coff"*` is a word conjunction with a prefix on `coff`, with `phrase: false`.

Punctuation-only text produces an empty word list. The adapter supplies its no-match behavior, SQL strings, and backend language configuration.

## Compile free-text terms

Call `term()` once for each configured term field. Pass a bare `TermNode` or `WildcardNode` from the normalized tree:

```php
use HosmelQ\SearchSyntaxParser\Query\ClauseCompiler;
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\Field;

$compiler = new ClauseCompiler();
$node = SearchParser::query('coffee')->parse();
$title = $compiler->term(Field::string('title'), $node);
$id = $compiler->term(Field::integer('id'), $node);
```

`$title` is a LIKE clause with value `%coffee%`. `$id` is null because `coffee` is not an integer.

A term field participates only if it allows equality and accepts the value through casting and validation. Wildcards also require a scalar string field and must satisfy its wildcard permissions and minimum length. An ineligible field returns null; configuration and callback failures propagate.

Ordinary string terms search substrings regardless of exact or prefix mode. Typed terms use equality after conversion. Word fields retain phrase and prefix metadata. `ignore()` applies to named clauses and does not suppress bare terms.

Pass `negated: true` for a negated term. Combine eligible fields with OR when positive and AND when negated. With no eligible fields, a positive term matches nothing and its negation matches everything. Directives and sorting remain separate parser results.
