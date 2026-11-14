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
    public function parse(string $input): NodeInterface
    {
        $this->tokens = new SearchLexer()->tokenize($input);
        $this->index = 0;
        $this->clauses = 0;

        if ($this->tokens->types === []) {
            throw ParseException::emptyQuery();
        }

        $ast = $this->parseExpression(0);
        $remaining = $this->tokens->values[$this->index] ?? null;

        if ($remaining !== null) {
            throw ParseException::unexpectedToken($remaining);
        }

        return $ast;
    }

    /**
     * @phpstan-impure
     */
    private function match(TokenType $expected): void
    {
        $actual = $this->tokens->types[$this->index] ?? null;

        if ($actual !== $expected) {
            throw ParseException::expectedToken($expected->name, $actual->name ?? 'NULL');
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
            ($type !== TokenType::Word && $type !== TokenType::String)
            || ($type === TokenType::Word && $value === '*')
        ) {
            throw ParseException::expectedValue();
        }

        if (++$this->clauses > $this->maxClauses) {
            throw new ParseException(
                sprintf('Search query exceeds the maximum of %d clauses.', $this->maxClauses),
            );
        }

        $this->index++;

        $next = $this->tokens->types[$this->index] ?? null;

        if ($type === TokenType::Word && match ($next) {
            TokenType::Colon, TokenType::Greater, TokenType::GreaterEqual, TokenType::Less, TokenType::LessEqual, TokenType::NotEqual => true,
            default => false,
        }) {
            return $this->parseFieldQuery($value);
        }

        return new TermNode($value);
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
            throw ParseException::unexpectedEndOfInput();
        }

        if (match ($type) {
            TokenType::Not, TokenType::Minus, TokenType::OpenParenthesis => true,
            default => false,
        }) {
            if ($depth >= $this->maxDepth) {
                throw new ParseException(
                    sprintf('Search query exceeds the maximum depth of %d.', $this->maxDepth),
                );
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
                throw ParseException::invalidWildcardUsage($field);
            }

            $this->index++;

            return new ExistsNode($field);
        }

        $value = $this->parseValue($field);

        if ($hasColon && ($this->tokens->types[$this->index] ?? null) === TokenType::Comma) {
            $values = [$value];

            do {
                $this->index++;

                $values[] = $this->parseValue($field, count($values));
            } while (($this->tokens->types[$this->index] ?? null) === TokenType::Comma);

            $this->validateValue($field, $values);

            return new InNode($field, $operator, $values);
        }

        $this->validateValue($field, $value);

        return new ComparisonNode($field, $operator, $value);
    }

    /**
     * @phpstan-impure
     */
    private function parseRange(string $field): RangeNode
    {
        $this->index++;

        $from = $this->parseRangeBound($field);
        $separator = $this->tokens->types[$this->index] ?? null;

        if (
            $separator !== TokenType::Word
            || mb_strtoupper($this->tokens->values[$this->index]) !== 'TO'
        ) {
            throw ParseException::expectedToken('TO', $separator->name ?? 'NULL');
        }

        $this->index++;

        $to = $this->parseRangeBound($field);

        $this->match(TokenType::CloseBracket);

        foreach ([$from, $to] as $bound) {
            if ($bound !== null) {
                $this->validateValue($field, $bound);
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

        return $this->parseValue($field);
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
                TokenType::Word, TokenType::String, TokenType::Not, TokenType::Minus, TokenType::OpenParenthesis => true,
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
            throw ParseException::expectedValue();
        }

        $value = $this->tokens->values[$this->index];
        $quoted = $type === TokenType::String;

        if ($type === TokenType::Minus) {
            $next = $this->index + 1;

            if (
                ($this->tokens->types[$next] ?? null) !== TokenType::Word
                || $this->tokens->offsets[$next] !== $this->tokens->offsets[$this->index] + 1
            ) {
                throw ParseException::expectedValue();
            }

            $value .= $this->tokens->values[$next];

            $this->index++;
        } elseif (! match ($type) {
            TokenType::Word, TokenType::String, TokenType::And, TokenType::Or, TokenType::Not => true,
            default => false,
        }) {
            throw ParseException::expectedValue();
        }

        $this->index++;

        $allowed = $this->searchParser->getAllowedField($field);

        return $allowed?->cast($value, $quoted, $index)
            ?? AllowedFieldItemRules::infer($value, $quoted);
    }

    /**
     * @param bool|float|int|list<bool|float|int|string>|string $value
     */
    private function validateValue(string $field, array|bool|float|int|string $value): void
    {
        if (! $this->searchParser->validateField($field, $value)) {
            throw InvalidFieldValueException::invalidValue($field);
        }
    }
}
