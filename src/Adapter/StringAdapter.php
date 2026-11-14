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
use HosmelQ\SearchSyntaxParser\AST\Visitor\VisitorInterface;
use HosmelQ\SearchSyntaxParser\Exception\ParseException;
use HosmelQ\SearchSyntaxParser\Lexer\SearchLexer;
use HosmelQ\SearchSyntaxParser\Lexer\TokenType;
use HosmelQ\SearchSyntaxParser\Validation\AllowedFieldItemRules;
use LogicException;
use Override;

final readonly class StringAdapter implements QueryAdapterInterface, VisitorInterface
{
    #[Override]
    public function build(NodeInterface $ast): string
    {
        /** @var string */
        return $ast->accept($this);
    }

    #[Override]
    public function visitBinaryOperator(BinaryOperatorNode $node): string
    {
        $operator = $node->getOperator();
        $left = $node->getLeft();
        $right = $node->getRight();
        $leftText = $this->build($left);
        $rightText = $this->build($right);

        if (
            $operator === 'AND'
            && $left instanceof BinaryOperatorNode
            && $left->getOperator() === 'OR'
        ) {
            $leftText = '('.$leftText.')';
        }

        if (
            $right instanceof BinaryOperatorNode
            && ($operator === 'AND' || $right->getOperator() === 'OR')
        ) {
            $rightText = '('.$rightText.')';
        }

        return $leftText.' '.$operator.' '.$rightText;
    }

    #[Override]
    public function visitComparison(ComparisonNode $node): string
    {
        return $node->getField().':'.($node->getOperator() === '=' ? '' : $node->getOperator())
            .$this->value($node->getValue());
    }

    #[Override]
    public function visitExists(ExistsNode $node): string
    {
        return $node->getField().':*';
    }

    #[Override]
    public function visitIn(InNode $node): string
    {
        return $node->getField().':'.($node->getOperator() === '=' ? '' : $node->getOperator())
            .implode(',', array_map($this->value(...), $node->getValues()));
    }

    #[Override]
    public function visitRange(RangeNode $node): string
    {
        return $node->getField().':['
            .($node->getFrom() === '*' ? '*' : $this->value($node->getFrom()))
            .' TO '.($node->getTo() === '*' ? '*' : $this->value($node->getTo())).']';
    }

    #[Override]
    public function visitTerm(TermNode $node): string
    {
        return $this->value($node->getValue());
    }

    #[Override]
    public function visitUnaryOperator(UnaryOperatorNode $node): string
    {
        $operand = $node->getOperand();
        $text = $this->build($operand);

        return 'NOT '.($operand instanceof BinaryOperatorNode ? '('.$text.')' : $text);
    }

    #[Override]
    public function visitWildcard(WildcardNode $node): string
    {
        $segments = $node->getSegments();

        foreach (array_slice($segments, 1, -1) as $segment) {
            if ($segment === '') {
                throw new LogicException(
                    'This wildcard pattern cannot be expressed by the query syntax.',
                );
            }
        }

        $pattern = implode('*', $segments);

        if (
            count($segments) < 2
            || ! $this->canBeBare($pattern)
            || $pattern === '*'
            || array_any(
                $segments,
                static fn (string $segment): bool => str_contains($segment, '*'),
            )
        ) {
            if (count($segments) !== 2 || $segments[1] !== '') {
                throw new LogicException(
                    'This wildcard pattern cannot be expressed by the query syntax.',
                );
            }

            $pattern = $this->quote($segments[0]).'*';
        }

        $field = $node->getField();

        return (
            $field === null
            ? ''
            : $field.':'.($node->getOperator() === '=' ? '' : $node->getOperator())
        )
            .$pattern;
    }

    private function canBeBare(string $value): bool
    {
        try {
            $tokens = new SearchLexer()->tokenize($value);
        } catch (ParseException) {
            return false;
        }

        return $tokens->types === [TokenType::Word] && $tokens->values === [$value];
    }

    private function quote(string $value): string
    {
        return '"'.strtr($value, ['\\' => '\\\\', '"' => '\\"']).'"';
    }

    private function value(bool|float|int|string $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            if (! is_finite($value)) {
                throw new LogicException(
                    'Non-finite floats cannot be expressed by the query syntax.',
                );
            }

            $text = json_encode($value, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);

            if (str_contains($text, 'e')) {
                [$mantissa, $exponent] = explode('e', $text);

                $negative = str_starts_with($mantissa, '-');
                $mantissa = mb_ltrim($mantissa, '-');
                $decimalPoint = mb_strpos($mantissa, '.');
                $point = (
                    $decimalPoint === false
                    ? mb_strlen($mantissa)
                    : $decimalPoint
                ) + (int) $exponent;
                $digits = str_replace('.', '', $mantissa);
                $text = match (true) {
                    $point <= 0 => '0.'.str_repeat('0', -$point).$digits,
                    $point >= mb_strlen($digits) => $digits
                        .str_repeat('0', $point - mb_strlen($digits))
                        .'.0',
                    default => mb_substr($digits, 0, $point).'.'.mb_substr($digits, $point),
                };
                $text = ($negative ? '-' : '').$text;
            }

            return str_contains($text, '.') ? $text : $text.'.0';
        }

        return $this->canBeBare($value) && ! str_contains($value, '*')
            && AllowedFieldItemRules::infer($value, false) === $value
            ? $value : $this->quote($value);
    }
}
