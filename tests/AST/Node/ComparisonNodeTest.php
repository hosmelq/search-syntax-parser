<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\AST\Node\ComparisonNode;
use HosmelQ\SearchSyntaxParser\Tests\Support\Visitors\RecordingVisitor;

it('returns correct type', function (): void {
    $node = new ComparisonNode('title', '=', 'shoes');

    expect($node->getType())->toBe('Comparison');
});

it('creates with correct properties', function (): void {
    $node = new ComparisonNode('title', '=', 'shoes');

    expect($node)
        ->getField()->toBe('title')
        ->getOperator()->toBe('=')
        ->getValue()->toBe('shoes');
});

it('dispatches visitor correctly', function (): void {
    $visitor = new RecordingVisitor();
    $node = new ComparisonNode('title', '=', 'shoes');

    $result = $node->accept($visitor);

    expect($visitor->lastCalled)->toBe('visitComparison')
        ->and($result)->toBe('comparison');
});
