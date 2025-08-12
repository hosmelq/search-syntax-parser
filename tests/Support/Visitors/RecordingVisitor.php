<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Tests\Support\Visitors;

use HosmelQ\SearchSyntaxParser\AST\Node\BinaryOperatorNode;
use HosmelQ\SearchSyntaxParser\AST\Node\ComparisonNode;
use HosmelQ\SearchSyntaxParser\AST\Node\ExistsNode;
use HosmelQ\SearchSyntaxParser\AST\Node\RangeNode;
use HosmelQ\SearchSyntaxParser\AST\Node\TermNode;
use HosmelQ\SearchSyntaxParser\AST\Node\UnaryOperatorNode;
use HosmelQ\SearchSyntaxParser\AST\Visitor\VisitorInterface;

class RecordingVisitor implements VisitorInterface
{
    public array $calls = [];

    public string $lastCalled = '';

    public function visitBinaryOperator(BinaryOperatorNode $node): string
    {
        $this->calls[] = 'visitBinaryOperator';
        $this->lastCalled = 'visitBinaryOperator';

        return 'binary_operator';
    }

    public function visitComparison(ComparisonNode $node): string
    {
        $this->calls[] = 'visitComparison';
        $this->lastCalled = 'visitComparison';

        return 'comparison';
    }

    public function visitExists(ExistsNode $node): string
    {
        $this->calls[] = 'visitExists';
        $this->lastCalled = 'visitExists';

        return 'exists';
    }

    public function visitRange(RangeNode $node): string
    {
        $this->calls[] = 'visitRange';
        $this->lastCalled = 'visitRange';

        return 'range';
    }

    public function visitTerm(TermNode $node): string
    {
        $this->calls[] = 'visitTerm';
        $this->lastCalled = 'visitTerm';

        return 'term';
    }

    public function visitUnaryOperator(UnaryOperatorNode $node): string
    {
        $this->calls[] = 'visitUnaryOperator';
        $this->lastCalled = 'visitUnaryOperator';

        return 'unary_operator';
    }
}
