<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Parser;

use Doctrine\Common\Lexer\Token;
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
use HosmelQ\SearchSyntaxParser\Lexer\TokenType;
use HosmelQ\SearchSyntaxParser\SearchParser;
use UnhandledMatchError;

class Parser
{
    /**
     * The lexer instance for tokenizing the input.
     */
    private readonly SearchLexer $lexer;

    /**
     * Create a new Parser instance.
     */
    public function __construct(private readonly SearchParser $searchParser)
    {
        $this->lexer = new SearchLexer();
    }

    /**
     * Parse input string into an AST.
     *
     * @throws ParseException
     */
    public function parse(string $input): NodeInterface
    {
        $this->lexer->setInput($input);
        $this->lexer->moveNext();

        $firstTokenType = $this->lexer->getLookaheadType();

        if (! $firstTokenType instanceof TokenType) {
            throw ParseException::emptyQuery();
        }

        $ast = $this->parseExpression();

        if ($this->lexer->getLookaheadType() instanceof TokenType) {
            $remaining = $this->lexer->lookahead instanceof Token
                ? $this->lexer->lookahead->value
                : 'Unknown';

            throw ParseException::unexpectedToken($remaining);
        }

        return $ast;
    }

    /**
     * Convert a parsed value to string for term nodes.
     */
    private function convertValueToString(bool|float|int|string $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            is_float($value), is_int($value) => (string) $value,
            is_string($value) => $value,
        };
    }

    /**
     * Check if token is a comparison operator (>, <, >=, <=, !=).
     */
    private function isComparisonOperator(null|TokenType $type): bool
    {
        if (! $type instanceof TokenType) {
            return false;
        }

        return $type->in([
            TokenType::Greater,
            TokenType::GreaterEqual,
            TokenType::Less,
            TokenType::LessEqual,
            TokenType::NotEqual,
        ]);
    }

    /**
     * Check if token can start a factor.
     */
    private function isFactorStart(TokenType $type): bool
    {
        return $type->in([
            TokenType::Date,
            TokenType::Identifier,
            TokenType::Minus,
            TokenType::Not,
            TokenType::Number,
            TokenType::OpenParenthesis,
            TokenType::String,
        ]);
    }

    /**
     * Check if the next token indicates a field-based query.
     */
    private function isFieldQuery(null|TokenType $nextType): bool
    {
        if (TokenType::Colon->is($nextType)) {
            return true;
        }

        return $nextType instanceof TokenType && $this->isComparisonOperator($nextType);
    }

    /**
     * Ensure the next token matches the expected type.
     *
     * @throws ParseException
     */
    private function match(TokenType $expectedType): void
    {
        $actualType = $this->lexer->getLookaheadType();

        if ($actualType !== $expectedType) {
            $actualName = $actualType->name ?? 'NULL';

            throw ParseException::expectedToken($expectedType->name, $actualName);
        }

        $this->lexer->moveNext();
    }

    /**
     * Parse field query with colon syntax (field:value, field:[range], field:*).
     *
     * @throws ParseException
     */
    private function parseColonFieldQuery(string $field): NodeInterface
    {
        $this->lexer->moveNext();

        if (TokenType::OpenBracket->is($this->lexer->getLookaheadType())) {
            return $this->parseRange($field);
        }

        $afterColon = $this->lexer->getLookaheadType();
        $operator = '=';

        if ($afterColon instanceof TokenType && $this->isComparisonOperator($afterColon)) {
            $operator = $this->parseOperator();
        }

        if (TokenType::Wildcard->is($this->lexer->getLookaheadType())) {
            return $this->parseExistsQuery($field, $operator);
        }

        $value = $this->parseValue();

        if (TokenType::Comma->is($this->lexer->getLookaheadType())) {
            $values = [$value];

            while (TokenType::Comma->is($this->lexer->getLookaheadType())) {
                $this->match(TokenType::Comma);

                $values[] = $this->parseValue();
            }

            if (! $this->searchParser->validateField($field, $values)) {
                throw InvalidFieldValueException::invalidValue($field);
            }

            return new InNode($field, $operator, $values);
        }

        if (! $this->searchParser->validateField($field, $value)) {
            throw InvalidFieldValueException::invalidValue($field);
        }

        return new ComparisonNode($field, $operator, $value);
    }

    /**
     * Parse comparison or term expression.
     *
     * @throws ParseException
     */
    private function parseComparison(): NodeInterface
    {
        $type = $this->lexer->getLookaheadType();

        if (TokenType::Identifier->is($type)) {
            return $this->parseIdentifierExpression();
        }

        return $this->parseValueTerm();
    }

    /**
     * Parse field exists query (field:*).
     *
     * @throws ParseException
     */
    private function parseExistsQuery(string $field, string $operator): ExistsNode
    {
        if ($operator !== '=') {
            throw ParseException::invalidWildcardUsage($field);
        }

        $this->lexer->moveNext();

        return new ExistsNode($field);
    }

    /**
     * Parse expression with OR operators (term OR term).
     *
     * @throws ParseException
     */
    private function parseExpression(): NodeInterface
    {
        $left = $this->parseTerm();

        while (TokenType::Or->is($this->lexer->getLookaheadType())) {
            $this->match(TokenType::Or);

            $right = $this->parseTerm();
            $left = new BinaryOperatorNode('OR', $left, $right);
        }

        return $left;
    }

    /**
     * Parse factor (NOT factor, -factor, (expression), comparison).
     *
     * @throws ParseException
     */
    private function parseFactor(): NodeInterface
    {
        $type = $this->lexer->getLookaheadType();

        if (! $type instanceof TokenType) {
            throw ParseException::unexpectedEndOfInput();
        }

        if ($type->is(TokenType::Not)) {
            $this->match(TokenType::Not);

            return new UnaryOperatorNode('NOT', $this->parseFactor());
        }

        if ($type->is(TokenType::Minus)) {
            $this->match(TokenType::Minus);

            return new UnaryOperatorNode('NOT', $this->parseFactor());
        }

        if ($type->is(TokenType::OpenParenthesis)) {
            $this->match(TokenType::OpenParenthesis);

            $expression = $this->parseExpression();

            $this->match(TokenType::CloseParenthesis);

            return $expression;
        }

        return $this->parseComparison();
    }

    /**
     * Parse field-based query (field:value, field>value, field<=100).
     *
     * @throws ParseException
     */
    private function parseFieldQuery(string $field): NodeInterface
    {
        if (! $this->searchParser->isFieldAllowed($field)) {
            throw InvalidFieldException::fieldNotAllowed($field, $this->searchParser->getAllowedFieldNames());
        }

        $type = $this->lexer->getLookaheadType();

        if (TokenType::Colon->is($type)) {
            return $this->parseColonFieldQuery($field);
        }

        $operator = $this->parseOperator();

        if (TokenType::Wildcard->is($this->lexer->getLookaheadType())) {
            throw ParseException::invalidWildcardUsage($field);
        }

        $value = $this->parseValue();

        if (! $this->searchParser->validateField($field, $value)) {
            throw InvalidFieldValueException::invalidValue($field);
        }

        return new ComparisonNode($field, $operator, $value);
    }

    /**
     * Parse identifier expression (field:value, field>value, or term).
     *
     * @throws ParseException
     */
    private function parseIdentifierExpression(): NodeInterface
    {
        $token = $this->lexer->lookahead;

        if (! $token instanceof Token) {
            throw ParseException::expectedIdentifier();
        }

        $field = $token->value;

        $this->lexer->moveNext();

        $nextType = $this->lexer->getLookaheadType();

        if ($this->isFieldQuery($nextType)) {
            return $this->parseFieldQuery($field);
        }

        return new TermNode($field);
    }

    /**
     * Parse comparison operator (>, <, >=, <=, !=).
     */
    private function parseOperator(): string
    {
        $type = $this->lexer->getLookaheadType();

        $operator = match ($type) {
            TokenType::Greater => '>',
            TokenType::GreaterEqual => '>=',
            TokenType::Less => '<',
            TokenType::LessEqual => '<=',
            TokenType::NotEqual => '!=',
            default => throw new UnhandledMatchError('Unexpected token type in parseOperator'),
        };

        $this->lexer->moveNext();

        return $operator;
    }

    /**
     * Parse range expression ([value TO value]).
     *
     * @throws ParseException
     */
    private function parseRange(string $field): RangeNode
    {
        $this->match(TokenType::OpenBracket);

        $from = $this->parseValue();

        $this->match(TokenType::To);

        $to = $this->parseValue();

        $this->match(TokenType::CloseBracket);

        return new RangeNode($field, $from, $to);
    }

    /**
     * Parse term with AND operators (factor AND factor, implicit AND).
     *
     * @throws ParseException
     */
    private function parseTerm(): NodeInterface
    {
        $left = $this->parseFactor();

        while ($this->lexer->lookahead instanceof Token) {
            $type = $this->lexer->getLookaheadType();

            if (TokenType::And->is($type)) {
                $this->match(TokenType::And);

                $right = $this->parseFactor();
                $left = new BinaryOperatorNode('AND', $left, $right);

                continue;
            }

            if ($type instanceof TokenType && $this->isFactorStart($type)) {
                $right = $this->parseFactor();
                $left = new BinaryOperatorNode('AND', $left, $right);

                continue;
            }

            break;
        }

        return $left;
    }

    /**
     * Parse value with type conversion and wildcard support ("text", 123, true, Nike*).
     *
     * @throws ParseException
     */
    private function parseValue(): bool|float|int|string
    {
        $lookahead = $this->lexer->lookahead;

        if (! $lookahead instanceof Token) {
            throw ParseException::expectedValue();
        }

        $type = $this->lexer->getLookaheadType();
        $value = $lookahead->value;

        $this->lexer->moveNext();

        if (TokenType::Wildcard->is($this->lexer->getLookaheadType())) {
            $value .= '*';

            $this->lexer->moveNext();
        }

        if (TokenType::Number->is($type)) {
            if (filter_var($value, FILTER_VALIDATE_INT) !== false) {
                return (int) $value;
            }

            return (float) $value;
        }

        if (TokenType::Identifier->is($type)) {
            return match (mb_strtolower($value)) {
                'false' => false,
                'true' => true,
                default => $value,
            };
        }

        return $value;
    }

    /**
     * Parse standalone value term.
     *
     * @throws ParseException
     */
    private function parseValueTerm(): TermNode
    {
        $value = $this->parseValue();

        return new TermNode($this->convertValueToString($value));
    }
}
