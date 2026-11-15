<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Date;

use DateError;
use DateException;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * A date expression resolved to its inclusive start and exclusive end.
 */
final readonly class DateValue
{
    private function __construct(
        private DateTimeImmutable $start,
        private DateTimeImmutable $end,
        private DatePrecision $precision,
        private bool $relative,
        private bool $explicitOffset,
    ) {}

    public static function parse(
        string $text,
        DateTimeZone $zone,
        null|DateTimeImmutable $now = null,
    ): self {
        try {
            $absolute = DateExpression::absolute($text);

            if ($absolute !== null) {
                return self::absolute($absolute, $zone);
            }

            $relative = DateExpression::relative($text);

            if ($relative !== null) {
                return self::relative(
                    $relative,
                    $zone,
                    $now ?? new DateTimeImmutable('now', $zone),
                );
            }
        } catch (DateError|DateException $exception) {
            throw new InvalidArgumentException(
                'The date expression cannot be resolved.',
                $exception->getCode(),
                previous: $exception,
            );
        }

        throw new InvalidArgumentException('Invalid date expression.');
    }

    public function bounds(string $operator = '='): DateBounds
    {
        return match ($operator) {
            '=' => new DateBounds($this->start, $this->end),
            '>' => new DateBounds($this->end, null),
            '>=' => new DateBounds($this->start, null),
            '<' => new DateBounds(null, $this->start),
            '<=' => new DateBounds(null, $this->end),
            default => throw new InvalidArgumentException(
                'Date bounds require =, >, >=, < or <=; negate = to implement !=.',
            ),
        };
    }

    public function end(): DateTimeImmutable
    {
        return $this->end;
    }

    public function hasExplicitOffset(): bool
    {
        return $this->explicitOffset;
    }

    public function isRelative(): bool
    {
        return $this->relative;
    }

    public function precision(): DatePrecision
    {
        return $this->precision;
    }

    public function start(): DateTimeImmutable
    {
        return $this->start;
    }

    /**
     * @param array<string, string> $parts
     */
    private static function absolute(array $parts, DateTimeZone $zone): self
    {
        $offset = $parts['offset'] ?? '';
        $fraction = $parts['fraction'] ?? '';
        $precision = match (true) {
            $fraction !== '' => DatePrecision::Fraction,
            ($parts['second'] ?? '') !== '' => DatePrecision::Second,
            ($parts['minute'] ?? '') !== '' => DatePrecision::Minute,
            ($parts['hour'] ?? '') !== '' => DatePrecision::Hour,
            isset($parts['day']) => DatePrecision::Day,
            isset($parts['month']) => DatePrecision::Month,
            default => DatePrecision::Year,
        };
        // The written wall-clock time, held in UTC so arithmetic on it ignores time zones.
        $wall = new DateTimeImmutable(sprintf(
            '%s-%s-%s %s:%s:%s.%s',
            $parts['year'],
            $parts['month'] ?? '01',
            $parts['day'] ?? '01',
            ($parts['hour'] ?? '') !== '' ? $parts['hour'] : '00',
            ($parts['minute'] ?? '') !== '' ? $parts['minute'] : '00',
            ($parts['second'] ?? '') !== '' ? $parts['second'] : '00',
            mb_str_pad(mb_substr($fraction, 0, 6), 6, '0'),
        ), new DateTimeZone('UTC'));
        $zone = $offset === '' ? $zone : new DateTimeZone($offset);
        $step = match ($precision) {
            DatePrecision::Year => '+1 year',
            DatePrecision::Month => '+1 month',
            DatePrecision::Day => '+1 day',
            DatePrecision::Hour => '+1 hour',
            DatePrecision::Minute => '+1 minute',
            DatePrecision::Second => '+1 second',
            default => sprintf('+%d microseconds', 10 ** (6 - min(6, mb_strlen($fraction)))),
        };
        $start = self::instant($wall, $zone);
        $end = self::instant($wall->modify($step), $zone);

        if (! in_array(
            $precision,
            [DatePrecision::Year, DatePrecision::Month, DatePrecision::Day],
            true,
        )) {
            // A repeated local time refers to its first occurrence, so a sub-day value never
            // spans more than its own unit of elapsed time.
            $end = min(
                $end,
                $start->setTimezone(new DateTimeZone('UTC'))->modify($step)->setTimezone($zone),
            );
        }

        return new self($start, $end, $precision, false, $offset !== '');
    }

    /**
     * Add calendar months, clamping the day to the last day of the target month.
     */
    private static function addMonths(DateTimeImmutable $wall, int $months): DateTimeImmutable
    {
        $total = (int) $wall->format('Y') * 12 + (int) $wall->format('n') - 1 + $months;
        $year = (int) floor($total / 12);
        $month = $total - $year * 12 + 1;
        $last = (int) $wall->setDate($year, $month, 1)->format('t');

        return $wall->setDate($year, $month, min((int) $wall->format('j'), $last));
    }

    /**
     * The earliest instant whose local time in the zone is the given wall-clock time.
     *
     * A repeated local time resolves to its first occurrence. A local time skipped by a
     * daylight saving change resolves to the first instant after the gap.
     */
    private static function instant(DateTimeImmutable $wall, DateTimeZone $zone): DateTimeImmutable
    {
        $text = $wall->format('X-m-d H:i:s.u');
        $guess = new DateTimeImmutable($text, $zone);

        // Fixed offsets and abbreviations have no location and no transitions.
        if ($zone->getLocation() === false) {
            return $guess;
        }

        $transitions = $zone->getTransitions(
            $guess->getTimestamp() - 172_800,
            $guess->getTimestamp() + 172_800,
        );
        $earliest = null;

        foreach ($transitions as $transition) {
            $candidate = $wall
                ->modify(sprintf('%+d seconds', -$transition['offset']))
                ->setTimezone($zone);

            if (
                $candidate->format('X-m-d H:i:s.u') === $text
                && (! $earliest instanceof DateTimeImmutable || $candidate < $earliest)
            ) {
                $earliest = $candidate;
            }
        }

        if ($earliest instanceof DateTimeImmutable) {
            return $earliest;
        }

        $seconds = (int) $wall->format('U');
        $previous = null;

        foreach ($transitions as $transition) {
            if (
                $previous !== null
                && $seconds >= $transition['ts'] + $previous
                && $seconds < $transition['ts'] + $transition['offset']
            ) {
                return new DateTimeImmutable('@'.$transition['ts'])->setTimezone($zone);
            }

            $previous = $transition['offset'];
        }

        return $guess;
    }

    /**
     * @param array<string, string> $parts
     */
    private static function relative(array $parts, DateTimeZone $zone, DateTimeImmutable $now): self
    {
        $utc = new DateTimeZone('UTC');
        $local = $now->setTimezone($zone);
        $offset = $parts['offset'];
        $unit = $offset === '' ? '' : mb_substr($offset, -1);
        $amount = $offset === '' ? 0 : (int) mb_substr($offset, 0, -1);

        if ($parts['anchor'] === 'now') {
            $start = match (true) {
                $amount === 0 => $local,
                // Hours and minutes are elapsed time.
                $unit === 'h', $unit === 'm' => $local->setTimezone($utc)
                    ->modify(sprintf('%+d %s', $amount, $unit === 'h' ? 'hours' : 'minutes'))
                    ->setTimezone($zone),
                // Calendar units keep the local wall-clock time.
                default => self::instant(
                    self::withinYears(self::shift(
                        new DateTimeImmutable($local->format('X-m-d H:i:s.u'), $utc),
                        $unit,
                        $amount,
                    )),
                    $zone,
                ),
            };

            self::withinYears($start);

            return new self(
                $start,
                $start->setTimezone($utc)->modify('+1 microsecond')->setTimezone($zone),
                DatePrecision::Instant,
                true,
                false,
            );
        }

        $wall = new DateTimeImmutable($local->format('X-m-d'), $utc);

        // When a repeated hour crosses midnight, the local date can lie outside its own interval.
        if ($now >= self::instant(self::shift($wall, 'd', 1), $zone)) {
            $wall = self::shift($wall, 'd', 1);
        } elseif ($now < self::instant($wall, $zone)) {
            $wall = self::shift($wall, 'd', -1);
        }

        $wall = match ($parts['anchor']) {
            'yesterday' => self::shift($wall, 'd', -1),
            'tomorrow' => self::shift($wall, 'd', 1),
            default => $wall,
        };
        $wall = self::withinYears(self::shift($wall, $unit, $amount));

        return new self(
            self::instant($wall, $zone),
            self::instant(self::shift($wall, 'd', 1), $zone),
            DatePrecision::Day,
            true,
            false,
        );
    }

    /**
     * Move a wall-clock time by calendar units.
     */
    private static function shift(
        DateTimeImmutable $wall,
        string $unit,
        int $amount,
    ): DateTimeImmutable {
        return match ($unit) {
            'y' => self::addMonths($wall, $amount * 12),
            'M' => self::addMonths($wall, $amount),
            'w' => $wall->modify(sprintf('%+d days', $amount * 7)),
            'd' => $wall->modify(sprintf('%+d days', $amount)),
            default => $wall,
        };
    }

    /**
     * Reject relative results outside the years absolute dates can express.
     */
    private static function withinYears(DateTimeImmutable $date): DateTimeImmutable
    {
        $year = (int) $date->format('Y');

        if ($year < 1 || $year > 9_999) {
            throw new InvalidArgumentException('The date is outside the years 0001 to 9999.');
        }

        return $date;
    }
}
