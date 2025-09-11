<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\Validation\Validators\MaxValidator;

it('validates numbers against maximum', function (float|int $max, float|int $value, bool $expected): void {
    $validator = new MaxValidator($max);

    expect($validator($value, 'field'))->toBe($expected);
})->with([
    'above max' => [3, 3.01, false],
    'negative above max' => [-5, -4.99, false],
    'at max' => [3, 3, true],
    'below max' => [3, 2.99, true],
    'negative ok' => [-5, -6, true],
]);

it('validates string length against maximum', function (int $max, string $value, bool $expected): void {
    $validator = new MaxValidator($max);

    expect($validator($value, 'name'))->toBe($expected);
})->with([
    'too long' => [3, 'abcd', false],
    'boundary' => [3, 'abc', true],
    'shorter ok' => [3, 'ab', true],
]);

it('validates array size against maximum', function (int $max, array $value, bool $expected): void {
    $validator = new MaxValidator($max);

    expect($validator($value, 'items'))->toBe($expected);
})->with([
    'too many' => [2, [1, 2, 3], false],
    'boundary' => [2, [1, 2], true],
    'fewer ok' => [2, [1], true],
]);
