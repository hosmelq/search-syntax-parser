<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Parser;

use HosmelQ\SearchSyntaxParser\AST\Node\BinaryOperatorNode;
use HosmelQ\SearchSyntaxParser\AST\Node\ComparisonNode;
use HosmelQ\SearchSyntaxParser\AST\Node\ExistsNode;
use HosmelQ\SearchSyntaxParser\AST\Node\InNode;
use HosmelQ\SearchSyntaxParser\AST\Node\NodeInterface;
use HosmelQ\SearchSyntaxParser\AST\Node\RangeNode;
use HosmelQ\SearchSyntaxParser\AST\Node\TermNode;
use HosmelQ\SearchSyntaxParser\AST\Node\UnaryOperatorNode;
use HosmelQ\SearchSyntaxParser\AST\Node\WildcardNode;
use HosmelQ\SearchSyntaxParser\Exception\InvalidFieldException;
use HosmelQ\SearchSyntaxParser\Exception\InvalidFieldValueException;
use HosmelQ\SearchSyntaxParser\Exception\ParseException;
use HosmelQ\SearchSyntaxParser\Lexer\SearchLexer;
use HosmelQ\SearchSyntaxParser\Lexer\Tokens;
use HosmelQ\SearchSyntaxParser\Lexer\TokenType;
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\AllowedFieldItemRules;

/**
 * @internal
 */
class Parser
{
    private int $clauses = 0;

    private int $index = 0;

    private string $input = '';

    /**
     * Literal segments of the most recently parsed value, or null for a literal value.
     *
     * @var null|list<string>
     */
    private null|array $segments = null;

    private Tokens $tokens;

    public function __construct(
        private readonly SearchParser $searchParser,
        private readonly int $maxDepth,
        private readonly int $maxClauses,
    ) {}

    /**
     * Parse input into an AST, bounding recursion and tree size.
     *
     * @throws ParseException
     */
    public function parse(string $input, bool $allowEmpty = false): null|NodeInterface
    {
        $this->input = $input;
        $this->tokens = new SearchLexer()->tokenize($input);
        $this->index = 0;
        $this->clauses = 0;

        if ($this->tokens->types === []) {
            if ($allowEmpty) {
                return null;
            }

            throw ParseException::emptyQuery();
        }

        $ast = $this->parseExpression(0);
        $remaining = $this->tokens->values[$this->index] ?? null;

        if ($remaining !== null) {
            throw ParseException::unexpectedToken($remaining, $this->position());
        }

        return $ast;
    }

    private function countClause(int $start): void
    {
        if (++$this->clauses > $this->maxClauses) {
            throw ParseException::maximumClauses($this->maxClauses, $this->position($start));
        }
    }

    /**
     * @phpstan-impure
     */
    private function match(TokenType $expected): void
    {
        $actual = $this->tokens->types[$this->index] ?? null;

        if ($actual !== $expected) {
            throw ParseException::expectedToken(
                $expected->name,
                $actual->name ?? 'NULL',
                $this->position(),
            );
        }

        $this->index++;
    }

    /**
     * @phpstan-impure
     */
    private function parseComparison(): NodeInterface
    {
        $type = $this->tokens->types[$this->index] ?? null;
        $value = $this->tokens->values[$this->index] ?? '';

        if (
            (
                ! in_array($type, [TokenType::Word, TokenType::String, TokenType::Wildcard], true)
            )
            || ($type === TokenType::Word && $value === '*')
        ) {
            throw ParseException::expectedValue($this->position());
        }

        $this->countClause($this->index);

        $this->index++;

        $next = $this->tokens->types[$this->index] ?? null;

        if ($type === TokenType::Word && match ($next) {
            TokenType::Colon, TokenType::Greater, TokenType::GreaterEqual, TokenType::Less, TokenType::LessEqual, TokenType::NotEqual => true,
            default => false,
        }) {
            return $this->parseFieldQuery($value);
        }

        $segments = $this->wildcardSegments($value, $type);

        return $segments === null ? new TermNode($value) : new WildcardNode(null, '=', $segments);
    }

    /**
     * @phpstan-impure
     */
    private function parseExpression(int $depth): NodeInterface
    {
        $left = $this->parseTerm($depth);

        while (($this->tokens->types[$this->index] ?? null) === TokenType::Or) {
            $this->index++;

            $left = new BinaryOperatorNode('OR', $left, $this->parseTerm($depth));
        }

        return $left;
    }

    /**
     * @phpstan-impure
     */
    private function parseFactor(int $depth): NodeInterface
    {
        $type = $this->tokens->types[$this->index] ?? null;

        if ($type === null) {
            throw ParseException::unexpectedEndOfInput($this->position());
        }

        if (match ($type) {
            TokenType::Not, TokenType::Minus, TokenType::OpenParenthesis => true,
            default => false,
        }) {
            if ($depth >= $this->maxDepth) {
                throw ParseException::maximumDepth($this->maxDepth, $this->position());
            }

            $this->index++;

            if ($type !== TokenType::OpenParenthesis) {
                return new UnaryOperatorNode('NOT', $this->parseFactor($depth + 1));
            }

            $expression = $this->parseExpression($depth + 1);

            $this->match(TokenType::CloseParenthesis);

            return $expression;
        }

        return $this->parseComparison();
    }

    /**
     * @phpstan-impure
     */
    private function parseFieldQuery(string $field): NodeInterface
    {
        if (! $this->searchParser->isFieldAllowed($field)) {
            throw InvalidFieldException::fieldNotAllowed(
                $field,
                $this->searchParser->getAllowedFieldNames(),
                $this->position($this->index - 1),
            );
        }

        $hasColon = ($this->tokens->types[$this->index] ?? null) === TokenType::Colon;

        if ($hasColon) {
            $this->index++;

            if (($this->tokens->types[$this->index] ?? null) === TokenType::OpenBracket) {
                return $this->parseRange($field);
            }
        }

        $operator = match ($this->tokens->types[$this->index] ?? null) {
            TokenType::Greater => '>',
            TokenType::GreaterEqual => '>=',
            TokenType::Less => '<',
            TokenType::LessEqual => '<=',
            TokenType::NotEqual => '!=',
            default => '=',
        };

        if ($operator !== '=') {
            $this->index++;
        }

        if (
            ($this->tokens->types[$this->index] ?? null) === TokenType::Word
            && $this->tokens->values[$this->index] === '*'
        ) {
            if (! $hasColon || $operator !== '=') {
                throw ParseException::invalidWildcardUsage($field, $this->position());
            }

            $this->index++;

            return new ExistsNode($field);
        }

        $valueIndex = $this->index;
        $value = $this->parseValue($field);
        $segments = $this->segments;

        if ($hasColon && ($this->tokens->types[$this->index] ?? null) === TokenType::Comma) {
            $values = [$value];
            $patterns = [$segments];
            $starts = [$valueIndex];
            $hasPattern = $segments !== null;

            do {
                $this->index++;

                $starts[] = $this->index;
                $values[] = $this->parseValue($field, count($values));
                $patterns[] = $this->segments;
                $hasPattern = $hasPattern || $this->segments !== null;
            } while (($this->tokens->types[$this->index] ?? null) === TokenType::Comma);

            if ($hasPattern && $operator !== '=' && $operator !== '!=') {
                $patternIndex = array_find_key(
                    $patterns,
                    static fn (null|array $pattern): bool => $pattern !== null,
                );

                throw ParseException::invalidWildcardPattern(
                    $this->position($starts[$patternIndex ?? 0]),
                );
            }

            $this->validateValue($field, $values, $valueIndex);

            if (! $hasPattern) {
                return new InNode($field, $operator, $values);
            }

            $left = null;

            foreach ($values as $index => $item) {
                if ($index > 0) {
                    $this->countClause($starts[$index]);
                }

                $pattern = $patterns[$index];
                $node = $pattern === null
                    ? new ComparisonNode($field, $operator, $item)
                    : new WildcardNode($field, $operator, $pattern);
                $left = $left === null
                    ? $node
                    : new BinaryOperatorNode($operator === '=' ? 'OR' : 'AND', $left, $node);
            }

            return $left;
        }

        if ($segments !== null && $operator !== '=' && $operator !== '!=') {
            throw ParseException::invalidWildcardPattern($this->position($valueIndex));
        }

        $this->validateValue($field, $value, $valueIndex);

        return $segments === null
            ? new ComparisonNode($field, $operator, $value)
            : new WildcardNode($field, $operator, $segments);
    }

    /**
     * @phpstan-impure
     */
    private function parseRange(string $field): RangeNode
    {
        $valueIndex = $this->index;

        $this->index++;

        $from = $this->parseRangeBound($field);
        $separator = $this->tokens->types[$this->index] ?? null;

        if (
            $separator !== TokenType::Word
            || $this->tokens->values[$this->index] !== 'TO'
        ) {
            throw ParseException::expectedToken(
                'TO',
                $separator->name ?? 'NULL',
                $this->position(),
            );
        }

        $this->index++;

        $to = $this->parseRangeBound($field);

        $this->match(TokenType::CloseBracket);

        foreach ([$from, $to] as $bound) {
            if ($bound !== null) {
                $this->validateValue($field, $bound, $valueIndex);
            }
        }

        return new RangeNode($field, $from ?? '*', $to ?? '*');
    }

    /**
     * Parse a range bound, returning null for an open `*` bound, which is neither cast nor validated.
     *
     * @phpstan-impure
     */
    private function parseRangeBound(string $field): null|bool|float|int|string
    {
        if (
            ($this->tokens->types[$this->index] ?? null) === TokenType::Word
            && $this->tokens->values[$this->index] === '*'
        ) {
            $this->index++;

            return null;
        }

        $start = $this->index;
        $value = $this->parseValue($field);

        if ($this->segments !== null || (is_string($value) && str_contains($value, '*'))) {
            throw ParseException::invalidWildcardPattern($this->position($start));
        }

        return $value;
    }

    /**
     * @phpstan-impure
     */
    private function parseTerm(int $depth): NodeInterface
    {
        $left = $this->parseFactor($depth);

        while (true) {
            $type = $this->tokens->types[$this->index] ?? null;

            if ($type === TokenType::And) {
                $this->index++;
            } elseif (! match ($type) {
                TokenType::Word, TokenType::String, TokenType::Wildcard, TokenType::Not, TokenType::Minus, TokenType::OpenParenthesis => true,
                default => false,
            }) {
                break;
            }

            $left = new BinaryOperatorNode('AND', $left, $this->parseFactor($depth));
        }

        return $left;
    }

    /**
     * @phpstan-impure
     */
    private function parseValue(string $field, int $index = 0): bool|float|int|string
    {
        $type = $this->tokens->types[$this->index] ?? null;

        if ($type === null) {
            throw ParseException::expectedValue($this->position());
        }

        $value = $this->tokens->values[$this->index];

        if ($type === TokenType::Word && $value === '*') {
            throw ParseException::invalidWildcardUsage($field, $this->position());
        }

        $quoted = $type === TokenType::String;

        if ($type === TokenType::Minus) {
            $next = $this->index + 1;

            if (
                ($this->tokens->types[$next] ?? null) !== TokenType::Word
                || $this->tokens->offsets[$next] !== $this->tokens->offsets[$this->index] + 1
            ) {
                throw ParseException::expectedValue($this->position());
            }

            $value .= $this->tokens->values[$next];

            $this->index++;
        } elseif (! match ($type) {
            TokenType::Word, TokenType::String, TokenType::Wildcard, TokenType::And, TokenType::Or, TokenType::Not => true,
            default => false,
        }) {
            throw ParseException::expectedValue($this->position());
        }

        $this->index++;

        $this->segments = $this->wildcardSegments($value, $type);
        $allowed = $this->searchParser->getAllowedField($field);

        return $allowed?->cast($value, $quoted, $index)
            ?? AllowedFieldItemRules::infer($value, $quoted);
    }

    private function position(null|int $index = null): int
    {
        $offset = $this->tokens->offsets[$index ?? $this->index] ?? null;

        return $offset === null
            ? mb_strlen($this->input)
            : mb_strlen(mb_strcut($this->input, 0, $offset));
    }

    /**
     * @param bool|float|int|list<bool|float|int|string>|string $value
     */
    private function validateValue(
        string $field,
        array|bool|float|int|string $value,
        int $start,
    ): void {
        if (! $this->searchParser->validateField($field, $value)) {
            throw InvalidFieldValueException::invalidValue($field, $this->position($start));
        }
    }

    /**
     * @return null|list<string>
     */
    private function wildcardSegments(string $value, TokenType $type): null|array
    {
        if ($type === TokenType::Wildcard) {
            return [mb_substr($value, 0, -1), ''];
        }

        if ($type === TokenType::String || ! str_contains($value, '*')) {
            return null;
        }

        $segments = preg_split('/\*+/', $value);

        if ($segments === false) {
            throw ParseException::tokenizationFailed();
        }

        return $segments;
    }
}
