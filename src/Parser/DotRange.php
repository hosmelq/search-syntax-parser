<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Parser;

/**
 * Recognizes unquoted `a..b` range text, shared by the parser and the string adapter.
 *
 * @internal
 */
final class DotRange
{
    /**
     * Split `a..b` into its bounds, or return null when the text is not a range.
     *
     * A range has exactly one `..` touching no other dot, non-empty sides, and no `/`,
     * so ellipses (`wait...`) and paths (`src/../lib`) stay literal text.
     *
     * @return null|array{string, string}
     */
    public static function split(string $value): null|array
    {
        $parts = explode('..', $value);

        if (count($parts) !== 2) {
            return null;
        }

        [$from, $to] = $parts;

        if (
            $from === ''
            || $to === ''
            || str_ends_with($from, '.')
            || str_starts_with($to, '.')
            || str_contains($value, '/')
        ) {
            return null;
        }

        return [$from, $to];
    }
}
