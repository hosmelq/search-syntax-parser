<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Lexer;

use function Safe\preg_match;

use Doctrine\Common\Lexer\AbstractLexer;
use Doctrine\Common\Lexer\Token;
use Safe\Exceptions\PcreException;

/**
 * @extends AbstractLexer<TokenType, string>
 */
class SearchLexer extends AbstractLexer
{
    /**
     * Get the current token as a TokenType enum.
     */
    public function getCurrentTokenType(): null|TokenType
    {
        if (! $this->token instanceof Token) {
            return null;
        }

        $type = $this->token->type;

        return $type instanceof TokenType ? $type : null;
    }

    /**
     * Get the lookahead token as TokenType enum.
     */
    public function getLookaheadType(): null|TokenType
    {
        if (! $this->lookahead instanceof Token) {
            return null;
        }

        $type = $this->lookahead->type;

        return $type instanceof TokenType ? $type : null;
    }

    /**
     * Lexical patterns that should be captured.
     */
    protected function getCatchablePatterns(): array
    {
        return [
            // ISO 8601 datetime format with timezone
            '\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[Z\+\-]\d*:?\d*',
            // Simple date format YYYY-MM-DD
            '\d{4}-\d{2}-\d{2}',
            // Numbers (integers and decimals)
            '[0-9]+\.?[0-9]*',
            // Double-quoted strings with escape sequences
            '"[^"\\\\]*(?:\\\\.[^"\\\\]*)*"',
            // Single quoted strings with escape sequences
            "'[^'\\\\]*(?:\\\\.[^'\\\\]*)*'",
            // Field identifiers (can contain dots for nested fields)
            '[a-zA-Z_][a-zA-Z0-9_\.]*',
            // Comparison operators (order matters - longer patterns first)
            '>=|<=|!=|>|<|:',
            // Boolean operators (word boundaries ensure complete words)
            '\bAND\b|\bOR\b|\bNOT\b',
            // Range operator
            '\bTO\b',
            // Wildcard character
            '\*',
            // Parentheses and brackets
            '\(|\)|\[|\]',
            // Comma separator
            ',',
            // Minus sign for negation
            '-',
        ];
    }

    /**
     * Patterns that should be skipped.
     */
    protected function getNonCatchablePatterns(): array
    {
        return [
            // Whitespace
            '\s+',
        ];
    }

    /**
     * Determine token type and process value.
     *
     * @param-out string $value
     *
     * @throws PcreException
     */
    protected function getType(mixed &$value): TokenType
    {
        if (! is_string($value)) {
            $value = (string) $value; // @phpstan-ignore-line cast.string
        }

        $type = $this->determineTokenType($value);

        // Process value modifications based on type
        if ($type->is(TokenType::String)) {
            // Remove surrounding quotes
            $value = mb_substr($value, 1, -1);
            // Unescape escaped characters
            $value = str_replace(['\\\\', '\\"', "\\'"], ['\\', '"', "'"], $value);
        }

        return $type;
    }

    /**
     * Determine the token type from the raw value.
     *
     * @throws PcreException
     */
    private function determineTokenType(string $value): TokenType
    {
        // Check for date/datetime patterns
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $value) > 0) {
            return TokenType::Date;
        }

        // Check for numeric values
        if (is_numeric($value)) {
            return TokenType::Number;
        }

        // Check for quoted strings
        if (preg_match('/^["\'].*["\']$/', $value) > 0) {
            return TokenType::String;
        }

        // Check for keywords and operators
        return match (mb_strtoupper($value)) {
            '!=' => TokenType::NotEqual,
            '(' => TokenType::OpenParenthesis,
            ')' => TokenType::CloseParenthesis,
            '*' => TokenType::Wildcard,
            ',' => TokenType::Comma,
            '-' => TokenType::Minus,
            ':' => TokenType::Colon,
            '<' => TokenType::Less,
            '<=' => TokenType::LessEqual,
            '>' => TokenType::Greater,
            '>=' => TokenType::GreaterEqual,
            'AND' => TokenType::And,
            'NOT' => TokenType::Not,
            'OR' => TokenType::Or,
            'TO' => TokenType::To,
            '[' => TokenType::OpenBracket,
            ']' => TokenType::CloseBracket,
            default => TokenType::Identifier
        };
    }
}
