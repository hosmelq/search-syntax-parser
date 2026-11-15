<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Parser;

/**
 * @internal
 */
final class DotRange
{
    /**
     * Ellipses and paths remain literal text instead of compact ranges.
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
