<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\AST\Node\InNode;
use HosmelQ\SearchSyntaxParser\Tests\TestSupport\TestVisitor;

it('exposes accessors and accepts visitor', function (): void {
    $node = new InNode('status', '!=', ['A', 'B']);

    expect($node)
        ->getType()->toBe('In')
        ->getField()->toBe('status')
        ->getOperator()->toBe('!=')
        ->getValues()->toBe(['A', 'B'])
        ->and($node->accept(new TestVisitor()))->toBe('in');
});
