<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Exception;

class ParseException extends SearchSyntaxException
{
    public static function expectedToken(
        string $expected,
        string $actual,
        null|int $position = null,
    ): self {
        return new self(
            sprintf('Unexpected token, expected %s but got %s.', $expected, $actual),
            ErrorReason::ExpectedToken,
            ['expected' => $expected, 'actual' => $actual],
            $position,
        );
    }

    public static function expectedValue(null|int $position = null): self
    {
        return new self('Expected value.', ErrorReason::ExpectedValue, [], $position);
    }

    public static function invalidUtf8(): self
    {
        return new self('Search query must be valid UTF-8.', ErrorReason::InvalidUtf8, []);
    }

    public static function invalidWildcardPattern(null|int $position = null): self
    {
        return new self(
            "Wildcard patterns are only supported with '=' or '!=' and cannot be range bounds.",
            ErrorReason::InvalidWildcardPattern,
            [],
            $position,
        );
    }

    public static function invalidWildcardUsage(string $field, null|int $position = null): self
    {
        return new self(sprintf(
            "Wildcard is only supported with ':' for exists queries; use NOT %s:* for negation.",
            $field,
        ), ErrorReason::InvalidWildcardUsage, ['field' => $field], $position);
    }

    public static function leadingWildcardNotAllowed(int $position): self
    {
        return new self(
            'Leading wildcards are not allowed for free-text terms.',
            ErrorReason::LeadingWildcardNotAllowed,
            [],
            $position,
        );
    }

    public static function maximumClauses(int $limit, int $position): self
    {
        return new self(
            sprintf('Search query exceeds the maximum of %d clauses.', $limit),
            ErrorReason::MaximumClauses,
            ['limit' => $limit],
            $position,
        );
    }

    public static function maximumDepth(int $limit, int $position): self
    {
        return new self(
            sprintf('Search query exceeds the maximum depth of %d.', $limit),
            ErrorReason::MaximumDepth,
            ['limit' => $limit],
            $position,
        );
    }

    public static function maximumLength(int $limit): self
    {
        return new self(
            sprintf('Search query exceeds the maximum length of %d characters.', $limit),
            ErrorReason::MaximumLength,
            ['limit' => $limit],
            $limit,
        );
    }

    public static function tokenizationFailed(): self
    {
        return new self(
            'Search query is too complex to tokenize.',
            ErrorReason::TokenizationFailed,
            [],
        );
    }

    public static function unexpectedEndOfInput(null|int $position = null): self
    {
        return new self(
            'Unexpected end of input.',
            ErrorReason::UnexpectedEndOfInput,
            [],
            $position,
        );
    }

    public static function unexpectedToken(string $token, null|int $position = null): self
    {
        return new self(
            sprintf('Unexpected token after query: %s.', $token),
            ErrorReason::UnexpectedToken,
            ['token' => $token],
            $position,
        );
    }

    public static function unterminatedQuote(int $position): self
    {
        return new self(
            'Unterminated quoted string.',
            ErrorReason::UnterminatedQuote,
            [],
            $position,
        );
    }
}
