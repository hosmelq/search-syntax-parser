<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\Validation\Validators\MinValidator;

it('validates numbers against minimum', function (float|int $min, float|int $value, bool $expected): void {
    $validator = new MinValidator($min);

    expect($validator($value, 'field'))->toBe($expected);
})->with([
    'below min' => [3, 2.99, false],
    'negative below min' => [-5, -5.01, false],
    'at min' => [3, 3, true],
    'above min' => [3, 3.01, true],
    'negative threshold ok' => [-5, -5, true],
]);

it('validates string length against minimum', function (int $min, string $value, bool $expected): void {
    $validator = new MinValidator($min);

    expect($validator($value, 'name'))->toBe($expected);
})->with([
    'too short' => [3, 'ab', false],
    'boundary' => [3, 'abc', true],
    'longer ok' => [3, 'abcd', true],
]);

it('validates array size against minimum', function (int $min, array $value, bool $expected): void {
    $validator = new MinValidator($min);

    expect($validator($value, 'items'))->toBe($expected);
})->with([
    'too few' => [2, [1], false],
    'boundary' => [2, [1, 2], true],
    'more ok' => [2, [1, 2, 3], true],
]);
