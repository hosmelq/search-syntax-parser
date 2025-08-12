<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\AST\Node\RangeNode;
use HosmelQ\SearchSyntaxParser\Tests\Support\Visitors\RecordingVisitor;

it('returns correct type', function (): void {
    $node = new RangeNode('price', 10, 20);

    expect($node->getType())->toBe('Range');
});

it('creates with correct properties', function (): void {
    $node = new RangeNode('price', 10, 20);

    expect($node)
        ->getField()->toBe('price')
        ->getFrom()->toBe(10)
        ->getTo()->toBe(20);
});

it('dispatches visitor correctly', function (): void {
    $visitor = new RecordingVisitor();
    $node = new RangeNode('price', 10, 20);

    $result = $node->accept($visitor);

    expect($visitor->lastCalled)->toBe('visitRange')
        ->and($result)->toBe('range');
});
