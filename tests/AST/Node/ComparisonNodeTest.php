<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\AST\Node\ComparisonNode;
use HosmelQ\SearchSyntaxParser\Tests\TestSupport\TestVisitor;

it('exposes accessors and accepts visitor', function (): void {
    $node = new ComparisonNode('price', '>=', 10);

    expect($node)
        ->getType()->toBe('Comparison')
        ->getField()->toBe('price')
        ->getOperator()->toBe('>=')
        ->getValue()->toBe(10)
        ->and($node->accept(new TestVisitor()))->toBe('comparison');
});
