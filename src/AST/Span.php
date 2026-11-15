<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\AST;

/**
 * Offsets and lengths count Unicode code points, not bytes or graphemes.
 */
final readonly class Span
{
    public function __construct(public int $offset, public int $length) {}
}
