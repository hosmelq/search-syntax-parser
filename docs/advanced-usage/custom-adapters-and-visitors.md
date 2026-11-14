---
title: "Custom adapters and visitors"
description: "Implement custom output formats through AST visitors and translate explicit wildcard segments into escaped LIKE patterns."
weight: 3
---

Implement `QueryAdapterInterface::build(NodeInterface $ast): mixed` to produce an output format. Register a creator with `extend()`, then select it by name. Creators receive no arguments; capture the parser when your adapter needs field mappings.

```php
use HosmelQ\SearchSyntaxParser\Adapter\QueryAdapterInterface;
use HosmelQ\SearchSyntaxParser\Adapter\StringAdapter;
use HosmelQ\SearchSyntaxParser\AST\Node\NodeInterface;
use HosmelQ\SearchSyntaxParser\SearchParser;

final readonly class QueryEnvelopeAdapter implements QueryAdapterInterface
{
    #[\Override]
    public function build(NodeInterface $ast): array
    {
        return ['query' => new StringAdapter()->build($ast)];
    }
}

$parser = SearchParser::query('coffee tea');
$parser->extend('envelope', fn (): QueryEnvelopeAdapter => new QueryEnvelopeAdapter());

assert($parser->build('envelope') === ['query' => 'coffee AND tea']);
```

## Visiting nodes

Each node implements `getType()`, `getSpan()`, and `accept(VisitorInterface $visitor)`. A visitor implements `visitBinaryOperator()`, `visitComparison()`, `visitExists()`, `visitIn()`, `visitRange()`, `visitTerm()`, `visitUnaryOperator()`, and `visitWildcard()`. Binary nodes expose left/right children; unary nodes expose an operand. Call `accept()` on children to traverse them.

AST type names are `BinaryOperator`, `Comparison`, `Exists`, `In`, `Range`, `Term`, `UnaryOperator`, and `Wildcard`. All field nodes retain external names. `WildcardNode::getField()` can be null for a bare term; `getOperator()` returns `=` or `!=`; `getSegments()` returns literal segments, with runs of unquoted asterisks collapsed.

`AbstractAdapter` supplies `getInternalFieldName()` when constructed with a parser. `ArrayAdapter` is also extensible, so a visitor can override one representation while inheriting the remaining node handling.

## Wildcards to LIKE

This adapter converts wildcard leaves into LIKE instructions and leaves other array output intact. It escapes literal `%`, `_`, and `!`, then joins segments with `%`. Apply the resulting pattern as a bound parameter with `ESCAPE '!'`; resolve the returned field through trusted database identifiers. Bare patterns target the configured title field in this example.

```php
use HosmelQ\SearchSyntaxParser\Adapter\ArrayAdapter;
use HosmelQ\SearchSyntaxParser\AST\Node\WildcardNode;
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;

final readonly class LikeAdapter extends ArrayAdapter
{
    #[\Override]
    public function visitWildcard(WildcardNode $node): array
    {
        $segments = array_map(
            static fn (string $segment): string => strtr($segment, ['!' => '!!', '%' => '!%', '_' => '!_']),
            $node->getSegments(),
        );

        return [
            'field' => $this->getInternalFieldName($node->getField() ?? 'title'),
            'operator' => $node->getOperator() === '!=' ? 'NOT LIKE' : 'LIKE',
            'pattern' => implode('%', $segments),
        ];
    }
}

$parser = SearchParser::query('title:co*fee')->allowedFields([
    AllowedField::string('title', 'product_title'),
]);
$parser->extend('like', fn (): LikeAdapter => new LikeAdapter($parser));

assert($parser->build('like') === [
    'field' => 'product_title',
    'operator' => 'LIKE',
    'pattern' => 'co%fee',
]);
```

A quoted asterisk is literal text inside its segment and is not converted to `%`. Keep field allowlists and bound values in your backend integration; parsing alone does not execute or authorize a database query.

## Source spans and literal values

`getSpan()` returns a readonly `Span` with public `offset` and `length` integers, measured in Unicode code points from the exact query string. It uses the same coordinates as `SearchSyntaxException::getPosition()`. Quotes, a trailing wildcard, and closing range brackets are included. Binary nodes cover both operands, including consumed group delimiters; unary nodes start at `NOT` or `-` and include their operand's closing parenthesis. A group's inner node covers only its inner content. Each generated leaf and join in an expanded wildcard list covers the whole field clause.

Every node constructor accepts an optional trailing `span: null|Span` argument; direct constructions default to null. `TermNode` also accepts `quoted: bool = false` before `span`, and exposes `isQuoted()`. Default-generated nodes and joins have null spans, so consumers can distinguish them from typed clauses. Neither built-in adapter emits spans. `RangeNode::getFrom()` and `getTo()` return null for open bounds and scalar values for literal bounds, including a quoted `*`.

```php
use HosmelQ\SearchSyntaxParser\SearchParser;

$node = SearchParser::query('  title:"Café"')->allowAnyField()->parse();

assert($node->getSpan()->offset === 2);
assert($node->getSpan()->length === 12);
assert(SearchParser::query('"Cold Brew"')->parse()->isQuoted());
assert(SearchParser::query('price:[* TO 10]')->allowAnyField()->parse()->getFrom() === null);
```

Adapters can report unsupported operations with a subclass of `SearchSyntaxException`. Its public constructor accepts the message, an `ErrorReason`, an array of parameters, and an optional character position.

```php
use HosmelQ\SearchSyntaxParser\Exception\ErrorReason;
use HosmelQ\SearchSyntaxParser\Exception\SearchSyntaxException;

final class UnsupportedQueryException extends SearchSyntaxException {}

$exception = new UnsupportedQueryException(
    'Ranges are not supported by this adapter.',
    ErrorReason::Unsupported,
    ['field' => 'price'],
    0,
);

assert($exception->getReason() === ErrorReason::Unsupported);
assert($exception->getParameters() === ['field' => 'price']);
```
