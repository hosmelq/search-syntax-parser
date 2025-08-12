<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\Adapter\ArrayAdapter;
use HosmelQ\SearchSyntaxParser\AST\Node\BinaryOperatorNode;
use HosmelQ\SearchSyntaxParser\AST\Node\ComparisonNode;
use HosmelQ\SearchSyntaxParser\AST\Node\ExistsNode;
use HosmelQ\SearchSyntaxParser\AST\Node\RangeNode;
use HosmelQ\SearchSyntaxParser\AST\Node\TermNode;
use HosmelQ\SearchSyntaxParser\AST\Node\UnaryOperatorNode;
use HosmelQ\SearchSyntaxParser\Configuration\SearchConfiguration;

it('converts BinaryOperatorNode to array', function (): void {
    $adapter = new ArrayAdapter(new SearchConfiguration());
    $left = new TermNode('a');
    $right = new TermNode('b');
    $node = new BinaryOperatorNode('AND', $left, $right);

    $result = $adapter->build($node);

    expect($result)->toBe([
        'left' => [
            'fields' => [],
            'type' => 'term',
            'value' => 'a',
        ],
        'operator' => 'AND',
        'right' => [
            'fields' => [],
            'type' => 'term',
            'value' => 'b',
        ],
        'type' => 'binary',
    ]);
});

it('converts ComparisonNode to array', function (): void {
    $adapter = new ArrayAdapter(new SearchConfiguration());
    $node = new ComparisonNode('title', '=', 'shoes');

    $result = $adapter->build($node);

    expect($result)->toBe([
        'field' => 'title',
        'operator' => '=',
        'type' => 'comparison',
        'value' => 'shoes',
    ]);
});

it('converts ExistsNode to array', function (): void {
    $adapter = new ArrayAdapter(new SearchConfiguration());
    $node = new ExistsNode('published_at');

    $result = $adapter->build($node);

    expect($result)->toBe([
        'field' => 'published_at',
        'type' => 'exists',
    ]);
});

it('converts RangeNode to array', function (): void {
    $adapter = new ArrayAdapter(new SearchConfiguration());
    $node = new RangeNode('price', 10, 20);

    $result = $adapter->build($node);

    expect($result)->toBe([
        'field' => 'price',
        'from' => 10,
        'to' => 20,
        'type' => 'range',
    ]);
});

it('converts TermNode to array', function (): void {
    $adapter = new ArrayAdapter(new SearchConfiguration());
    $node = new TermNode('shoes');

    $result = $adapter->build($node);

    expect($result)->toBe([
        'fields' => [],
        'type' => 'term',
        'value' => 'shoes',
    ]);
});

it('converts UnaryOperatorNode to array', function (): void {
    $adapter = new ArrayAdapter(new SearchConfiguration());
    $operand = new TermNode('test');
    $node = new UnaryOperatorNode('NOT', $operand);

    $result = $adapter->build($node);

    expect($result)->toBe([
        'operand' => [
            'fields' => [],
            'type' => 'term',
            'value' => 'test',
        ],
        'operator' => 'NOT',
        'type' => 'unary',
    ]);
});

it('converts complex nested tree to array', function (): void {
    $adapter = new ArrayAdapter(new SearchConfiguration());
    $comparison1 = new ComparisonNode('title', '=', 'shoes');
    $comparison2 = new ComparisonNode('price', '>', 50);
    $binary = new BinaryOperatorNode('AND', $comparison1, $comparison2);
    $unary = new UnaryOperatorNode('NOT', $binary);

    $result = $adapter->build($unary);

    expect($result)->toBe([
        'operand' => [
            'left' => [
                'field' => 'title',
                'operator' => '=',
                'type' => 'comparison',
                'value' => 'shoes',
            ],
            'operator' => 'AND',
            'right' => [
                'field' => 'price',
                'operator' => '>',
                'type' => 'comparison',
                'value' => 50,
            ],
            'type' => 'binary',
        ],
        'operator' => 'NOT',
        'type' => 'unary',
    ]);
});

it('converts complex query with exists nodes to array', function (): void {
    $adapter = new ArrayAdapter(new SearchConfiguration());
    $exists1 = new ExistsNode('published_at');
    $exists2 = new ExistsNode('discount');
    $notExists = new UnaryOperatorNode('NOT', $exists2);
    $binary = new BinaryOperatorNode('AND', $exists1, $notExists);

    $result = $adapter->build($binary);

    expect($result)->toBe([
        'left' => [
            'field' => 'published_at',
            'type' => 'exists',
        ],
        'operator' => 'AND',
        'right' => [
            'operand' => [
                'field' => 'discount',
                'type' => 'exists',
            ],
            'operator' => 'NOT',
            'type' => 'unary',
        ],
        'type' => 'binary',
    ]);
});

it('does not include fields in term nodes when searchable fields is empty', function (): void {
    $adapter = new ArrayAdapter(new SearchConfiguration());
    $node = new TermNode('coffee');

    $result = $adapter->build($node);

    expect($result)->toBe([
        'fields' => [],
        'type' => 'term',
        'value' => 'coffee',
    ]);
});

it('includes searchable fields in term nodes when configuration is provided', function (): void {
    $adapter = new ArrayAdapter(
        (new SearchConfiguration())->setSearchableFields(['title', 'description'])
    );
    $node = new TermNode('coffee');

    $result = $adapter->build($node);

    expect($result)->toBe([
        'fields' => ['title', 'description'],
        'type' => 'term',
        'value' => 'coffee',
    ]);
});

it('uses allowed fields as searchable fields when not explicitly set', function (): void {
    $adapter = new ArrayAdapter(
        (new SearchConfiguration())->setAllowedFields(['title', 'price', 'category'])
    );
    $node = new TermNode('electronics');

    $result = $adapter->build($node);

    expect($result)->toBe([
        'fields' => ['title', 'price', 'category'],
        'type' => 'term',
        'value' => 'electronics',
    ]);
});
