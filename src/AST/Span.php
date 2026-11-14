<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\AST;

/**
 * Source extent in Unicode code points relative to the exact query string.
 */
final readonly class Span
{
    public function __construct(public int $offset, public int $length) {}
}
