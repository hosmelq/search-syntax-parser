<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Date;

use DateTimeImmutable;
use DateTimeZone;
use HosmelQ\SearchSyntaxParser\Exception\InvalidFieldValueException;
use HosmelQ\SearchSyntaxParser\Query\Bounds;
use HosmelQ\SearchSyntaxParser\Query\Clause;
use InvalidArgumentException;

/**
 * Half-open adapter bounds: from is inclusive and to is exclusive.
 */
final readonly class DateBounds
{
    public function __construct(
        public null|DateTimeImmutable $from,
        public null|DateTimeImmutable $to,
    ) {}

    /**
     * @return list<DateBounds>
     */
    public static function forClause(
        Clause $clause,
        DateTimeZone $timezone,
        DateTimeImmutable $now,
    ): array {
        $operator = $clause->negated ? $clause->operator->complement() : $clause->operator;
        $parse = static function (bool|float|int|string $value) use (
            $clause,
            $timezone,
            $now,
        ): DateValue {
            try {
                return DateValue::parse((string) $value, $timezone, $now);
            } catch (InvalidArgumentException) {
                // Validation uses UTC and the system clock, so the caller's zone or clock can move a
                // value outside the supported years.
                throw InvalidFieldValueException::invalidValue($clause->field, $clause->position);
            }
        };

        if ($clause->value instanceof Bounds) {
            return [self::range(
                $clause->value->from === null ? null : $parse($clause->value->from),
                $clause->value->to === null ? null : $parse($clause->value->to),
            )];
        }

        if (is_array($clause->value)) {
            return array_map(
                static fn (bool|float|int|string $value): DateBounds => $parse($value)->bounds(),
                $clause->value,
            );
        }

        /** @var bool|float|int|string $value */
        $value = $clause->value;

        return [$parse($value)->bounds($operator->value)];
    }

    public static function range(null|DateValue $from, null|DateValue $to): self
    {
        return new self($from?->start(), $to?->end());
    }

    /**
     * Null means no stored values; two open bounds cover all present values.
     */
    public function toStorage(DateTimeZone $zone, string $format): null|Bounds
    {
        if (
            $this->from instanceof DateTimeImmutable
            && $this->to instanceof DateTimeImmutable
            && $this->from >= $this->to
        ) {
            return null;
        }

        // Escaped literals do not contribute to stored fractional precision.
        $tokens = '';

        for ($index = 0; $index < mb_strlen($format); $index++) {
            $token = mb_substr($format, $index, 1);

            if ($token === '\\') {
                $index++;
            } else {
                $tokens .= $token;
            }
        }

        $precision = str_contains($tokens, 'u')
            ? 1
            : (str_contains($tokens, 'v') ? 1_000 : 1_000_000);
        $from = $this->from instanceof DateTimeImmutable
            ? $this->roundUp($this->from->setTimezone($zone), $precision)
            : null;
        $to = $this->to instanceof DateTimeImmutable
            ? $this->roundUp($this->to->setTimezone($zone), $precision)
            : null;
        $fromYear = $from instanceof DateTimeImmutable ? (int) $from->format('Y') : null;
        $toYear = $to instanceof DateTimeImmutable ? (int) $to->format('Y') : null;

        if (
            ($fromYear !== null && $fromYear > 9_999)
            || ($toYear !== null && $toYear < 1)
            || ($toYear === 1 && $to->format('m-d H:i:s.u') === '01-01 00:00:00.000000')
        ) {
            return null;
        }

        $from = $fromYear !== null && $fromYear < 1 ? null : $from;
        $to = $toYear !== null && $toYear > 9_999 ? null : $to;

        if (
            $from instanceof DateTimeImmutable
            && $to instanceof DateTimeImmutable
            && $from >= $to
        ) {
            return null;
        }

        return new Bounds($from?->format($format), $to?->format($format));
    }

    private function roundUp(DateTimeImmutable $bound, int $precision): DateTimeImmutable
    {
        $remainder = (int) $bound->format('u') % $precision;

        return $remainder === 0 ? $bound : $bound->setTimezone(new DateTimeZone('UTC'))
            ->modify('+'.($precision - $remainder).' microseconds')
            ->setTimezone($bound->getTimezone());
    }
}
