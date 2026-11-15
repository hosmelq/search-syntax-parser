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
        return $this->render($node)[0];
    }

    #[Override]
    public function visitComparison(ComparisonNode $node): string
    {
        if ($node->isDefault()) {
            return '';
        }

        return $this->fieldPrefix($node->getField())
            .($node->getOperator() === '=' ? '' : $node->getOperator())
            .$this->value($node->getValue());
    }

    #[Override]
    public function visitExists(ExistsNode $node): string
    {
        if ($node->isDefault()) {
            return '';
        }

        return $this->fieldPrefix($node->getField()).'*';
    }

    #[Override]
    public function visitIn(InNode $node): string
    {
        if ($node->isDefault()) {
            return '';
        }

        return $this->fieldPrefix($node->getField())
            .($node->getOperator() === '=' ? '' : $node->getOperator())
            .implode(',', array_map($this->value(...), $node->getValues()));
    }

    #[Override]
    public function visitRange(RangeNode $node): string
    {
        return $this->fieldPrefix($node->getField()).'['
            .($node->getFrom() === null ? '*' : $this->value($node->getFrom()))
            .' TO '.($node->getTo() === null ? '*' : $this->value($node->getTo())).']';
    }

    #[Override]
    public function visitTerm(TermNode $node): string
    {
        $value = $node->getValue();

        return $node->isQuoted() || ! $this->canBeBare($value) || str_contains($value, '*')
            ? $this->quote($value) : $value;
    }

    #[Override]
    public function visitUnaryOperator(UnaryOperatorNode $node): string
    {
        if ($node->isDefault()) {
            return '';
        }

        [$text, $operator] = $this->render($node->getOperand());

        if ($text === '') {
            return '';
        }

        return 'NOT '.($operator === null ? $text : '('.$text.')');
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
            : $this->fieldPrefix($field).($node->getOperator() === '=' ? '' : $node->getOperator())
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

    /**
     * Write a field name and its colon, refusing names the query syntax cannot express.
     */
    private function fieldPrefix(string $name): string
    {
        if ($name === '*' || ! $this->canBeBare($name)) {
            throw new LogicException('This field name cannot be expressed by the query syntax.');
        }

        // A name starting like an ISO datetime would absorb `:` and the value into one word.
        return preg_match('/^\d{4}-\d{2}-\d{2}[Tt]\d{2}/u', $name) === 0 ? $name.':' : $name.' :';
    }

    private function quote(string $value): string
    {
        return '"'.strtr($value, ['\\' => '\\\\', '"' => '\\"']).'"';
    }

    /**
     * Render text and its surviving root operator so omitted defaults cannot change precedence.
     *
     * @return array{string, null|string}
     */
    private function render(NodeInterface $node): array
    {
        if (! $node instanceof BinaryOperatorNode) {
            /** @var string $text */
            $text = $node->accept($this);

            return [$text, null];
        }

        $operator = $node->getOperator();

        [$leftText, $leftOperator] = $this->render($node->getLeft());
        [$rightText, $rightOperator] = $this->render($node->getRight());

        if ($leftText === '') {
            return [$rightText, $rightOperator];
        }

        if ($rightText === '') {
            return [$leftText, $leftOperator];
        }

        if ($operator === 'AND' && $leftOperator === 'OR') {
            $leftText = '('.$leftText.')';
        }

        if ($rightOperator !== null && ($operator === 'AND' || $rightOperator === 'OR')) {
            $rightText = '('.$rightText.')';
        }

        return [$leftText.' '.$operator.' '.$rightText, $operator];
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

        // A leading `=` would merge with a preceding `<` or `>` into another operator.
        $bare = $this->canBeBare($value)
            && ! str_contains($value, '*')
            && ! str_starts_with($value, '=')
            && AllowedFieldItemRules::infer($value, false) === $value;

        return $bare ? $value : $this->quote($value);
    }
}
