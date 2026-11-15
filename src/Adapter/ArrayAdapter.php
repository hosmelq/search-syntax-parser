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
use HosmelQ\SearchSyntaxParser\AST\Node\WildcardNode;
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;
use HosmelQ\SearchSyntaxParser\Validation\TextMatch;
use Override;

readonly class ArrayAdapter extends AbstractAdapter
{
    /**
     * Build an array representation from the AST.
     *
     * @return array<string, mixed>
     */
    #[Override]
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
    #[Override]
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
    #[Override]
    public function visitComparison(ComparisonNode $node): array
    {
        $field = $this->searchParser?->getAllowedField($node->getField());

        return [
            'default' => $node->isDefault(),
            'field' => $this->getInternalFieldName($node->getField()),
            'match' => ($field?->getTextMatch($node) ?? TextMatch::Exact)->value,
            'operator' => $node->getOperator(),
            'quoted' => $node->isQuoted(),
            'type' => 'comparison',
            'value' => $field?->toStorage($node->getValue()) ?? $node->getValue(),
        ];
    }

    /**
     * Visit an exists node (category:*, title:*).
     *
     * @return array<string, mixed>
     */
    #[Override]
    public function visitExists(ExistsNode $node): array
    {
        return [
            'default' => $node->isDefault(),
            'field' => $this->getInternalFieldName($node->getField()),
            'type' => 'exists',
        ];
    }

    /**
     * Visit an in node (status:ACTIVE,DRAFT).
     *
     * @return array<string, mixed>
     */
    #[Override]
    public function visitIn(InNode $node): array
    {
        $field = $this->searchParser?->getAllowedField($node->getField());
        $values = $node->getValues();

        return [
            'default' => $node->isDefault(),
            'field' => $this->getInternalFieldName($node->getField()),
            'match' => array_map(
                static fn (int $index): string
                    => ($field?->getTextMatch($node, $index) ?? TextMatch::Exact)->value,
                array_keys($values),
            ),
            'operator' => $node->getOperator(),
            'quoted' => array_map($node->isQuoted(...), array_keys($values)),
            'type' => 'in',
            'values' => $field instanceof AllowedField
                ? array_map($field->toStorage(...), $values, $node->getPositions())
                : $values,
        ];
    }

    /**
     * Visit a range node (date:[2025-01-01 TO 2025-12-31], price:[10 TO 50]).
     *
     * @return array<string, mixed>
     */
    #[Override]
    public function visitRange(RangeNode $node): array
    {
        $field = $this->searchParser?->getAllowedField($node->getField());
        $from = $node->getFrom();
        $to = $node->getTo();

        return [
            'field' => $this->getInternalFieldName($node->getField()),
            'from' => $from === null ? null : ($field?->toStorage($from) ?? $from),
            'to' => $to === null ? null : ($field?->toStorage($to) ?? $to),
            'type' => 'range',
        ];
    }

    /**
     * Visit a term node (2025, Coffee, Electronics).
     *
     * @return array<string, mixed>
     */
    #[Override]
    public function visitTerm(TermNode $node): array
    {
        return [
            'quoted' => $node->isQuoted(),
            'type' => 'term',
            'value' => $node->getValue(),
        ];
    }

    /**
     * Visit a unary operator node (NOT test, -title:Coffee).
     *
     * @return array<string, mixed>
     */
    #[Override]
    public function visitUnaryOperator(UnaryOperatorNode $node): array
    {
        return [
            'default' => $node->isDefault(),
            'operand' => $node->getOperand()->accept($this),
            'operator' => $node->getOperator(),
            'type' => 'unary',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function visitWildcard(WildcardNode $node): array
    {
        $field = $node->getField();

        return [
            'field' => $field === null ? null : $this->getInternalFieldName($field),
            'operator' => $node->getOperator(),
            'segments' => $node->getSegments(),
            'type' => 'wildcard',
        ];
    }
}
