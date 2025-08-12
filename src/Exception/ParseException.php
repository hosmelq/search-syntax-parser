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
     * Maximum number of conditions exceeded.
     */
    public static function exceededConditions(int $max): self
    {
        return new self(sprintf('Maximum number of conditions (%d) exceeded.', $max));
    }

    /**
     * Maximum nesting depth exceeded.
     */
    public static function exceededNestingDepth(int $max): self
    {
        return new self(sprintf('Maximum nesting depth of %d exceeded.', $max));
    }

    /**
     * Maximum query length exceeded.
     */
    public static function exceededQueryLength(int $max): self
    {
        return new self(sprintf('Query exceeds maximum length of %d characters.', $max));
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
     * Field name isn't allowed.
     */
    public static function fieldNotAllowed(string $field): self
    {
        return new self(sprintf("Field '%s' is not allowed.", $field));
    }

    /**
     * Invalid field value.
     */
    public static function invalidFieldValue(string $field): self
    {
        return new self(sprintf("Invalid value for field '%s'", $field));
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
