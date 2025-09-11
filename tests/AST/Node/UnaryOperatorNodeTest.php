<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\AST\Node\TermNode;
use HosmelQ\SearchSyntaxParser\AST\Node\UnaryOperatorNode;
use HosmelQ\SearchSyntaxParser\Tests\TestSupport\TestVisitor;

it('exposes accessors and accepts visitor', function (): void {
    $operand = new TermNode('tea');
    $node = new UnaryOperatorNode('NOT', $operand);

    expect($node)
        ->getType()->toBe('UnaryOperator')
        ->getOperator()->toBe('NOT')
        ->getOperand()->toBe($operand)
        ->and($node->accept(new TestVisitor()))->toBe('unary');
});
