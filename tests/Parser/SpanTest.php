<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\AST\Node\BinaryOperatorNode;
use HosmelQ\SearchSyntaxParser\AST\Node\ComparisonNode;
use HosmelQ\SearchSyntaxParser\AST\Node\ExistsNode;
use HosmelQ\SearchSyntaxParser\AST\Node\InNode;
use HosmelQ\SearchSyntaxParser\AST\Node\NodeInterface;
use HosmelQ\SearchSyntaxParser\AST\Node\RangeNode;
use HosmelQ\SearchSyntaxParser\AST\Node\TermNode;
use HosmelQ\SearchSyntaxParser\AST\Node\UnaryOperatorNode;
use HosmelQ\SearchSyntaxParser\AST\Node\WildcardNode;
use HosmelQ\SearchSyntaxParser\AST\Span;
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;

it('spans the exact source consumed by each parsed node kind', function (
    string $query,
    string $type,
    int $offset,
    int $length,
): void {
    $node = SearchParser::query($query)->allowAnyField()->parse();

    expect($node->getType())->toBe($type)
        ->and($node->getSpan())->toEqual(new Span($offset, $length));
})->with([
    ['title:Coffee', 'Comparison', 0, 12],
    ['  title:Coffee  ', 'Comparison', 2, 12],
    ['"Cold Brew"', 'Term', 0, 11],
    ['  café  ', 'Term', 2, 4],
    ["\u{2003}🙂e\u{0301}", 'Term', 1, 3],
    ['name:"a\\"b"', 'Comparison', 0, 11],
    ['âge:>-5', 'Comparison', 0, 7],
    ['name:*', 'Exists', 0, 6],
    ['name:a,"b c"', 'In', 0, 12],
    ['âge:[-5 TO 10]', 'Range', 0, 14],
    ['"café noir"*', 'Wildcard', 0, 12],
    ['name:"café noir"*', 'Wildcard', 0, 17],
    ['NOT (café OR 🙂)', 'UnaryOperator', 0, 15],
    ['-(café)', 'UnaryOperator', 0, 7],
    ['(café) AND (🙂)', 'BinaryOperator', 0, 14],
    [' a OR b ', 'BinaryOperator', 1, 6],
    [' ( café ) ', 'Term', 3, 4],
]);

it('keeps inner group spans and includes closing parentheses in outer nodes', function (): void {
    $node = SearchParser::query('NOT (a OR b) AND (c)')->parse();

    expect($node->getSpan())->toEqual(new Span(0, 20))
        ->and($node->getLeft()->getSpan())->toEqual(new Span(0, 12))
        ->and($node->getLeft()->getOperand()->getSpan())->toEqual(new Span(5, 6))
        ->and($node->getLeft()->getOperand()->getLeft()->getSpan())->toEqual(new Span(5, 1))
        ->and($node->getRight()->getSpan())->toEqual(new Span(18, 1));
});

it('gives every expanded wildcard list node the whole clause span', function (
    string $operator,
): void {
    $query = '  name:'.$operator.'café*,bob,"🙂"*  ';
    $node = SearchParser::query($query)->allowAnyField()->parse();
    $span = new Span(2, mb_strlen($query) - 4);

    expect($node->getSpan())->toEqual($span)
        ->and($node->getLeft()->getSpan())->toEqual($span)
        ->and($node->getLeft()->getLeft()->getSpan())->toEqual($span)
        ->and($node->getLeft()->getRight()->getSpan())->toEqual($span)
        ->and($node->getRight()->getSpan())->toEqual($span);
})->with(['', '!=']);

it('marks all default-generated nodes and joins with null spans', function (): void {
    $node = SearchParser::query('coffee')->allowedFields([
        AllowedField::string('status')->default('active'),
        AllowedField::array('tag')->default(['one', 'two']),
        AllowedField::string('deleted')->default(null),
    ])->parse();

    expect($node->getSpan())->toBeNull()
        ->and($node->getRight()->getSpan())->toBeNull()
        ->and($node->getRight()->getOperand()->getSpan())->toBeNull()
        ->and($node->getLeft()->getSpan())->toBeNull()
        ->and($node->getLeft()->getRight()->getSpan())->toBeNull()
        ->and($node->getLeft()->getLeft()->getSpan())->toBeNull()
        ->and($node->getLeft()->getLeft()->getRight()->getSpan())->toBeNull()
        ->and($node->getLeft()->getLeft()->getLeft()->getSpan())->toEqual(new Span(0, 6));
});

it('keeps direct node constructions spanless unless supplied explicitly', function (
    NodeInterface $node,
): void {
    expect($node->getSpan())->toBeNull();
})->with([
    new ComparisonNode('x', '=', 1), new ExistsNode('x'), new InNode('x', '=', [1]),
    new RangeNode('x', null, 1), new TermNode('coffee'), new WildcardNode('x', '=', ['a', '']),
    new UnaryOperatorNode('NOT', new TermNode('a')),
    new BinaryOperatorNode('AND', new TermNode('a'), new TermNode('b')),
]);
