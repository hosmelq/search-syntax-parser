<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Date;

/**
 * Shared syntax checks without resolving relative values or choosing a time zone.
 *
 * @internal
 */
final class DateExpression
{
    /**
     * @return null|array<string, string>
     */
    public static function absolute(string $text): null|array
    {
        if (preg_match(
            '/^(?<year>\d{4})(?:-(?<month>\d{2})(?:-(?<day>\d{2})(?:T(?<hour>\d{2})(?::(?<minute>\d{2})(?::(?<second>\d{2})(?:\.(?<fraction>\d+))?)?)?(?<offset>Z|[+-](?<offsetHour>\d{2})(?::(?<offsetMinute>\d{2}))?)?)?)?)?$/D',
            $text,
            $parts,
        ) !== 1) {
            return null;
        }

        if (! checkdate(
            (int) ($parts['month'] ?? 1),
            (int) ($parts['day'] ?? 1),
            (int) $parts['year'],
        )
            || (int) ($parts['hour'] ?? 0) >= 24
            || (int) ($parts['minute'] ?? 0) >= 60
            || (int) ($parts['second'] ?? 0) >= 60
            || (int) ($parts['offsetHour'] ?? 0) >= 24
            || (int) ($parts['offsetMinute'] ?? 0) >= 60) {
            return null;
        }

        return array_filter($parts, is_string(...), ARRAY_FILTER_USE_KEY);
    }

    /**
     * @return null|array<string, string>
     */
    public static function relative(string $text): null|array
    {
        if (preg_match(
            '/^(?:(?<anchor>(?i:now|today|yesterday|tomorrow))(?<offset>[+-]\d{1,6}[yMwdhm])?|(?<bare>[+-]\d{1,6}[yMwdhm]))$/D',
            $text,
            $parts,
        ) !== 1) {
            return null;
        }

        $anchor = mb_strtolower($parts['anchor'] ?? '');
        $offset = ($parts['offset'] ?? '') !== '' ? $parts['offset'] : ($parts['bare'] ?? '');

        if ($anchor !== '' && $anchor !== 'now' && $offset !== ''
            && str_contains('hm', mb_substr($offset, -1))) {
            return null;
        }

        return ['anchor' => $anchor === '' ? 'now' : $anchor, 'offset' => $offset];
    }
}
