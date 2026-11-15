<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\Date\DateBounds;
use HosmelQ\SearchSyntaxParser\Date\DateValue;
use HosmelQ\SearchSyntaxParser\Exception\ErrorReason;
use HosmelQ\SearchSyntaxParser\Exception\InvalidFieldValueException;
use HosmelQ\SearchSyntaxParser\Query\Bounds;
use HosmelQ\SearchSyntaxParser\Query\Clause;
use HosmelQ\SearchSyntaxParser\Query\Operator;

it('rejects date clauses outside the supplied clock or timezone', function (
    string $value,
    string $now,
    string $zone,
): void {
    try {
        DateBounds::forClause(
            new Clause('created', Operator::Equal, $value, 7),
            new DateTimeZone($zone),
            new DateTimeImmutable($now),
        );

        test()->fail('Expected an invalid date.');
    } catch (InvalidFieldValueException $invalidFieldValueException) {
        expect($invalidFieldValueException->getReason())->toBe(ErrorReason::InvalidFieldValue)
            ->and($invalidFieldValueException->getField())->toBe('created')
            ->and($invalidFieldValueException->getPosition())->toBe(7);
    }
})->with([
    ['tomorrow', '9999-12-31T12:00:00Z', 'UTC'],
    ['yesterday', '0001-01-01T12:00:00Z', 'UTC'],
    ['now', '9999-12-31T23:00:00Z', '+02:00'],
    ['invalid', '2024-01-01T00:00:00Z', 'UTC'],
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

it('clamps storage bounds to four digit years after zone conversion', function (
    string $from,
    string $to,
    string $zone,
    null|string $expectedFrom,
    null|string $expectedTo,
): void {
    $bounds = new DateBounds(new DateTimeImmutable($from), new DateTimeImmutable($to))
        ->toStorage(new DateTimeZone($zone), 'Y-m-d H:i:s');

    expect($bounds->from)->toBe($expectedFrom)->and($bounds->to)->toBe($expectedTo);
})->with([
    ['0001-01-01T00:00:00Z', '0001-01-02T00:00:00Z', '-01:00', null, '0001-01-01 23:00:00'],
    ['9999-12-30T00:00:00Z', '9999-12-31T23:59:59Z', '+02:00', '9999-12-30 02:00:00', null],
    ['0000-12-31T00:00:00Z', '9999-12-31T23:59:59.999999Z', 'UTC', null, null],
]);

it('compiles positive intervals for scalar list range and negated clauses', function (
    Clause $clause,
    array $expected,
): void {
    $bounds = DateBounds::forClause(
        $clause,
        new DateTimeZone('UTC'),
        new DateTimeImmutable('2024-01-10T12:00:00Z'),
    );

    expect(array_map(static fn (DateBounds $bound): array => [
        $bound->from?->format('Y-m-d H:i:s'),
        $bound->to?->format('Y-m-d H:i:s'),
    ], $bounds))->toBe($expected);
})->with([
    [
        new Clause('date', Operator::Equal, 'today'),
        [['2024-01-10 00:00:00', '2024-01-11 00:00:00']],
    ],
    [
        new Clause('date', Operator::NotEqual, 'today', negated: true),
        [['2024-01-10 00:00:00', '2024-01-11 00:00:00']],
    ],
    [new Clause('date', Operator::Greater, '2024-01-10'), [['2024-01-11 00:00:00', null]]],
    [
        new Clause('date', Operator::LessOrEqual, '2024-01-10', negated: true),
        [['2024-01-11 00:00:00', null]],
    ],
    [
        new Clause('date', Operator::In, ['2023', '2024-02']),
        [
            ['2023-01-01 00:00:00', '2024-01-01 00:00:00'],
            ['2024-02-01 00:00:00', '2024-03-01 00:00:00'],
        ],
    ],
    [
        new Clause('date', Operator::Between, new Bounds('2023', '2024-02')),
        [['2023-01-01 00:00:00', '2024-03-01 00:00:00']],
    ],
    [new Clause('date', Operator::Between, new Bounds(null, null)), [[null, null]]],
]);

it('preserves open storage bounds', function (bool $openFrom, bool $openTo): void {
    $bounds = new DateBounds(
        $openFrom ? null : new DateTimeImmutable('2024-01-01T00:00:00Z'),
        $openTo ? null : new DateTimeImmutable('2024-02-01T00:00:00Z'),
    )->toStorage(new DateTimeZone('UTC'), 'Y-m-d');

    expect($bounds->from)->toBe($openFrom ? null : '2024-01-01')
        ->and($bounds->to)->toBe($openTo ? null : '2024-02-01');
})->with([[false, true], [true, false], [true, true]]);

it('returns no storage interval for empty rounded or out of range bounds', function (
    null|string $from,
    null|string $to,
    string $format,
): void {
    expect(new DateBounds(
        $from === null ? null : new DateTimeImmutable($from),
        $to === null ? null : new DateTimeImmutable($to),
    )->toStorage(new DateTimeZone('UTC'), $format))->toBeNull();
})->with([
    ['2024-01-01T00:00:00Z', '2024-01-01T00:00:00Z', 'Y-m-d H:i:s.u'],
    ['2024-02-01T00:00:00Z', '2024-01-01T00:00:00Z', 'Y-m-d H:i:s.u'],
    ['2024-01-01T00:00:00.000001Z', '2024-01-01T00:00:00.000002Z', 'Y-m-d H:i:s'],
    ['2024-01-01T00:00:00.000001Z', '2024-01-01T00:00:00.000002Z', 'Y-m-d H:i:s.v'],
    ['9999-12-31T23:59:59.999999Z', null, 'Y-m-d H:i:s'],
    [null, '0001-01-01T00:00:00Z', 'Y-m-d H:i:s'],
    [null, '0000-12-31T00:00:00Z', 'Y-m-d H:i:s'],
]);

it('rounds storage bounds upward at the formats fractional precision', function (
    string $format,
    string $from,
    string $to,
): void {
    $bounds = new DateBounds(
        new DateTimeImmutable('2024-01-01T00:00:00.123456Z'),
        new DateTimeImmutable('2024-01-01T00:00:01.987654Z'),
    )->toStorage(new DateTimeZone('+02:00'), $format);

    expect($bounds->from)->toBe($from)->and($bounds->to)->toBe($to);
})->with([
    ['Y-m-d H:i:s.u', '2024-01-01 02:00:00.123456', '2024-01-01 02:00:01.987654'],
    ['Y-m-d H:i:s.v', '2024-01-01 02:00:00.124', '2024-01-01 02:00:01.988'],
    ['Y-m-d H:i:s', '2024-01-01 02:00:01', '2024-01-01 02:00:02'],
    ['Y-m-d H:i:s \\u\\v', '2024-01-01 02:00:01 uv', '2024-01-01 02:00:02 uv'],
]);
