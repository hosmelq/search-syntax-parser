<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\Validation\Validators\BetweenValidator;

it('rejects arrays outside the size range', function (
    int $min,
    int $max,
    array $value,
): void {
    $validator = new BetweenValidator($min, $max);

    expect($validator($value, 'items'))->toBeFalse();
})->with(['too short' => [2, 3, [1]], 'too long' => [2, 3, [1, 2, 3, 4]]]);

it('rejects numbers outside the range', function (
    float|int $min,
    float|int $max,
    float|int $value,
): void {
    $validator = new BetweenValidator($min, $max);

    expect($validator($value, 'field'))->toBeFalse();
})->with([
    'below min' => [1, 3, 0.99],
    'above max' => [1, 3, 3.01],
    'below decimal min' => [2.5, 3.5, 2.49],
    'above decimal max' => [2.5, 3.5, 3.51],
]);

it('rejects strings outside the length range', function (
    int $min,
    int $max,
    string $value,
): void {
    $validator = new BetweenValidator($min, $max);

    expect($validator($value, 'name'))->toBeFalse();
})->with(['too short' => [3, 5, 'ab'], 'too long' => [3, 5, 'abcdef']]);

it('accepts arrays within the size range', function (
    int $min,
    int $max,
    array $value,
): void {
    $validator = new BetweenValidator($min, $max);

    expect($validator($value, 'items'))->toBeTrue();
})->with(['boundary' => [1, 3, [1, 2, 3]], 'inside' => [2, 3, [1, 2]]]);

it('accepts numbers within the range', function (
    float|int $min,
    float|int $max,
    float|int $value,
): void {
    $validator = new BetweenValidator($min, $max);

    expect($validator($value, 'field'))->toBeTrue();
})->with([
    'inside range' => [1, 3, 2],
    'at min' => [1, 3, 1],
    'at max' => [1, 3, 3],
    'negative ok' => [-5, -1, -3],
    'inside decimal range' => [2.5, 3.5, 3.0],
]);

it('accepts strings within the length range', function (
    int $min,
    int $max,
    string $value,
): void {
    $validator = new BetweenValidator($min, $max);

    expect($validator($value, 'name'))->toBeTrue();
})->with([
    'boundary min' => [2, 4, 'ab'],
    'boundary max' => [2, 4, 'abcd'],
    'length ok' => [3, 5, 'abc'],
]);
