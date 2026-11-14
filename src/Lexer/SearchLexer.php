<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Lexer;

use function Safe\preg_match_all;

use HosmelQ\SearchSyntaxParser\Exception\ParseException;
use Safe\Exceptions\PcreException;

/**
 * @internal
 */
final class SearchLexer
{
    private const string PATTERN = '/\G(\s*+)\K(?:'
        // A word starting with an ISO 8601 datetime may contain colons.
        .'\d{4}-\d{2}-\d{2}[Tt]\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?(?:[Zz]|[+-]\d{2}(?::?\d{2})?)?'
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
    private const string WORD_TAIL = '[^\s():,<>\[\]!]*+(?:!(?!=)[^\s():,<>\[\]!]*+)*+';

    /**
     * @throws ParseException
     */
    public function tokenize(string $input): Tokens
    {
        try {
            preg_match_all(self::PATTERN, $input, $matches, PREG_PATTERN_ORDER);
        } catch (PcreException) {
            throw new ParseException(preg_last_error() === PREG_BAD_UTF8_ERROR
                ? 'Search query must be valid UTF-8.'
                : 'Search query is too complex to tokenize.');
        }

        // PHPStan cannot infer match shapes for patterns with (*MARK) verbs; every alternative sets a mark.
        /** @var array{0: list<string>, 1: list<string>, MARK?: array<int, string>} $matches */

        // Capture whitespace and accumulate byte lengths instead of allocating offset tuples.
        $types = [];
        $values = [];
        $offsets = [];
        $offset = 0;

        foreach ($matches[0] as $index => $value) {
            $offset += mb_strlen($matches[1][$index], '8bit');
            $start = $offset;
            $offset += mb_strlen($value, '8bit');
            $kind = $matches['MARK'][$index] ?? '';

            if ($kind === 'e') {
                throw new ParseException('Unterminated quoted string.');
            }

            $type = match ($kind) {
                'q', 'qs' => TokenType::String,
                'w' => isset($value[3]) ? TokenType::Word : match (mb_strtoupper($value)) {
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

            if ($type === TokenType::String) {
                $value = mb_substr($value, 1, $kind === 'qs' ? -2 : -1);
                $value = strtr($value, ['\\\\' => '\\', '\\"' => '"', "\\'" => "'"]);

                if ($kind === 'qs') {
                    $value .= '*';
                }
            }

            $types[] = $type;
            $values[] = $value;
            $offsets[] = $start;
        }

        return new Tokens($types, $values, $offsets);
    }
}
