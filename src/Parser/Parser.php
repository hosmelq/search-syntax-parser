<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Parser;

use Doctrine\Common\Lexer\Token;
use HosmelQ\SearchSyntaxParser\AST\Node\BinaryOperatorNode;
use HosmelQ\SearchSyntaxParser\AST\Node\ComparisonNode;
use HosmelQ\SearchSyntaxParser\AST\Node\ExistsNode;
use HosmelQ\SearchSyntaxParser\AST\Node\NodeInterface;
use HosmelQ\SearchSyntaxParser\AST\Node\RangeNode;
use HosmelQ\SearchSyntaxParser\AST\Node\TermNode;
use HosmelQ\SearchSyntaxParser\AST\Node\UnaryOperatorNode;
use HosmelQ\SearchSyntaxParser\Configuration\SearchConfiguration;
use HosmelQ\SearchSyntaxParser\Exception\ParseException;
use HosmelQ\SearchSyntaxParser\Lexer\SearchLexer;
use HosmelQ\SearchSyntaxParser\Lexer\TokenType;
use UnhandledMatchError;

class Parser
{
    /**
     * Current count of parsed conditions.
     */
    private int $conditionCount = 0;

    /**
     * The lexer instance for tokenizing the input.
     */
    private readonly SearchLexer $lexer;

    /**
     * Current nesting depth for parentheses.
     */
    private int $nestingDepth = 0;

    /**
     * Create a new Parser instance.
     */
    public function __construct(private readonly SearchConfiguration $configuration)
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
        $this->validateInput($input);

        $this->lexer->setInput($input);
        $this->lexer->moveNext();

        $this->conditionCount = 0;
        $this->nestingDepth = 0;

        $firstTokenType = $this->lexer->getLookaheadType();

        if (! $firstTokenType instanceof TokenType) {
            throw ParseException::emptyQuery();
        }

        $ast = $this->parseExpression();

        // Check for remaining tokens after parsing the expression
        if ($this->lexer->getLookaheadType() instanceof TokenType) {
            $remaining = $this->lexer->lookahead instanceof Token ? $this->lexer->lookahead->value : 'Unknown';

            throw ParseException::unexpectedToken($remaining);
        }

        return $ast;
    }

    /**
     * Enforce maximum number of conditions.
     *
     * @throws ParseException
     */
    private function checkConditionCount(): void
    {
        $maxConditions = $this->configuration->getLimit('max_conditions');

        if (is_int($maxConditions) && $maxConditions > 0 && $this->conditionCount >= $maxConditions) {
            throw ParseException::exceededConditions($maxConditions);
        }
    }

    /**
     * Enforce maximum nesting depth of parentheses/groups.
     *
     * @throws ParseException
     */
    private function checkNestingDepth(): void
    {
        $maxDepth = $this->configuration->getLimit('max_nesting_depth');

        if (is_int($maxDepth) && $maxDepth > 0 && $this->nestingDepth >= $maxDepth) {
            throw ParseException::exceededNestingDepth($maxDepth);
        }
    }

    /**
     * Convert a parsed value to string for term nodes.
     *
     * Term nodes represent free-text terms, so their values are always stored
     * as strings (even when the literal looked numeric). In contrast, field
     * comparisons keep the original numeric types when applicable.
     */
    private function convertValueToString(float|int|string $value): string
    {
        return match (true) {
            is_string($value) => $value,
            is_float($value), is_int($value) => (string) $value,
        };
    }

    /**
     * True if token is a comparison operator (no colon).
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
     * True if the token can start a factor (for implicit AND determination).
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
        return $nextType === TokenType::Colon || ($nextType instanceof TokenType && $this->isComparisonOperator($nextType));
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
     * Parse a colon-based field query (field:value, field:[range], field:*).
     *
     * @throws ParseException
     */
    private function parseColonFieldQuery(string $field): NodeInterface
    {
        $this->lexer->moveNext(); // consume ':'

        // Check for range syntax: field:[from TO to]
        if ($this->lexer->getLookaheadType() === TokenType::OpenBracket) {
            $range = $this->parseRange($field);

            ++$this->conditionCount;

            return $range;
        }

        // Check for operator after colon: field:>value
        $operator = '=';
        $afterColon = $this->lexer->getLookaheadType();

        if ($afterColon instanceof TokenType && $this->isComparisonOperator($afterColon)) {
            $operator = $this->parseOperator();
        }

        // Check for exists query: field:*
        if ($this->lexer->getLookaheadType() === TokenType::Wildcard) {
            return $this->parseExistsQuery($field, $operator);
        }

        // Regular field comparison
        $value = $this->parseValue();

        if (! $this->configuration->validateField($field, $value)) {
            throw ParseException::invalidFieldValue($field);
        }

        ++$this->conditionCount;

        return new ComparisonNode($field, $operator, $value);
    }

    /**
     * comparison := IDENT ((':' [op]) | op)? (value | range)
     * If IDENT is not followed by ':'/operator, treat it as a Term.
     *
     * @throws ParseException
     */
    private function parseComparison(): NodeInterface
    {
        $this->checkConditionCount();

        $type = $this->lexer->getLookaheadType();

        if ($type === TokenType::Identifier) {
            return $this->parseIdentifierExpression();
        }

        return $this->parseValueTerm();
    }

    /**
     * Parse an exists query (field:*).
     *
     * @throws ParseException
     */
    private function parseExistsQuery(string $field, string $operator): ExistsNode
    {
        if ($operator !== '=') {
            throw ParseException::invalidWildcardUsage($field);
        }

        $this->lexer->moveNext(); // consume '*'
        ++$this->conditionCount;

        return new ExistsNode($field);
    }

    /**
     * expression := term (OR term)*
     * Handles OR precedence (lowest).
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
     * factor := NOT factor | '-' factor | '(' expression ')' | comparison.
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
            $this->checkNestingDepth();

            ++$this->nestingDepth;

            $this->match(TokenType::OpenParenthesis);

            $expr = $this->parseExpression();

            $this->match(TokenType::CloseParenthesis);

            --$this->nestingDepth;

            return $expr;
        }

        return $this->parseComparison();
    }

    /**
     * Parse a field-based query (field:value, field>value, etc.).
     *
     * @throws ParseException
     */
    private function parseFieldQuery(string $field): NodeInterface
    {
        if (! $this->configuration->isFieldAllowed($field)) {
            throw ParseException::fieldNotAllowed($field);
        }

        $type = $this->lexer->getLookaheadType();

        if (TokenType::Colon->is($type)) {
            return $this->parseColonFieldQuery($field);
        }

        // Direct comparison operator (field>value)
        $operator = $this->parseOperator();

        // Check for exists query: field>* (not allowed)
        if ($this->lexer->getLookaheadType() === TokenType::Wildcard) {
            throw ParseException::invalidWildcardUsage($field);
        }

        $value = $this->parseValue();

        if (! $this->configuration->validateField($field, $value)) {
            throw ParseException::invalidFieldValue($field);
        }

        ++$this->conditionCount;

        return new ComparisonNode($field, $operator, $value);
    }

    /**
     * Parse an identifier-based expression (field:value, field>value, or standalone term).
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

        // Check if this is a field-based query
        if ($this->isFieldQuery($nextType)) {
            return $this->parseFieldQuery($field);
        }

        // Standalone identifier becomes a term
        ++$this->conditionCount;

        return new TermNode($field);
    }

    /**
     * op := '>' | '<' | '>=' | '<=' | '!='
     * Returns symbolic operator for comparison nodes.
     *
     * @throws ParseException
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
     * range := '[' value 'TO' value ']'.
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
     * term := factor ((AND | implicit_and) factor)*
     * Handles AND precedence and implicit AND (space).
     *
     * @throws ParseException
     */
    private function parseTerm(): NodeInterface
    {
        $left = $this->parseFactor();

        while ($this->lexer->lookahead instanceof Token) {
            $type = $this->lexer->getLookaheadType();

            if ($type === TokenType::And) {
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
     * Parse a value token and handle trailing wildcard (e.g., "Nike*").
     * Returns typed values for numbers (int|float), or string otherwise.
     *
     * @throws ParseException
     */
    private function parseValue(): float|int|string
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

        return $value;
    }

    /**
     * Parse a value-only term (not field-based).
     *
     * @throws ParseException
     */
    private function parseValueTerm(): TermNode
    {
        $value = $this->parseValue();

        ++$this->conditionCount;

        return new TermNode($this->convertValueToString($value));
    }

    /**
     * Validate input constraints before parsing.
     *
     * @throws ParseException
     */
    private function validateInput(string $input): void
    {
        $maxLength = $this->configuration->getLimit('max_query_length');

        if (is_int($maxLength) && $maxLength > 0 && mb_strlen($input) > $maxLength) {
            throw ParseException::exceededQueryLength($maxLength);
        }
    }
}
