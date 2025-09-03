<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\Adapter\ArrayAdapter;
use HosmelQ\SearchSyntaxParser\AST\Node\BinaryOperatorNode;
use HosmelQ\SearchSyntaxParser\AST\Node\ComparisonNode;
use HosmelQ\SearchSyntaxParser\AST\Node\ExistsNode;
use HosmelQ\SearchSyntaxParser\AST\Node\InNode;
use HosmelQ\SearchSyntaxParser\AST\Node\RangeNode;
use HosmelQ\SearchSyntaxParser\AST\Node\TermNode;
use HosmelQ\SearchSyntaxParser\AST\Node\UnaryOperatorNode;
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;

it('maps binary node recursively', function (): void {
    $adapter = new ArrayAdapter();
    $left = new ComparisonNode('price', '>', 10);
    $right = new TermNode('coffee');
    $node = new BinaryOperatorNode('AND', $left, $right);

    expect($adapter->build($node))->toBe([
        'left' => [
            'field' => 'price',
            'operator' => '>',
            'type' => 'comparison',
            'value' => 10,
        ],
        'operator' => 'AND',
        'right' => [
            'type' => 'term',
            'value' => 'coffee',
        ],
        'type' => 'binary',
    ]);
});

it('maps comparison node', function (): void {
    $adapter = new ArrayAdapter();
    $node = new ComparisonNode('price', '>=', 10);

    expect($adapter->build($node))->toBe([
        'field' => 'price',
        'operator' => '>=',
        'type' => 'comparison',
        'value' => 10,
    ]);
});

it('maps comparison node with internal field name', function (): void {
    $searchParser = (new SearchParser())->allowedFields([
        new AllowedField('price', 'unit_price'),
    ]);

    $adapter = new ArrayAdapter($searchParser);
    $node = new ComparisonNode('price', '=', 5);

    expect($adapter->build($node))->toBe([
        'field' => 'unit_price',
        'operator' => '=',
        'type' => 'comparison',
        'value' => 5,
    ]);
});

it('maps exists node', function (): void {
    $adapter = new ArrayAdapter();
    $node = new ExistsNode('category');

    expect($adapter->build($node))->toBe([
        'field' => 'category',
        'type' => 'exists',
    ]);
});

it('maps in node', function (): void {
    $adapter = new ArrayAdapter();
    $node = new InNode('status', '!=', ['A', 'B']);

    expect($adapter->build($node))->toBe([
        'field' => 'status',
        'operator' => '!=',
        'type' => 'in',
        'values' => ['A', 'B'],
    ]);
});

it('maps range node', function (): void {
    $adapter = new ArrayAdapter();
    $node = new RangeNode('date', '2025-01-01', '2025-12-31');

    expect($adapter->build($node))->toBe([
        'field' => 'date',
        'from' => '2025-01-01',
        'to' => '2025-12-31',
        'type' => 'range',
    ]);
});

it('maps term node', function (): void {
    $adapter = new ArrayAdapter();
    $node = new TermNode('coffee');

    expect($adapter->build($node))->toBe([
        'type' => 'term',
        'value' => 'coffee',
    ]);
});

it('maps unary node recursively', function (): void {
    $adapter = new ArrayAdapter();
    $inner = new ComparisonNode('flag', '=', true);
    $node = new UnaryOperatorNode('NOT', $inner);

    expect($adapter->build($node))->toBe([
        'operand' => [
            'field' => 'flag',
            'operator' => '=',
            'type' => 'comparison',
            'value' => true,
        ],
        'operator' => 'NOT',
        'type' => 'unary',
    ]);
});
