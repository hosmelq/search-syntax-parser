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
use HosmelQ\SearchSyntaxParser\Validation\Field;
use HosmelQ\SearchSyntaxParser\Validation\TextMatch;
use Override;

readonly class ArrayAdapter extends AbstractAdapter
{
    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function build(NodeInterface $ast): array
    {
        /**
         * @var array<string, mixed>
         */
        return $ast->accept($this);
    }

    /**
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
            'values' => $field instanceof Field
                ? array_map($field->toStorage(...), $values, $node->getPositions())
                : $values,
        ];
    }

    /**
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
            'from' => $from === null ? null : $field?->toStorage($from) ?? $from,
            'to' => $to === null ? null : $field?->toStorage($to) ?? $to,
            'type' => 'range',
        ];
    }

    /**
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
