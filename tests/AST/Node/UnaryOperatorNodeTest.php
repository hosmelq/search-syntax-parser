<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\AST\Node\TermNode;
use HosmelQ\SearchSyntaxParser\AST\Node\UnaryOperatorNode;
use HosmelQ\SearchSyntaxParser\Tests\Support\Visitors\RecordingVisitor;

it('returns correct type', function (): void {
    $operand = new TermNode('test');
    $node = new UnaryOperatorNode('NOT', $operand);

    expect($node->getType())->toBe('UnaryOperator');
});

it('creates with correct properties', function (): void {
    $operand = new TermNode('test');
    $node = new UnaryOperatorNode('NOT', $operand);

    expect($node)
        ->getOperand()->toBe($operand)
        ->getOperator()->toBe('NOT');
});

it('dispatches visitor correctly', function (): void {
    $visitor = new RecordingVisitor();
    $node = new UnaryOperatorNode('NOT', new TermNode('test'));

    $result = $node->accept($visitor);

    expect($visitor->lastCalled)->toBe('visitUnaryOperator')
        ->and($result)->toBe('unary_operator');
});
