<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\AST\Node\BinaryOperatorNode;
use HosmelQ\SearchSyntaxParser\AST\Node\TermNode;
use HosmelQ\SearchSyntaxParser\Tests\Support\Visitors\RecordingVisitor;

it('records multiple visitor calls correctly', function (): void {
    $visitor = new RecordingVisitor();

    $term1 = new TermNode('a');
    $term2 = new TermNode('b');
    $binary = new BinaryOperatorNode('AND', $term1, $term2);

    $term1->accept($visitor);
    $term2->accept($visitor);
    $binary->accept($visitor);

    expect($visitor)
        ->calls->toBe(['visitTerm', 'visitTerm', 'visitBinaryOperator'])
        ->lastCalled->toBe('visitBinaryOperator');
});
