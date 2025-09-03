<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\AST\Node\BinaryOperatorNode;
use HosmelQ\SearchSyntaxParser\AST\Node\ComparisonNode;
use HosmelQ\SearchSyntaxParser\AST\Node\ExistsNode;
use HosmelQ\SearchSyntaxParser\AST\Node\InNode;
use HosmelQ\SearchSyntaxParser\AST\Node\RangeNode;
use HosmelQ\SearchSyntaxParser\AST\Node\TermNode;
use HosmelQ\SearchSyntaxParser\AST\Node\UnaryOperatorNode;
use HosmelQ\SearchSyntaxParser\SearchParser;

it('parses field comparisons', function (): void {
    $ast = SearchParser::query('price:>10')->parse();

    expect($ast)->toBeInstanceOf(ComparisonNode::class)
        ->getField()->toBe('price')
        ->getOperator()->toBe('>')
        ->getValue()->toBe(10);
});

it('parses exists queries', function (): void {
    $ast = SearchParser::query('category:*')->parse();

    expect($ast)->toBeInstanceOf(ExistsNode::class)
        ->getField()->toBe('category');
});

it('parses in lists with comma-separated values', function (): void {
    $ast = SearchParser::query('status:A,B')->parse();

    expect($ast)->toBeInstanceOf(InNode::class)
        ->getField()->toBe('status')
        ->getOperator()->toBe('=')
        ->getValues()->toBe(['A', 'B']);
});

it('parses numeric and date ranges', function (): void {
    $numeric = SearchParser::query('price:[10 TO 50]')->parse();
    $date = SearchParser::query('date:[2025-01-01 TO 2025-12-31]')->parse();

    expect($numeric)->toBeInstanceOf(RangeNode::class)
        ->getField()->toBe('price')
        ->getFrom()->toBe(10)
        ->getTo()->toBe(50)
        ->and($date)->toBeInstanceOf(RangeNode::class)
        ->getField()->toBe('date')
        ->getFrom()->toBe('2025-01-01')
        ->getTo()->toBe('2025-12-31');
});

it('parses NOT modifier and minus as unary', function (): void {
    $notAst = SearchParser::query('NOT title:Coffee')->parse();
    $minusAst = SearchParser::query('-title:Coffee')->parse();

    foreach ([$notAst, $minusAst] as $ast) {
        expect($ast)->toBeInstanceOf(UnaryOperatorNode::class)
            ->getOperator()->toBe('NOT');

        $operand = $ast->getOperand();
        expect($operand)->toBeInstanceOf(ComparisonNode::class)
            ->getField()->toBe('title')
            ->getOperator()->toBe('=')
            ->getValue()->toBe('Coffee');
    }
});

it('parses wildcard suffix on values', function (): void {
    $ast = SearchParser::query('brand:nike*')->parse();

    expect($ast)->toBeInstanceOf(ComparisonNode::class)
        ->getField()->toBe('brand')
        ->getOperator()->toBe('=')
        ->getValue()->toBe('nike*');
});

it('parses implicit AND at AST level', function (): void {
    $ast = SearchParser::query('title:Coffee price:<10')->parse();

    $left = $ast->getLeft();
    $right = $ast->getRight();

    expect($ast)->toBeInstanceOf(BinaryOperatorNode::class)
        ->getOperator()->toBe('AND')
        ->and($left)->toBeInstanceOf(ComparisonNode::class)
        ->getField()->toBe('title')
        ->getOperator()->toBe('=')
        ->getValue()->toBe('Coffee')
        ->and($right)->toBeInstanceOf(ComparisonNode::class)
        ->getField()->toBe('price')
        ->getOperator()->toBe('<')
        ->getValue()->toBe(10);
});

it('parses standalone term identifiers and numbers', function (): void {
    $termString = SearchParser::query('Coffee')->parse();
    $termInt = SearchParser::query('123')->parse();
    $termFloat = SearchParser::query('12.5')->parse();

    expect($termString)->toBeInstanceOf(TermNode::class)
        ->getValue()->toBe('Coffee')
        ->and($termInt)->toBeInstanceOf(TermNode::class)
        ->getValue()->toBe('123')
        ->and($termFloat)->toBeInstanceOf(TermNode::class)
        ->getValue()->toBe('12.5');
});

it('parses standalone boolean terms as lowercase strings', function (): void {
    $trueTerm = SearchParser::query('true')->parse();
    $falseTerm = SearchParser::query('false')->parse();

    expect($trueTerm)->toBeInstanceOf(TermNode::class)
        ->getValue()->toBe('true')
        ->and($falseTerm)->toBeInstanceOf(TermNode::class)
        ->getValue()->toBe('false');
});

it('parses field comparisons without colon', function (): void {
    $ast = SearchParser::query('price>10')->parse();

    expect($ast)->toBeInstanceOf(ComparisonNode::class)
        ->getField()->toBe('price')
        ->getOperator()->toBe('>')
        ->getValue()->toBe(10);
});

it('parses boolean identifier values into booleans', function (): void {
    $astTrue = SearchParser::query('flag:true')->parse();
    $astFalse = SearchParser::query('flag:false')->parse();

    expect($astTrue)->toBeInstanceOf(ComparisonNode::class)
        ->getField()->toBe('flag')
        ->getOperator()->toBe('=')
        ->getValue()->toBe(true)
        ->and($astFalse)->toBeInstanceOf(ComparisonNode::class)
        ->getField()->toBe('flag')
        ->getOperator()->toBe('=')
        ->getValue()->toBe(false);
});

it('parses float numbers into floats', function (): void {
    $ast = SearchParser::query('score:10.5')->parse();

    expect($ast)->toBeInstanceOf(ComparisonNode::class)
        ->getField()->toBe('score')
        ->getOperator()->toBe('=')
        ->getValue()->toBe(10.5);
});

it('parses string ranges', function (): void {
    $parser = SearchParser::query('title:["A" TO "Z"]');
    $ast = $parser->parse();

    expect($ast)->toBeInstanceOf(RangeNode::class)
        ->getField()->toBe('title')
        ->getFrom()->toBe('A')
        ->getTo()->toBe('Z');
});

it('parses in-list with not-equal operator', function (): void {
    $ast = SearchParser::query('status:!=A,B')->parse();

    expect($ast)->toBeInstanceOf(InNode::class)
        ->getField()->toBe('status')
        ->getOperator()->toBe('!=')
        ->getValues()->toBe(['A', 'B']);
});

it('parses wildcard suffix on quoted strings', function (): void {
    $ast = SearchParser::query('title:"Co"*')->parse();

    expect($ast)->toBeInstanceOf(ComparisonNode::class)
        ->getField()->toBe('title')
        ->getOperator()->toBe('=')
        ->getValue()->toBe('Co*');
});
