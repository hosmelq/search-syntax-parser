<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\AST\Node\InNode;
use HosmelQ\SearchSyntaxParser\Configuration\SearchConfiguration;
use HosmelQ\SearchSyntaxParser\Parser\Parser;
use HosmelQ\SearchSyntaxParser\SearchParser;

it('parses basic comma-separated values', function (): void {
    $parser = new Parser(new SearchConfiguration());

    $ast = $parser->parse('status:ACTIVE,DRAFT');

    expect($ast)
        ->toBeInstanceOf(InNode::class)
        ->getField()->toBe('status')
        ->getOperator()->toBe('=')
        ->getValues()->toBe(['ACTIVE', 'DRAFT']);
});

it('parses multiple comma-separated values', function (): void {
    $parser = new Parser(new SearchConfiguration());

    $ast = $parser->parse('type:A,B,C');

    expect($ast)
        ->toBeInstanceOf(InNode::class)
        ->getField()->toBe('type')
        ->getOperator()->toBe('=')
        ->getValues()->toBe(['A', 'B', 'C']);
});

it('parses comma-separated values with not equal operator', function (): void {
    $parser = new Parser(new SearchConfiguration());

    $ast = $parser->parse('status:!=ACTIVE,DRAFT');

    expect($ast)
        ->toBeInstanceOf(InNode::class)
        ->getField()->toBe('status')
        ->getOperator()->toBe('!=')
        ->getValues()->toBe(['ACTIVE', 'DRAFT']);
});

it('parses comma-separated values mixed with boolean logic', function (): void {
    $parser = new Parser(new SearchConfiguration());

    $ast = $parser->parse('status:ACTIVE,DRAFT AND price:>50');

    expect($ast->getType())->toBe('BinaryOperator')
        ->and($ast->getOperator())->toBe('AND')
        ->and($ast->getLeft())->toBeInstanceOf(InNode::class)
        ->and($ast->getLeft()->getField())->toBe('status')
        ->and($ast->getLeft()->getValues())->toBe(['ACTIVE', 'DRAFT']);
});

it('converts comma-separated values to array using ArrayAdapter', function (): void {
    $parser = new SearchParser();

    $result = $parser->build('status:ACTIVE,DRAFT', 'array');

    expect($result)->toBe([
        'field' => 'status',
        'operator' => '=',
        'type' => 'in',
        'values' => ['ACTIVE', 'DRAFT'],
    ]);
});

it('converts comma-separated values with complex query to array', function (): void {
    $parser = new SearchParser();

    $result = $parser->build('status:ACTIVE,DRAFT AND price:>50', 'array');

    expect($result)->toBeArray()
        ->left->field->toBe('status')
        ->left->type->toBe('in')
        ->left->values->toBe(['ACTIVE', 'DRAFT'])
        ->operator->toBe('AND')
        ->right->type->toBe('comparison')
        ->type->toBe('binary');
});

it('handles quoted comma-separated values', function (): void {
    $parser = new Parser(new SearchConfiguration());

    $ast = $parser->parse('name:"John Doe","Jane Smith"');

    expect($ast)
        ->toBeInstanceOf(InNode::class)
        ->getField()->toBe('name')
        ->getValues()->toBe(['John Doe', 'Jane Smith']);
});

it('handles numeric comma-separated values', function (): void {
    $parser = new Parser(new SearchConfiguration());

    $ast = $parser->parse('id:1,2,3');

    expect($ast)
        ->toBeInstanceOf(InNode::class)
        ->getField()->toBe('id')
        ->getValues()->toBe([1, 2, 3]);
});
