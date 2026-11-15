---
title: "Custom adapters and visitors"
description: "Implement custom output formats through AST visitors and translate explicit wildcard segments into escaped LIKE patterns."
weight: 3
---

Implement `QueryAdapterInterface::build(NodeInterface $ast): mixed` to produce an output format. Register a creator with `extend()`, then select it by name. When parsing returns `null`, `SearchParser::build()` still resolves the adapter, so an unknown name fails, but returns `null` without calling it; direct adapter calls require a non-null tree. Creators receive no arguments; capture the parser when your adapter needs field mappings.

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

Dynamic field nodes retain their full typed name, such as `meta.color`. `getAllowedField()` resolves its pattern metadata, while internal mappings replace the pattern suffix, for example `meta.*` to `attributes.*`. Adapters must map accepted keys to trusted backend identifiers. Compact and bracket syntax both produce `RangeNode`; adapters need no separate compact-range handler.

`AbstractAdapter` supplies `getInternalFieldName()` when constructed with a parser. Get a configured field through `SearchParser::getAllowedField($node->getField())`; its `getTextMatch($node, $index)` resolves effective text matching, and `toStorage($value)` converts cast values for your backend. Apply conversions to comparison values, list items, and non-null range bounds, and implement case-insensitive text comparisons. `ArrayAdapter` is also extensible, so a visitor can override one representation while inheriting the remaining node handling.

## Rewriting nodes

Extend `NodeTransformer` to override a single `visit*()` method instead of implementing the whole visitor interface. `transform(NodeInterface $node): NodeInterface` recursively rewrites children and returns an equivalent tree by default. Unchanged readonly leaves are shared; rebuilt binary and unary nodes retain their spans, and default-origin flags survive.

```php
use HosmelQ\SearchSyntaxParser\Adapter\StringAdapter;
use HosmelQ\SearchSyntaxParser\AST\Node\NodeInterface;
use HosmelQ\SearchSyntaxParser\AST\Node\TermNode;
use HosmelQ\SearchSyntaxParser\AST\Visitor\NodeTransformer;
use HosmelQ\SearchSyntaxParser\SearchParser;

final class UppercaseTerms extends NodeTransformer
{
    #[\Override]
    public function visitTerm(TermNode $node): NodeInterface
    {
        return new TermNode(mb_strtoupper($node->getValue()), $node->isQuoted(), $node->getSpan());
    }
}

$ast = SearchParser::query('coffee OR NOT tea')->parse();
$rewritten = new UppercaseTerms()->transform($ast);

assert(new StringAdapter()->build($rewritten) === 'COFFEE OR NOT TEA');
```

When replacing a comparison, exists, list, or unary node in a custom override, pass its `isDefault()` value as the new node's `default` argument. Comparisons and lists also accept `quoted` as the last optional argument: a boolean for comparisons or a list of booleans aligned with list values. Preserve these flags with `ComparisonNode::isQuoted()` or `InNode::isQuoted($index)` so quoted values retain exact text matching. Defaults are unquoted.

## Negation normal form

`NegationNormalFormTransformer` extends `NodeTransformer` and is opt-in. `parse()` never runs it automatically. The result has `NOT` only directly around a leaf: a comparison, list, range, exists check, wildcard, or term. Leaves have positive operators, so `field:!=v`, not-equal lists, and not-equal wildcards become `NOT` around their `=` form. De Morgan's laws push negation through AND/OR, and double negations disappear. Comparisons such as `>`, `>=`, `<`, and `<=` keep their operators.

```php
use HosmelQ\SearchSyntaxParser\Adapter\StringAdapter;
use HosmelQ\SearchSyntaxParser\AST\Visitor\NegationNormalFormTransformer;
use HosmelQ\SearchSyntaxParser\SearchParser;

$ast = SearchParser::query('NOT (price:>10 OR status:!=open)')->allowAnyField()->parse();
$normalized = new NegationNormalFormTransformer()->transform($ast);

assert(new StringAdapter()->build($normalized) === 'NOT price:>10 AND status:open');
```

Negation means set complement. A field declared `nullable()` can have a missing stored value, and its complement must include that value. `NOT price:>10` therefore stays `NOT price:>10`; changing it to `price:<=10` would exclude missing prices. For multi-valued fields, `tag:!=a` means no element equals `a`, including records with no elements.

Adapters must implement that contract explicitly. A SQL adapter needs to include `IS NULL` or absent relations where appropriate instead of relying on SQL's three-valued logic or a plain `NOT LIKE`. See [query semantics](../basic-usage/query-syntax#complements-and-missing-values).

## Wildcards to LIKE

This adapter converts wildcard leaves into LIKE instructions and leaves other array output intact. It uses the framework-independent `LikePattern` helper to escape literal `%`, `_`, and `!`, then join segments with `%`. Apply the resulting pattern as a bound parameter with `LIKE ? ESCAPE '!'`; resolve the returned field through trusted database identifiers. Bare patterns target the configured title field in this example.

```php
use HosmelQ\SearchSyntaxParser\Adapter\ArrayAdapter;
use HosmelQ\SearchSyntaxParser\AST\Node\WildcardNode;
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Support\LikePattern;
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;

final readonly class LikeAdapter extends ArrayAdapter
{
    #[\Override]
    public function visitWildcard(WildcardNode $node): array
    {
        return [
            'field' => $this->getInternalFieldName($node->getField() ?? 'title'),
            'operator' => $node->getOperator() === '!=' ? 'NOT LIKE' : 'LIKE',
            'pattern' => LikePattern::fromWildcard($node),
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

`LikePattern::escape(string $value, string $escape = '!')` escapes literal SQL pattern characters. `fromWildcard(WildcardNode $node, string $escape = '!')` joins the escaped literal segments with `%`. `forMatch(string $value, TextMatch $match, string $escape = '!')` returns escaped text for `Exact`, `value%` for `Prefix`, and `%value%` for `Contains`. It throws `InvalidArgumentException` for `Words`, since LIKE cannot express whole-word matching. The escape must be a single Unicode character other than `%` and `_`; an invalid escape throws `InvalidArgumentException`. Emit the same escape in the backend's `ESCAPE` clause.

```php
use HosmelQ\SearchSyntaxParser\Support\LikePattern;
use HosmelQ\SearchSyntaxParser\Validation\TextMatch;

assert(LikePattern::escape('50%_!') === '50!%!_!!');
assert(LikePattern::forMatch('50%', TextMatch::Exact) === '50!%');
assert(LikePattern::forMatch('50%', TextMatch::Prefix) === '50!%%');
assert(LikePattern::forMatch('50%', TextMatch::Contains) === '%50!%%');
assert(LikePattern::escape('a_b#', '#') === 'a#_b##');
```

A quoted asterisk is literal text inside its segment and is not converted to `%`. Keep field allowlists and bound values in your backend integration; parsing alone does not execute or authorize a database query.

## Source spans and literal values

`getSpan()` returns a readonly `Span` with public `offset` and `length` integers, measured in Unicode code points from the exact query string. It uses the same coordinates as `SearchSyntaxException::getPosition()`. Quotes, a trailing wildcard, and closing range brackets are included. Binary nodes cover both operands, including consumed group delimiters; unary nodes start at `NOT` or `-` and include their operand's closing parenthesis. A group's inner node covers only its inner content. Each generated leaf and join in an expanded wildcard list covers the whole field clause.

Every node constructor accepts an optional `span: null|Span` argument; direct constructions default to null. `TermNode` also accepts `quoted: bool = false` before `span`, and exposes `isQuoted()`. Default-generated nodes and joins also have null spans. Use `isDefault()` on `ComparisonNode`, `ExistsNode`, `InNode`, and `UnaryOperatorNode` to identify default-origin clauses, because hand-built trees may also be spanless. Those constructors accept `default: bool = false` after `span`. For a null default, both the NOT wrapper and its `ExistsNode` carry the flag. Parsed nodes always use false. Neither built-in adapter emits spans. `RangeNode::getFrom()` and `getTo()` return null for open bounds and scalar values for literal bounds, including a quoted `*`.

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
