<?php

declare(strict_types=1);

use Brick\Math\BigDecimal;
use Brick\Math\Exception\NumberFormatException;
use HosmelQ\SearchSyntaxParser\Support\Decimal;

it('rejects non-finite floats and invalid strings', function (
    float|string $value,
    string $exception,
): void {
    expect(fn (): BigDecimal => Decimal::from($value))->toThrow($exception);
})->with([
    [INF, JsonException::class],
    [-INF, JsonException::class],
    [NAN, JsonException::class],
    ['', NumberFormatException::class],
    ['invalid', NumberFormatException::class],
]);

it('restores serialize precision when float conversion throws', function (): void {
    $previous = ini_set('serialize_precision', '3');

    try {
        expect(fn (): BigDecimal => Decimal::from(INF))->toThrow(JsonException::class)
            ->and(ini_get('serialize_precision'))->toBe('3');
    } finally {
        ini_set('serialize_precision', $previous);
    }
});

it('trims surrounding whitespace from decimal strings', function (): void {
    expect((string) Decimal::from(" \t\n\r\v\f1.25 \t\n\r\v\f"))->toBe('1.25');
});

it('uses the shortest round trip for floats and restores serialize precision', function (
    float $value,
    string $expected,
): void {
    $previous = ini_set('serialize_precision', '3');

    try {
        expect((string) Decimal::from($value))->toBe($expected)
            ->and(ini_get('serialize_precision'))->toBe('3');
    } finally {
        ini_set('serialize_precision', $previous);
    }
})->with([
    [0.1, '0.1'],
    [0.1 + 0.2, '0.30000000000000004'],
]);
