<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\AST\Node\BinaryOperatorNode;
use HosmelQ\SearchSyntaxParser\AST\Node\TermNode;
use HosmelQ\SearchSyntaxParser\Tests\Support\Visitors\RecordingVisitor;

it('returns correct type', function (): void {
    $left = new TermNode('left');
    $right = new TermNode('right');
    $node = new BinaryOperatorNode('AND', $left, $right);

    expect($node->getType())->toBe('BinaryOperator');
});

it('creates with correct properties', function (): void {
    $left = new TermNode('left');
    $right = new TermNode('right');
    $node = new BinaryOperatorNode('AND', $left, $right);

    expect($node)
        ->getLeft()->toBe($left)
        ->getOperator()->toBe('AND')
        ->getRight()->toBe($right);
});

it('dispatches visitor correctly', function (): void {
    $visitor = new RecordingVisitor();
    $node = new BinaryOperatorNode('AND', new TermNode('a'), new TermNode('b'));

    $result = $node->accept($visitor);

    expect($visitor->lastCalled)->toBe('visitBinaryOperator')
        ->and($result)->toBe('binary_operator');
});
