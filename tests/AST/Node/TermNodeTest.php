<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\AST\Node\TermNode;
use HosmelQ\SearchSyntaxParser\Tests\Support\Visitors\RecordingVisitor;

it('returns correct type', function (): void {
    $node = new TermNode('shoes');

    expect($node->getType())->toBe('Term');
});

it('creates with correct properties', function (): void {
    $node = new TermNode('shoes');

    expect($node->getValue())->toBe('shoes');
});

it('dispatches visitor correctly', function (): void {
    $visitor = new RecordingVisitor();
    $node = new TermNode('shoes');

    $result = $node->accept($visitor);

    expect($visitor->lastCalled)->toBe('visitTerm')
        ->and($result)->toBe('term');
});
