<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\AST\Node\TermNode;
use HosmelQ\SearchSyntaxParser\Tests\TestSupport\TestVisitor;

it('exposes accessors and accepts visitor', function (): void {
    $node = new TermNode('coffee');

    expect($node)
        ->getType()->toBe('Term')
        ->getValue()->toBe('coffee')
        ->and($node->accept(new TestVisitor()))->toBe('term');
});
