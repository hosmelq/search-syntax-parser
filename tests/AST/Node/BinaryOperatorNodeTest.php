<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\AST\Node\BinaryOperatorNode;
use HosmelQ\SearchSyntaxParser\AST\Node\ComparisonNode;
use HosmelQ\SearchSyntaxParser\AST\Node\TermNode;
use HosmelQ\SearchSyntaxParser\Tests\TestSupport\TestVisitor;

it('exposes accessors and accepts visitor', function (): void {
    $left = new TermNode('coffee');
    $right = new ComparisonNode('price', '>', 10);
    $node = new BinaryOperatorNode('AND', $left, $right);

    expect($node)
        ->getType()->toBe('BinaryOperator')
        ->getOperator()->toBe('AND')
        ->getLeft()->toBe($left)
        ->getRight()->toBe($right)
        ->and($node->accept(new TestVisitor()))->toBe('binary');
});
