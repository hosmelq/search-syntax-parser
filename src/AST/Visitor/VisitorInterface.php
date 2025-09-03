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

interface VisitorInterface
{
    /**
     * Visit a binary operator node (title:Coffee AND price:>10).
     */
    public function visitBinaryOperator(BinaryOperatorNode $node): mixed;

    /**
     * Visit a comparison node (price:>=5, status:!=sold, title:Coffee).
     */
    public function visitComparison(ComparisonNode $node): mixed;

    /**
     * Visit an exists node (category:*, title:*).
     */
    public function visitExists(ExistsNode $node): mixed;

    /**
     * Visit an in node (status:ACTIVE,DRAFT, type:A,B,C).
     */
    public function visitIn(InNode $node): mixed;

    /**
     * Visit a range node (date:[2025-01-01 TO 2025-12-31], price:[10 TO 50]).
     */
    public function visitRange(RangeNode $node): mixed;

    /**
     * Visit a term node (2025, Coffee, Electronics).
     */
    public function visitTerm(TermNode $node): mixed;

    /**
     * Visit a unary operator node (NOT title:Coffee, NOT price:>10).
     */
    public function visitUnaryOperator(UnaryOperatorNode $node): mixed;
}
