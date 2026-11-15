---
title: "Custom Adapters and Visitors"
description: "Create output formats with AST visitors, transform query trees, and preserve source positions, quoting, and default metadata."
weight: 3
---

Implement `QueryAdapterInterface` to produce another output format. Register a creator with `extend()` and select it by name:

```php
use HosmelQ\SearchSyntaxParser\Adapter\QueryAdapterInterface;
use HosmelQ\SearchSyntaxParser\Adapter\StringAdapter;
use HosmelQ\SearchSyntaxParser\AST\Node\NodeInterface;
use HosmelQ\SearchSyntaxParser\SearchParser;

final readonly class QueryEnvelopeAdapter implements QueryAdapterInterface
{
    public function build(NodeInterface $ast): array
    {
        return ['query' => new StringAdapter()->build($ast)];
    }
}

$parser = SearchParser::query('coffee tea');
$parser->extend('envelope', fn (): QueryEnvelopeAdapter => new QueryEnvelopeAdapter());

$result = $parser->build('envelope');
```

The result is `['query' => 'coffee AND tea']`. Creators receive no arguments; capture the parser if the adapter needs field metadata.

Custom adapters receive a non-null tree. When parsing returns null, `build()` resolves the selected adapter but does not call it. Unknown adapter names still fail.

## Visit nodes

Every node implements `getType()`, `getSpan()`, and `accept(VisitorInterface $visitor)`. Implement these visitor methods to handle each node:

| Method | Node type |
| --- | --- |
| `visitBinaryOperator()` | AND or OR with left and right children. |
| `visitComparison()` | A field, operator, and scalar value. |
| `visitExists()` | An existence check. |
| `visitIn()` | A field and list of values. |
| `visitRange()` | A field and inclusive bounds. |
| `visitTerm()` | A bare text term. |
| `visitUnaryOperator()` | NOT with an operand. |
| `visitWildcard()` | Literal segments separated by wildcard operators. |

Call `accept()` on children to traverse them. Field nodes retain external names, including complete dynamic keys. A wildcard's field is null for a bare term. Compact and bracket ranges share `RangeNode`.

`AbstractAdapter` provides `getInternalFieldName()` when constructed with a parser. Use `getAllowedField()` to retrieve field metadata, `getTextMatch()` for effective matching, and `toStorage()` for backend values. The [clause compiler](compiling-clauses) handles these value and operator conversions for scalar field schemas.

`ArrayAdapter` is extensible, so you can override one visitor method while keeping the rest of its representation. Adapters must implement case-insensitive text comparisons and map accepted fields to trusted backend identifiers.

## Rewrite a tree

Extend `NodeTransformer` to replace selected nodes. Its default implementation recursively transforms children and returns an equivalent tree:

```php
use HosmelQ\SearchSyntaxParser\Adapter\StringAdapter;
use HosmelQ\SearchSyntaxParser\AST\Node\NodeInterface;
use HosmelQ\SearchSyntaxParser\AST\Node\TermNode;
use HosmelQ\SearchSyntaxParser\AST\Visitor\NodeTransformer;
use HosmelQ\SearchSyntaxParser\SearchParser;

final class UppercaseTerms extends NodeTransformer
{
    public function visitTerm(TermNode $node): NodeInterface
    {
        return new TermNode(mb_strtoupper($node->getValue()), $node->isQuoted(), $node->getSpan());
    }
}

$tree = SearchParser::query('coffee OR NOT tea')->parse();
$rewritten = new UppercaseTerms()->transform($tree);
$text = new StringAdapter()->build($rewritten);
```

The result is `COFFEE OR NOT TEA`. Unchanged readonly leaves are shared; rebuilt groups retain their spans.

When replacing nodes, preserve the metadata they expose:

- Pass `isDefault()` to replacement comparisons, lists, exists checks, and unary nodes.
- Preserve `isQuoted()` on terms and comparisons, and each item's quote flag on lists.
- Pass `InNode::getPositions()` so array storage conversion keeps the written item positions.

## Negation normal form

Use `NegationNormalFormTransformer` before compiling backend clauses. It moves NOT directly around leaves, applies De Morgan's laws, and removes double negation. Not-equal comparisons, lists, and wildcards become NOT around their positive form.

```php
use HosmelQ\SearchSyntaxParser\Adapter\StringAdapter;
use HosmelQ\SearchSyntaxParser\AST\Visitor\NegationNormalFormTransformer;
use HosmelQ\SearchSyntaxParser\SearchParser;

$tree = SearchParser::query('NOT (price:>10 OR status:!=open)')
    ->allowedFields(['price', 'status'])
    ->parse();

$normalized = new NegationNormalFormTransformer()->transform($tree);
$text = new StringAdapter()->build($normalized);
```

The result is `NOT price:>10 AND status:open`. `parse()` does not run this transformer automatically.

Ordering comparisons retain their operators. `NOT price:>10` includes missing prices for a nullable field, so replacing it with a plain `price:<=10` would change the result. For a multi-valued field, negation means no element matches, including records with no elements.

Adapters must apply [set-complement semantics](../basic-usage/query-syntax#include-missing-values-in-complements), including missing columns, keys, or relations. SQL's ordinary NOT or NOT LIKE is not sufficient for nullable values.

## Build LIKE patterns

`Support\LikePattern` escapes literal SQL pattern characters and converts wildcard segments:

```php
use HosmelQ\SearchSyntaxParser\AST\Node\WildcardNode;
use HosmelQ\SearchSyntaxParser\Support\LikePattern;
use HosmelQ\SearchSyntaxParser\Validation\TextMatch;

$wildcard = new WildcardNode('title', '=', ['co', 'fee']);
$pattern = LikePattern::fromWildcard($wildcard);
$prefix = LikePattern::forMatch('50%', TextMatch::Prefix);
```

`$pattern` is `co%fee`, and `$prefix` is `50!%%`. Bind these values and use `LIKE ? ESCAPE '!'`. The helper escapes literal `%`, `_`, and `!`; quoted asterisks remain literal text.

`escape()` protects a literal string. `forMatch()` supports exact, prefix, and contains modes. Whole-word matching needs a backend operation, so `TextMatch::Words` throws `InvalidArgumentException`.

All three methods accept a custom escape character. It must be one Unicode character other than `%` or `_`, and the backend must use the same character in its ESCAPE clause.

## Read source positions

`getSpan()` returns a readonly `Span` with `offset` and `length`, measured in Unicode code points from the original query. These are the same coordinates used by exception positions:

```php
use HosmelQ\SearchSyntaxParser\SearchParser;

$node = SearchParser::query('  title:"Café"')->allowedFields(['title'])->parse();
$span = $node->getSpan();
```

The offset is `2` and the length is `12`. Quotes, trailing wildcards, and closing range brackets are included. Binary nodes cover their operands and consumed group delimiters; unary nodes start at NOT or minus. A group's inner node covers its inner content. Expanded wildcard-list nodes cover the whole field clause.

Node constructors accept an optional span. Programmatic and default-generated nodes can have null spans, so use `isDefault()` to identify defaults. Neither built-in adapter emits spans. Compiled clauses retain the leaf's offset as `position`.

## Report adapter errors

Extend `SearchSyntaxException` when an adapter needs a structured query error:

```php
use HosmelQ\SearchSyntaxParser\Exception\ErrorReason;
use HosmelQ\SearchSyntaxParser\Exception\SearchSyntaxException;

final class UnsupportedQueryException extends SearchSyntaxException {}

$exception = new UnsupportedQueryException(
    'Ranges are not supported by this adapter.',
    ErrorReason::OperatorNotAllowed,
    ['field' => 'price', 'clause' => 'ranges'],
    0,
);
```

The constructor accepts a message, reason, parameters, and optional source position. Use the reason and parameters for application translations; see [error handling](../basic-usage/error-handling).
