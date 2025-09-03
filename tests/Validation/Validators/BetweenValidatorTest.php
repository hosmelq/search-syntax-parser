<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\Validation\Validators\BetweenValidator;

it('validates numbers between min and max', function (float|int $min, float|int $max, float|int $value, bool $expected): void {
    $validator = new BetweenValidator($min, $max);

    expect($validator($value, 'field'))->toBe($expected);
})->with([
    'below min' => [1, 3, 0.99, false],
    'above max' => [1, 3, 3.01, false],
    'below decimal min' => [2.5, 3.5, 2.49, false],
    'above decimal max' => [2.5, 3.5, 3.51, false],
    'inside range' => [1, 3, 2, true],
    'at min' => [1, 3, 1, true],
    'at max' => [1, 3, 3, true],
    'negative ok' => [-5, -1, -3, true],
    'inside decimal range' => [2.5, 3.5, 3.0, true],
]);

it('validates string length between min and max', function (int $min, int $max, string $value, bool $expected): void {
    $validator = new BetweenValidator($min, $max);

    expect($validator($value, 'name'))->toBe($expected);
})->with([
    'too short' => [3, 5, 'ab', false],
    'too long' => [3, 5, 'abcdef', false],
    'boundary min' => [2, 4, 'ab', true],
    'boundary max' => [2, 4, 'abcd', true],
    'length ok' => [3, 5, 'abc', true],
]);

it('validates array size between min and max', function (int $min, int $max, array $value, bool $expected): void {
    $validator = new BetweenValidator($min, $max);

    expect($validator($value, 'items'))->toBe($expected);
})->with([
    'too short' => [2, 3, [1], false],
    'too long' => [2, 3, [1, 2, 3, 4], false],
    'boundary' => [1, 3, [1, 2, 3], true],
    'inside' => [2, 3, [1, 2], true],
]);
