<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\Date\DateBounds;
use HosmelQ\SearchSyntaxParser\Date\DatePrecision;
use HosmelQ\SearchSyntaxParser\Date\DateValue;

it('covers each written precision with half-open bounds', function (
    string $text,
    DatePrecision $precision,
    string $from,
    string $to,
): void {
    $value = DateValue::parse($text, new DateTimeZone('America/Managua'));

    expect($value->precision())->toBe($precision)
        ->and($value->start()->format('Y-m-d H:i:s.uP'))->toBe($from)
        ->and($value->end()->format('Y-m-d H:i:s.uP'))->toBe($to)
        ->and($value->isRelative())->toBeFalse()
        ->and($value->hasExplicitOffset())->toBeFalse();
})->with([
    [
        '2024',
        DatePrecision::Year,
        '2024-01-01 00:00:00.000000-06:00',
        '2025-01-01 00:00:00.000000-06:00',
    ],
    [
        '2024-02',
        DatePrecision::Month,
        '2024-02-01 00:00:00.000000-06:00',
        '2024-03-01 00:00:00.000000-06:00',
    ],
    [
        '2024-02-29',
        DatePrecision::Day,
        '2024-02-29 00:00:00.000000-06:00',
        '2024-03-01 00:00:00.000000-06:00',
    ],
    [
        '2024-03-15T10',
        DatePrecision::Hour,
        '2024-03-15 10:00:00.000000-06:00',
        '2024-03-15 11:00:00.000000-06:00',
    ],
    [
        '2024-03-15T10:30',
        DatePrecision::Minute,
        '2024-03-15 10:30:00.000000-06:00',
        '2024-03-15 10:31:00.000000-06:00',
    ],
    [
        '2024-03-15T23:59:59',
        DatePrecision::Second,
        '2024-03-15 23:59:59.000000-06:00',
        '2024-03-16 00:00:00.000000-06:00',
    ],
    [
        '2024-03-15T10:30:45.1',
        DatePrecision::Fraction,
        '2024-03-15 10:30:45.100000-06:00',
        '2024-03-15 10:30:45.200000-06:00',
    ],
    [
        '2024-03-15T10:30:45.123',
        DatePrecision::Fraction,
        '2024-03-15 10:30:45.123000-06:00',
        '2024-03-15 10:30:45.124000-06:00',
    ],
    [
        '2024-03-15T23:59:59.999999',
        DatePrecision::Fraction,
        '2024-03-15 23:59:59.999999-06:00',
        '2024-03-16 00:00:00.000000-06:00',
    ],
    [
        '2024-03-15T10:30:45.123456789',
        DatePrecision::Fraction,
        '2024-03-15 10:30:45.123456-06:00',
        '2024-03-15 10:30:45.123457-06:00',
    ],
]);

it('uses explicit offsets independently of the request zone', function (
    string $text,
    string $expectedUtc,
    int $seconds,
): void {
    $value = DateValue::parse($text, new DateTimeZone('Pacific/Auckland'));

    expect($value->hasExplicitOffset())->toBeTrue()
        ->and($value->isRelative())->toBeFalse()
        ->and($value->start()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'))
        ->toBe($expectedUtc)
        ->and($value->end()->getTimestamp() - $value->start()->getTimestamp())->toBe($seconds);
})->with([
    ['2024-03-15T10:30Z', '2024-03-15 10:30:00', 60],
    ['2024-03-15T10:30+05', '2024-03-15 05:30:00', 60],
    ['2024-03-15T10:30-05:30', '2024-03-15 16:00:00', 60],
    ['2024-03-15T10+05:30', '2024-03-15 04:30:00', 3_600],
    ['2024-03-15T10:30:45+23:59', '2024-03-14 10:31:45', 1],
]);

it('resolves instant anchors and offsets against the supplied request time', function (
    string $text,
    string $expected,
): void {
    $value = DateValue::parse(
        $text,
        new DateTimeZone('America/Managua'),
        new DateTimeImmutable('2024-03-15T16:30:45.123456Z'),
    );

    expect($value->start()->format('Y-m-d H:i:s.uP'))->toBe($expected)
        ->and((float) $value->end()->format('U.u') - (float) $value->start()->format('U.u'))
        ->toEqualWithDelta(0.000_001, 0.000_000_1)
        ->and($value->precision())->toBe(DatePrecision::Instant)
        ->and($value->isRelative())->toBeTrue()
        ->and($value->hasExplicitOffset())->toBeFalse();
})->with([
    ['NoW', '2024-03-15 10:30:45.123456-06:00'],
    ['now-7d', '2024-03-08 10:30:45.123456-06:00'],
    ['-7d', '2024-03-08 10:30:45.123456-06:00'],
    ['+3d', '2024-03-18 10:30:45.123456-06:00'],
    ['now+2h', '2024-03-15 12:30:45.123456-06:00'],
    ['now+2m', '2024-03-15 10:32:45.123456-06:00'],
    ['now+2M', '2024-05-15 10:30:45.123456-06:00'],
    ['now-1y', '2023-03-15 10:30:45.123456-06:00'],
    ['now-1w', '2024-03-08 10:30:45.123456-06:00'],
    ['now+0d', '2024-03-15 10:30:45.123456-06:00'],
]);

it('resolves day anchors using the request zone before applying calendar offsets', function (
    string $text,
    string $from,
    string $to,
): void {
    $value = DateValue::parse(
        $text,
        new DateTimeZone('America/Managua'),
        new DateTimeImmutable('2024-03-16T01:30:45.123456Z'),
    );

    expect($value->start()->format('Y-m-d H:i:s'))->toBe($from.' 00:00:00')
        ->and($value->end()->format('Y-m-d H:i:s'))->toBe($to.' 00:00:00')
        ->and($value->precision())->toBe(DatePrecision::Day)
        ->and($value->isRelative())->toBeTrue();
})->with([
    ['TODAY', '2024-03-15', '2024-03-16'],
    ['yesterday', '2024-03-14', '2024-03-15'],
    ['tomorrow', '2024-03-16', '2024-03-17'],
    ['today-7d', '2024-03-08', '2024-03-09'],
    ['tomorrow+1w', '2024-03-23', '2024-03-24'],
    ['yesterday+1M', '2024-04-14', '2024-04-15'],
    ['today-1y', '2023-03-15', '2023-03-16'],
]);

it('uses the current time when no request time is supplied', function (): void {
    $before = new DateTimeImmutable();
    $value = DateValue::parse('now', new DateTimeZone('UTC'));
    $after = new DateTimeImmutable();

    expect($value->start() >= $before && $value->start() <= $after)->toBeTrue()
        ->and($value->start()->getTimezone()->getName())->toBe('UTC');
});

it('computes each day boundary in the real zone including missing midnights', function (
    string $zone,
    string $date,
    string $from,
    string $to,
    int $hours,
): void {
    $zone = new DateTimeZone($zone);

    foreach ([$date, 'today', 'tomorrow-1d', 'yesterday+1d'] as $text) {
        $value = DateValue::parse($text, $zone, new DateTimeImmutable($date.' 12:00:00', $zone));

        expect($value->start()->format('Y-m-d H:i:sP'))->toBe($from)
            ->and($value->end()->format('Y-m-d H:i:sP'))->toBe($to)
            ->and($value->end()->getTimestamp() - $value->start()->getTimestamp())->toBe(
                $hours * 3_600,
            );
    }
})->with([
    [
        'America/New_York',
        '2024-03-10',
        '2024-03-10 00:00:00-05:00',
        '2024-03-11 00:00:00-04:00',
        23,
    ],
    [
        'America/New_York',
        '2024-11-03',
        '2024-11-03 00:00:00-04:00',
        '2024-11-04 00:00:00-05:00',
        25,
    ],
    [
        'America/Santiago',
        '2024-09-08',
        '2024-09-08 01:00:00-03:00',
        '2024-09-09 00:00:00-03:00',
        23,
    ],
]);

it('keeps calendar periods distinct from elapsed hours at DST transitions', function (): void {
    $zone = new DateTimeZone('America/New_York');
    $now = new DateTimeImmutable('2024-03-09 12:00:00', $zone);
    $dayLater = DateValue::parse('now+1d', $zone, $now);
    $hoursLater = DateValue::parse('now+24h', $zone, $now);

    expect($dayLater->start()->format('Y-m-d H:i:sP'))->toBe('2024-03-10 12:00:00-04:00')
        ->and($hoursLater->start()->format('Y-m-d H:i:sP'))->toBe('2024-03-10 13:00:00-04:00');
});

it('keeps fractional and instant intervals exact when the clock moves backwards', function (): void {
    $zone = new DateTimeZone('America/New_York');
    $now = new DateTimeImmutable('2024-11-03T05:59:59.999999Z');

    foreach (['now', '2024-11-03T01:59:59.999999'] as $text) {
        $value = DateValue::parse($text, $zone, $now);

        expect($value->start()->format('U.u'))->toBe('1730613599.999999')
            ->and($value->end()->format('U.u'))->toBe('1730613600.000000')
            ->and($value->end()->format('Y-m-d H:i:s.uP'))->toBe(
                '2024-11-03 01:00:00.000000-05:00',
            );
    }

    $fraction = DateValue::parse('2024-11-03T01:59:59.9', $zone);

    expect($fraction->start()->format('U.u'))->toBe('1730613599.900000')
        ->and($fraction->end()->format('U.u'))->toBe('1730613600.000000');
});

it('maps every comparison operator to inclusive from and exclusive to bounds', function (
    string $operator,
    null|string $from,
    null|string $to,
): void {
    $bounds = DateValue::parse('2024-03', new DateTimeZone('UTC'))->bounds($operator);

    expect($bounds->from?->format('Y-m-d'))->toBe($from)
        ->and($bounds->to?->format('Y-m-d'))->toBe($to);
})->with([
    ['=', '2024-03-01', '2024-04-01'],
    ['>', '2024-04-01', null],
    ['>=', '2024-03-01', null],
    ['<', null, '2024-03-01'],
    ['<=', null, '2024-04-01'],
]);

it('builds inclusive written ranges with optional open ends', function (
    bool $openFrom,
    bool $openTo,
): void {
    $zone = new DateTimeZone('UTC');
    $bounds = DateBounds::range(
        $openFrom ? null : DateValue::parse('2023', $zone),
        $openTo ? null : DateValue::parse('2024-06', $zone),
    );

    expect($bounds->from?->format('Y-m-d'))->toBe($openFrom ? null : '2023-01-01')
        ->and($bounds->to?->format('Y-m-d'))->toBe($openTo ? null : '2024-07-01');
})->with([[false, false], [true, false], [false, true], [true, true]]);

it('requires adapters to negate equality rather than represent its complement as one interval', function (): void {
    DateValue::parse('today', new DateTimeZone('UTC'))->bounds('!=');
})->throws(InvalidArgumentException::class);

it('rejects values outside the format-less date grammar', function (string $text): void {
    DateValue::parse($text, new DateTimeZone('UTC'));
})->throws(InvalidArgumentException::class)->with([
    '0000', '2024-13', '2025-02-29', '2024-02-30', '2024-03-15T24',
    '2024-03-15T10:60', '2024-03-15T10:30:60', '2024-03-15t10:30z',
    '2024-03-15T10:30+24:00', '2024-03-15T10:30-05:60', '15/03/2024',
]);

it('starts a day at the first occurrence of a repeated midnight', function (): void {
    $zone = new DateTimeZone('Asia/Amman');
    $day = DateValue::parse('2021-10-29', $zone);
    $today = DateValue::parse('today', $zone, new DateTimeImmutable('2021-10-28T21:30:00Z'));

    expect($day->start()->getTimestamp())->toBe(
        new DateTimeImmutable('2021-10-28T21:00:00Z')->getTimestamp(),
    )
        ->and($day->end()->getTimestamp() - $day->start()->getTimestamp())->toBe(25 * 3_600)
        ->and(DateValue::parse('2021-10-28', $zone)->end()->getTimestamp())->toBe(
            $day->start()->getTimestamp(),
        )
        ->and($today->start()->getTimestamp())->toBe($day->start()->getTimestamp());
});

it('treats repeated sub-day times as their first occurrence and skipped ones as empty', function (
    string $text,
    string $from,
    string $to,
): void {
    $value = DateValue::parse($text, new DateTimeZone('America/New_York'));

    expect($value->start()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'))->toBe(
        $from,
    )
        ->and($value->end()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'))->toBe(
            $to,
        );
})->with([
    'repeated hour' => ['2024-11-03T01', '2024-11-03 05:00:00', '2024-11-03 06:00:00'],
    'last repeated minute' => ['2024-11-03T01:59', '2024-11-03 05:59:00', '2024-11-03 06:00:00'],
    'skipped hour' => ['2024-03-10T02', '2024-03-10 07:00:00', '2024-03-10 07:00:00'],
    'skipped minute' => ['2024-03-10T02:30', '2024-03-10 07:00:00', '2024-03-10 07:00:00'],
    'minute before the gap' => ['2024-03-10T01:59', '2024-03-10 06:59:00', '2024-03-10 07:00:00'],
]);

it('clamps month and year offsets to the last day of the target month', function (
    string $text,
    string $now,
    string $expected,
): void {
    $value = DateValue::parse($text, new DateTimeZone('UTC'), new DateTimeImmutable($now));

    expect($value->start()->format('Y-m-d H:i:s'))->toBe($expected);
})->with([
    ['today-1M', '2025-03-31T12:00:00Z', '2025-02-28 00:00:00'],
    ['today+1M', '2025-01-31T12:00:00Z', '2025-02-28 00:00:00'],
    ['today+1y', '2024-02-29T12:00:00Z', '2025-02-28 00:00:00'],
    ['today-12M', '2024-02-29T12:00:00Z', '2023-02-28 00:00:00'],
    ['now-1M', '2025-03-31T12:00:00Z', '2025-02-28 12:00:00'],
    ['today-1M', '2024-03-31T12:00:00Z', '2024-02-29 00:00:00'],
]);

it('keeps the wall-clock time for day offsets on now and elapsed time for hours', function (): void {
    $zone = new DateTimeZone('America/New_York');
    $now = new DateTimeImmutable('2024-03-12T16:00:00Z');

    expect(DateValue::parse('now-7d', $zone, $now)->start()->format('Y-m-d H:i:sP'))->toBe(
        '2024-03-05 12:00:00-05:00',
    )
        ->and(DateValue::parse('now-168h', $zone, $now)->start()->format('Y-m-d H:i:sP'))->toBe(
            '2024-03-05 11:00:00-05:00',
        )
        ->and(
            DateValue::parse('now', $zone, new DateTimeImmutable('2024-11-03T06:30:00Z'))
                ->start()
                ->format('Y-m-d H:i:sP'),
        )
        ->toBe('2024-11-03 01:30:00-05:00');
});

it('keeps now inside today when a repeated hour crosses midnight', function (): void {
    $zone = new DateTimeZone('America/Goose_Bay');
    $now = new DateTimeImmutable('2000-10-28T23:30:00-04:00');
    $today = DateValue::parse('today', $zone, $now);

    expect($now >= $today->start() && $now < $today->end())->toBeTrue()
        ->and(DateValue::parse('yesterday', $zone, $now)->end()->getTimestamp())->toBe(
            $today->start()->getTimestamp(),
        );
});

it('keeps now exact for zero calendar offsets during a repeated hour', function (
    string $text,
): void {
    $now = new DateTimeImmutable('2024-11-03T06:30:00Z');

    expect(
        DateValue::parse($text, new DateTimeZone('America/New_York'), $now)
            ->start()
            ->getTimestamp(),
    )->toBe($now->getTimestamp());
})->with(['now+0d', '-0d', 'now-0M', 'now+0y']);

it('rejects relative values outside the years 0001 to 9999', function (string $text): void {
    expect(fn (): DateValue => DateValue::parse($text, new DateTimeZone('UTC')))
        ->toThrow(InvalidArgumentException::class, 'The date is outside the years 0001 to 9999.');
})->with(['today+999999y', 'now-999999M', '+999999w', 'today-3000y']);
