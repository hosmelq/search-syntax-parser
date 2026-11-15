<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Lexer;

use HosmelQ\SearchSyntaxParser\Exception\ParseException;

/**
 * @internal
 */
final class SearchLexer
{
    private const string DATETIME = '\d{4}-\d{2}-\d{2}[Tt]\d{2}(?::\d{2}(?::\d{2}(?:\.\d+)?)?)?(?:[Zz]|[+-]\d{2}(?::?\d{2})?)?';

    private const string PATTERN = '/\G(\s*+)\K(?:'
        // A word starting with an ISO 8601 datetime may contain colons.
        .self::DATETIME
        .self::WORD_TAIL
        .'(*MARK:w)'
        .'|"[^"\\\\]*+(?:\\\\.[^"\\\\]*+)*+"(?:\*(*MARK:qs)|(*MARK:q))'
        ."|'[^'\\\\]*+(?:\\\\.[^'\\\\]*+)*+'(?:\\*(*MARK:qs)|(*MARK:q))"
        .'|(?:>=|<=|!=|[:<>(),\[\]-])(*MARK:p)'
        .'|(?:[^\s"\'():,<>\[\]!-]|!(?!=))'
        .self::WORD_TAIL
        .'(*MARK:w)'
        .'|["\'](*MARK:e)'
        .')/su';

    /**
     * Rest of a word, unrolled so long words don't spend one PCRE match step per character.
     */
    private const string WORD_TAIL = '[^\s():,<>\[\]!.]*+(?:(?:!(?!=)|\.\.'
        .self::DATETIME.'|\.++)[^\s():,<>\[\]!.]*+)*+';

    /**
     * @throws ParseException
     */
    public function tokenize(string $input): Tokens
    {
        if (preg_match_all(self::PATTERN, $input, $matches, PREG_PATTERN_ORDER) === false) {
            throw preg_last_error() === PREG_BAD_UTF8_ERROR
                ? ParseException::invalidUtf8()
                : ParseException::tokenizationFailed();
        }

        // ASCII byte offsets are already Unicode code point offsets.
        $encoding = preg_match('/[\x80-\xff]/', $input) === 0 ? '8bit' : 'UTF-8';
        $types = [];
        $values = [];
        $offsets = [];
        $ends = [];
        $offset = 0;

        foreach ($matches[0] as $index => $value) {
            $whitespace = $matches[1][$index];

            if ($whitespace !== '') {
                $offset += mb_strlen($whitespace, $encoding);
            }

            $start = $offset;
            $offset += isset($value[1]) ? mb_strlen($value, $encoding) : 1;
            $kind = $matches['MARK'][$index] ?? '';

            if ($kind === 'e') {
                throw ParseException::unterminatedQuote($start);
            }

            $type = match ($kind) {
                'q' => TokenType::String,
                'qs' => TokenType::Wildcard,
                'w' => isset($value[3]) ? TokenType::Word : match ($value) {
                    'AND' => TokenType::And,
                    'OR' => TokenType::Or,
                    'NOT' => TokenType::Not,
                    default => TokenType::Word,
                },
                default => match ($value) {
                    ':' => TokenType::Colon,
                    ',' => TokenType::Comma,
                    '-' => TokenType::Minus,
                    '(' => TokenType::OpenParenthesis,
                    ')' => TokenType::CloseParenthesis,
                    '[' => TokenType::OpenBracket,
                    ']' => TokenType::CloseBracket,
                    '>' => TokenType::Greater,
                    '>=' => TokenType::GreaterEqual,
                    '<' => TokenType::Less,
                    '<=' => TokenType::LessEqual,
                    '!=' => TokenType::NotEqual,
                    default => TokenType::Word,
                },
            };

            if ($type === TokenType::String || $type === TokenType::Wildcard) {
                $value = mb_substr($value, 1, $kind === 'qs' ? -2 : -1);
                $value = strtr($value, ['\\\\' => '\\', '\\"' => '"', "\\'" => "'"]);

                if ($kind === 'qs') {
                    $value .= '*';
                }
            }

            $types[] = $type;
            $values[] = $value;
            $offsets[] = $start;
            $ends[] = $offset;
        }

        return new Tokens(
            $types,
            $values,
            $offsets,
            $ends,
            mb_strlen($input, $encoding),
        );
    }
}
