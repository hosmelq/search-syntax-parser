<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\Date\DateBounds;
use HosmelQ\SearchSyntaxParser\Date\DateValue;

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
