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
use HosmelQ\SearchSyntaxParser\Exception\ParseException;
use HosmelQ\SearchSyntaxParser\Lexer\SearchLexer;
use HosmelQ\SearchSyntaxParser\Lexer\TokenType;
use HosmelQ\SearchSyntaxParser\Parser\DotRange;
use HosmelQ\SearchSyntaxParser\Sorting\Sort;
use HosmelQ\SearchSyntaxParser\Validation\Field;
use HosmelQ\SearchSyntaxParser\Validation\FieldItemRules;
use LogicException;
use Override;

final readonly class StringAdapter extends AbstractAdapter
{
    #[Override]
    public function build(null|NodeInterface $ast): string
    {
        [$text, $operator] = $ast instanceof NodeInterface ? $this->render($ast) : ['', null];

        $settings = [];

        foreach ($this->searchParser?->getTypedDirectives() ?? [] as $name => $value) {
            $settings[] = $this->comparison(
                (string) $name,
                '=',
                $this->value($value, field: $this->searchParser?->getAllowedField((string) $name)),
            );
        }

        $sort = $this->searchParser?->getSort();

        if ($sort instanceof Sort && ! $sort->isDefault()) {
            $settings[] = $this->fieldPrefix('sort').$this->value(
                $sort->getName().'-'.$sort->getDirection(),
            );
        }

        if ($settings === []) {
            return $text;
        }

        if ($text !== '') {
            array_unshift($settings, $operator === 'OR' ? '('.$text.')' : $text);
        }

        return implode(' AND ', $settings);
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

        $value = $this->value(
            $node->getValue(),
            $node->isQuoted(),
            $this->searchParser?->getAllowedField($node->getField()),
        );

        return $this->comparison($node->getField(), $node->getOperator(), $value);
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

        $field = $this->searchParser?->getAllowedField($node->getField());

        return $this->comparison(
            $node->getField(),
            $node->getOperator(),
            implode(',', array_map(
                fn (bool|float|int|string $value, int $index): string => $this->value(
                    $value,
                    $node->isQuoted($index),
                    $field,
                    $index,
                ),
                $node->getValues(),
                array_keys($node->getValues()),
            )),
        );
    }

    #[Override]
    public function visitRange(RangeNode $node): string
    {
        $field = $this->searchParser?->getAllowedField($node->getField());
        $from = $node->getFrom();
        $to = $node->getTo();

        return $this->fieldPrefix($node->getField()).'['
            .($from === null ? '*' : $this->value($from, false, $field))
            .' TO '.($to === null ? '*' : $this->value($to, false, $field)).']';
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
            || ($node->getField() !== null && ! $this->isBareValue($pattern))
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

    private function canBeBare(string $value, bool $keywords = false): bool
    {
        try {
            $tokens = new SearchLexer()->tokenize($value);
        } catch (ParseException) {
            return false;
        }

        return count($tokens->types) === 1
            && ($tokens->types[0] === TokenType::Word || ($keywords && in_array(
                $tokens->types[0],
                [TokenType::And, TokenType::Or, TokenType::Not],
                true,
            )))
            && $tokens->values === [$value];
    }

    private function comparison(string $field, string $operator, string $value): string
    {
        return $this->fieldPrefix($field)
            .($operator === '=' ? '' : $operator)
            .(($operator === '<' || $operator === '>') && str_starts_with($value, '=') ? ' ' : '')
            .$value;
    }

    private function fieldPrefix(string $name): string
    {
        if ($name === '*' || ! $this->canBeBare($name)) {
            throw new LogicException('This field name cannot be expressed by the query syntax.');
        }

        // A name starting like an ISO datetime would absorb `:` and the value into one word.
        return preg_match('/^\d{4}-\d{2}-\d{2}[Tt]\d{2}/u', $name) === 0 ? $name.':' : $name.' :';
    }

    private function isBareValue(string $value): bool
    {
        // The parser reads a leading minus as its own token, then splits the rest.
        if (DotRange::split(
            str_starts_with($value, '-') ? mb_substr($value, 1) : $value,
        ) !== null) {
            return false;
        }

        return $this->canBeBare($value, true)
            || (str_starts_with($value, '-') && $this->canBeBare(mb_substr($value, 1)));
    }

    private function quote(string $value): string
    {
        return '"'.strtr($value, ['\\' => '\\\\', '"' => '\\"']).'"';
    }

    /**
     * @return array{string, null|string}
     */
    private function render(NodeInterface $node): array
    {
        if (! $node instanceof BinaryOperatorNode) {
            /**
             * @var string $text
             */
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

    private function value(
        bool|float|int|string $value,
        bool $quoted = false,
        null|Field $field = null,
        int $index = 0,
    ): string {
        if (is_bool($value)) {
            $text = $value ? 'true' : 'false';

            return $quoted || $field?->hasAlias($text) === true ? $this->quote($text) : $text;
        }

        if (is_int($value)) {
            $text = (string) $value;

            return $quoted || $field?->hasAlias($text) === true ? $this->quote($text) : $text;
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

            $text = str_contains($text, '.') ? $text : $text.'.0';

            return $quoted || $field?->hasAlias($text) === true ? $this->quote($text) : $text;
        }

        // Unquoted text must read back as the same value: through the field's own casting
        // when it is configured, otherwise through untyped inference. Alias tokens stay quoted,
        // and are detected without running alias closures.
        $bare = ! str_contains($value, '*')
            && $this->isBareValue($value)
            && (
                $field instanceof Field
                ? ! $field->hasAlias($value) && $field->cast($value, false, $index) === $value
                : FieldItemRules::infer($value, false) === $value
            );

        return ! $quoted && $bare ? $value : $this->quote($value);
    }
}
