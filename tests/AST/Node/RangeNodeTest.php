<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\AST\Node\RangeNode;
use HosmelQ\SearchSyntaxParser\Tests\TestSupport\TestVisitor;

it('exposes accessors and accepts visitor', function (): void {
    $node = new RangeNode('date', '2025-01-01', '2025-12-31');

    expect($node)
        ->getType()->toBe('Range')
        ->getField()->toBe('date')
        ->getFrom()->toBe('2025-01-01')
        ->getTo()->toBe('2025-12-31')
        ->and($node->accept(new TestVisitor()))->toBe('range');
});
