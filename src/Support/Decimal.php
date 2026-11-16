<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Support;

use Brick\Math\BigDecimal;

/**
 * @internal
 */
final class Decimal
{
    public static function canonical(string $value): string
    {
        return preg_match('/^[+-]?(?:\d+\.?\d*|\.\d+)$/D', $value) === 1
            ? (string) self::from($value)
            : $value;
    }

    public static function from(bool|float|int|string $value): BigDecimal
    {
        if (! is_float($value)) {
            return BigDecimal::of(mb_trim((string) $value, " \t\n\r\v\f"));
        }

        // json_encode() writes the shortest round-trip text only with serialize_precision -1.
        $precision = ini_set('serialize_precision', '-1');

        try {
            return BigDecimal::of(json_encode($value, JSON_THROW_ON_ERROR));
        } finally {
            if ($precision !== false) {
                ini_set('serialize_precision', $precision);
            }
        }
    }
}
