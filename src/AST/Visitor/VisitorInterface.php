<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\AST\Visitor;

use HosmelQ\SearchSyntaxParser\AST\Node\BinaryOperatorNode;
use HosmelQ\SearchSyntaxParser\AST\Node\ComparisonNode;
use HosmelQ\SearchSyntaxParser\AST\Node\ExistsNode;
use HosmelQ\SearchSyntaxParser\AST\Node\InNode;
use HosmelQ\SearchSyntaxParser\AST\Node\RangeNode;
use HosmelQ\SearchSyntaxParser\AST\Node\TermNode;
use HosmelQ\SearchSyntaxParser\AST\Node\UnaryOperatorNode;
use HosmelQ\SearchSyntaxParser\AST\Node\WildcardNode;

interface VisitorInterface
{
    public function visitBinaryOperator(BinaryOperatorNode $node): mixed;

    public function visitComparison(ComparisonNode $node): mixed;

    public function visitExists(ExistsNode $node): mixed;

    public function visitIn(InNode $node): mixed;

    public function visitRange(RangeNode $node): mixed;

    public function visitTerm(TermNode $node): mixed;

    public function visitUnaryOperator(UnaryOperatorNode $node): mixed;

    public function visitWildcard(WildcardNode $node): mixed;
}
