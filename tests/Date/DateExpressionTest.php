<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\Date\DateValue;
use HosmelQ\SearchSyntaxParser\Exception\InvalidFieldValueException;
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;

it('rejects invalid dates and times', function (string $value): void {
    SearchParser::query('date:"'.$value.'"')->allowedFields([AllowedField::date('date')])->build();
})->throws(InvalidFieldValueException::class)->with([
    '2025-02-29',
    '2025-02-30',
    '2025-13-01',
    '2025-00-01',
    '2025-01-00',
    '0000-01-01',
    '2025-1-1',
    '2025-01-01T25:00',
    '2025-01-01T24:00',
    '2025-01-01T12:60',
    '2025-01-01T12:30:60',
    '2025-01-01T12:30.1',
    '2025-01-01T12:30:00.',
    '2025-01-01T12:30+25:00',
    '2025-01-01T12:30-05:60',
    '2025-01-01T12:30Ztrailing',
    '2025-01-01 ',
]);

it('rejects invalid partial calendars even with the opt-in', function (string $value): void {
    expect(AllowedField::date('created')->allowPartialDates()->validate($value))->toBeFalse();
})->with(['0000', '2024-00', '2024-13', '2024-3', '24', '2024-', '2024-03T10']);

it('rejects malformed relative dates in both validation and resolution', function (
    string $value,
): void {
    expect(AllowedField::date('created')->validate($value))
        ->toBeFalse()
        ->and(fn (): DateValue => DateValue::parse($value, new DateTimeZone('UTC')))
        ->toThrow(InvalidArgumentException::class);
})->with([
    '',
    'now-1D',
    'now+2H',
    'now+1Y',
    'now-1W',
    'today+1h',
    'today-1m',
    'yesterday+0h',
    'tomorrow-2m',
    'now-1s',
    'now-1ms',
    'now-1.5d',
    'now--1d',
    'now+-1d',
    'now+1d-2d',
    'today+1d+2w',
    '-1d+2h',
    'now-',
    'now+1',
    'now 1d',
    'now- 1d',
    'now-1 d',
    'nowZ',
    'now-1dZ',
    'today+1MZ',
    'nextweek',
    ' now',
    'today ',
    '-d',
    '+d',
    '7d',
]);

it('rejects relative offsets longer than six digits', function (): void {
    $field = AllowedField::date('created');

    expect($field->validate('-1000000d'))
        ->toBeFalse()
        ->and($field->validate('today+99999999999999999999d'))
        ->toBeFalse();
});

it('accepts relative offsets up to six digits', function (): void {
    $field = AllowedField::date('created');

    expect($field->validate('-99999d'))->toBeTrue()->and($field->validate('-999999h'))->toBeTrue();
});

it('accepts the relative grammar without resolving its text', function (string $value): void {
    foreach ([$value, '"'.$value.'"'] as $text) {
        expect(
            SearchParser::query('created:'.$text)
                ->allowedFields([AllowedField::date('created')])
                ->parse()
                ->getValue(),
        )->toBe(
            $value,
        );
    }
})->with([
    'now',
    'NOW',
    'NoW',
    'today',
    'TODAY',
    'yesterday',
    'YeStErDaY',
    'tomorrow',
    'TOMORROW',
    'now-7d',
    'now+2h',
    'now+1m',
    'now-1M',
    'now+2y',
    'now-1w',
    'now+0d',
    'now-0007d',
    'today-7d',
    'ToDaY+2M',
    'yesterday-1y',
    'tomorrow+1w',
    '-7d',
    '+3d',
    '-2h',
    '+5m',
]);

it('validates dates and ISO datetimes while preserving raw text', function (string $value): void {
    foreach ([$value, '"'.$value.'"'] as $text) {
        expect(
            SearchParser::query('date:'.$text)
                ->allowedFields([AllowedField::date('date')])
                ->build()['value'],
        )->toBe(
            $value,
        );
    }
})->with([
    '2024-02-29',
    '2025-01-01',
    '2025-01-01T12:30',
    '2025-01-01T12:30Z',
    '2025-01-01T12:30:45',
    '2025-01-01T12:30:45.123456789Z',
    '2025-01-01T00:00:00+05',
    '2025-01-01T23:59:59-05:30',
    '2025-01-01T12',
    '2025-01-01T12+05:30',
]);
