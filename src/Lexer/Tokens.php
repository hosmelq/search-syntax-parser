<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Lexer;

/**
 * Parallel lists avoid allocating an object for every token.
 *
 * @internal
 */
final readonly class Tokens
{
    /**
     * @param list<TokenType> $types
     * @param list<string> $values
     * @param list<int> $offsets
     */
    public function __construct(public array $types, public array $values, public array $offsets) {}
}
