<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Support;

use HosmelQ\SearchSyntaxParser\AST\Node\WildcardNode;
use HosmelQ\SearchSyntaxParser\Validation\TextMatch;
use InvalidArgumentException;

final class LikePattern
{
    public static function escape(string $value, string $escape = '!'): string
    {
        if (mb_strlen($escape) !== 1 || $escape === '%' || $escape === '_') {
            throw new InvalidArgumentException(
                'A LIKE escape must be one character other than % or _.',
            );
        }

        return strtr($value, [$escape => $escape.$escape, '%' => $escape.'%', '_' => $escape.'_']);
    }

    public static function forMatch(string $value, TextMatch $match, string $escape = '!'): string
    {
        $value = self::escape($value, $escape);

        return match ($match) {
            TextMatch::Exact => $value,
            TextMatch::Prefix => $value.'%',
            TextMatch::Contains => '%'.$value.'%',
            TextMatch::Words => throw new InvalidArgumentException(
                'Whole-word matching cannot be expressed with LIKE.',
            ),
        };
    }

    public static function fromWildcard(WildcardNode $node, string $escape = '!'): string
    {
        // Validate the escape even when a programmatically constructed node has no segments.
        self::escape('', $escape);

        return implode('%', array_map(
            static fn (string $segment): string => self::escape($segment, $escape),
            $node->getSegments(),
        ));
    }
}
