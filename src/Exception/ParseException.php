<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Exception;

use Exception;

class ParseException extends Exception
{
    /**
     * Empty search query provided.
     */
    public static function emptyQuery(): self
    {
        return new self('Empty search query.');
    }

    /**
     * Expected identifier token.
     */
    public static function expectedIdentifier(): self
    {
        return new self('Expected identifier.');
    }

    /**
     * Unexpected token type encountered.
     */
    public static function expectedToken(string $expected, string $actual): self
    {
        return new self(sprintf('Unexpected token, expected %s but got %s.', $expected, $actual));
    }

    /**
     * Expected value token.
     */
    public static function expectedValue(): self
    {
        return new self('Expected value.');
    }

    /**
     * Improper wildcard usage.
     */
    public static function invalidWildcardUsage(string $field): self
    {
        return new self(sprintf("Wildcard is only supported with ':' for exists queries; use NOT %s:* for negation.", $field));
    }

    /**
     * Unexpected end of input.
     */
    public static function unexpectedEndOfInput(): self
    {
        return new self('Unexpected end of input.');
    }

    /**
     * Unexpected token after query completion.
     */
    public static function unexpectedToken(string $token): self
    {
        return new self(sprintf('Unexpected token after query: %s.', $token));
    }
}
