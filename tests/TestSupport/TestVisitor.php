<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Tests\TestSupport;

use HosmelQ\SearchSyntaxParser\AST\Node\BinaryOperatorNode;
use HosmelQ\SearchSyntaxParser\AST\Node\ComparisonNode;
use HosmelQ\SearchSyntaxParser\AST\Node\ExistsNode;
use HosmelQ\SearchSyntaxParser\AST\Node\InNode;
use HosmelQ\SearchSyntaxParser\AST\Node\RangeNode;
use HosmelQ\SearchSyntaxParser\AST\Node\TermNode;
use HosmelQ\SearchSyntaxParser\AST\Node\UnaryOperatorNode;
use HosmelQ\SearchSyntaxParser\AST\Visitor\VisitorInterface;

class TestVisitor implements VisitorInterface
{
    public function visitBinaryOperator(BinaryOperatorNode $node): mixed
    {
        return 'binary';
    }

    public function visitComparison(ComparisonNode $node): mixed
    {
        return 'comparison';
    }

    public function visitExists(ExistsNode $node): mixed
    {
        return 'exists';
    }

    public function visitIn(InNode $node): mixed
    {
        return 'in';
    }

    public function visitRange(RangeNode $node): mixed
    {
        return 'range';
    }

    public function visitTerm(TermNode $node): mixed
    {
        return 'term';
    }

    public function visitUnaryOperator(UnaryOperatorNode $node): mixed
    {
        return 'unary';
    }
}
