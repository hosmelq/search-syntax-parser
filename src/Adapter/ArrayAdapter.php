<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Adapter;

use HosmelQ\SearchSyntaxParser\AST\Node\BinaryOperatorNode;
use HosmelQ\SearchSyntaxParser\AST\Node\ComparisonNode;
use HosmelQ\SearchSyntaxParser\AST\Node\ExistsNode;
use HosmelQ\SearchSyntaxParser\AST\Node\InNode;
use HosmelQ\SearchSyntaxParser\AST\Node\NodeInterface;
use HosmelQ\SearchSyntaxParser\AST\Node\RangeNode;
use HosmelQ\SearchSyntaxParser\AST\Node\TermNode;
use HosmelQ\SearchSyntaxParser\AST\Node\UnaryOperatorNode;
use HosmelQ\SearchSyntaxParser\AST\Visitor\VisitorInterface;
use HosmelQ\SearchSyntaxParser\Configuration\SearchConfiguration;

readonly class ArrayAdapter implements QueryAdapterInterface, VisitorInterface
{
    /**
     * Create a new ArrayAdapter instance.
     */
    public function __construct(
        private null|SearchConfiguration $configuration = null
    ) {
    }

    /**
     * Build an array representation from the AST.
     *
     * @return array<string, mixed>
     */
    public function build(NodeInterface $ast): array
    {
        /** @var array<string, mixed> */
        return $ast->accept($this);
    }

    /**
     * Visit a binary operator node (title:Coffee AND price:>10).
     *
     * @return array<string, mixed>
     */
    public function visitBinaryOperator(BinaryOperatorNode $node): array
    {
        return [
            'left' => $node->getLeft()->accept($this),
            'operator' => $node->getOperator(),
            'right' => $node->getRight()->accept($this),
            'type' => 'binary',
        ];
    }

    /**
     * Visit a comparison node (price:>=5, status:!=sold, title:Coffee).
     *
     * @return array<string, mixed>
     */
    public function visitComparison(ComparisonNode $node): array
    {
        return [
            'field' => $node->getField(),
            'operator' => $node->getOperator(),
            'type' => 'comparison',
            'value' => $node->getValue(),
        ];
    }

    /**
     * Visit an exists node (category:*, title:*).
     *
     * @return array<string, mixed>
     */
    public function visitExists(ExistsNode $node): array
    {
        return [
            'field' => $node->getField(),
            'type' => 'exists',
        ];
    }

    /**
     * Visit an in node (status:ACTIVE,DRAFT).
     *
     * @return array<string, mixed>
     */
    public function visitIn(InNode $node): array
    {
        return [
            'field' => $node->getField(),
            'operator' => $node->getOperator(),
            'type' => 'in',
            'values' => $node->getValues(),
        ];
    }

    /**
     * Visit a range node (date:[2025-01-01 TO 2025-12-31], price:[10 TO 50]).
     *
     * @return array<string, mixed>
     */
    public function visitRange(RangeNode $node): array
    {
        return [
            'field' => $node->getField(),
            'from' => $node->getFrom(),
            'to' => $node->getTo(),
            'type' => 'range',
        ];
    }

    /**
     * Visit a term node (2025, Coffee, Electronics).
     *
     * @return array<string, mixed>
     */
    public function visitTerm(TermNode $node): array
    {
        $result = [
            'fields' => [],
            'type' => 'term',
            'value' => $node->getValue(),
        ];

        if ($this->configuration instanceof SearchConfiguration) {
            $searchableFields = $this->configuration->getSearchableFields();

            if ($searchableFields !== []) {
                $result['fields'] = $searchableFields;
            }
        }

        return $result;
    }

    /**
     * Visit a unary operator node (NOT title:Coffee, NOT price:>10).
     *
     * @return array<string, mixed>
     */
    public function visitUnaryOperator(UnaryOperatorNode $node): array
    {
        return [
            'operand' => $node->getOperand()->accept($this),
            'operator' => $node->getOperator(),
            'type' => 'unary',
        ];
    }
}
