<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\AST\Node\ExistsNode;
use HosmelQ\SearchSyntaxParser\Tests\Support\Visitors\RecordingVisitor;

it('returns correct type', function (): void {
    $node = new ExistsNode('published_at');

    expect($node->getType())->toBe('Exists');
});

it('creates with correct properties', function (): void {
    $node = new ExistsNode('published_at');

    expect($node->getField())->toBe('published_at');
});

it('dispatches visitor correctly', function (): void {
    $visitor = new RecordingVisitor();
    $node = new ExistsNode('tags');

    $result = $node->accept($visitor);

    expect($visitor->lastCalled)->toBe('visitExists')
        ->and($result)->toBe('exists');
});
