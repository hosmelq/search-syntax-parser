<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Lexer;

/**
 * Parallel lists avoid allocating an object for every token.
 * Offsets and exclusive ends use Unicode code points, as does the input length.
 *
 * @internal
 */
final readonly class Tokens
{
    /**
     * @param list<TokenType> $types
     * @param list<string> $values
     * @param list<int> $offsets
     * @param list<int> $ends
     */
    public function __construct(
        public array $types,
        public array $values,
        public array $offsets,
        public array $ends,
        public int $length,
    ) {}
}
