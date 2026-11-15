<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\Validation\Validators\MaxValidator;

it('rejects arrays above the maximum size', function (
    int $max,
    array $value,
): void {
    $validator = new MaxValidator($max);

    expect($validator($value, 'items'))->toBeFalse();
})->with(['too many' => [2, [1, 2, 3]]]);

it('rejects numbers above the maximum', function (
    float|int $max,
    float|int $value,
): void {
    $validator = new MaxValidator($max);

    expect($validator($value, 'field'))->toBeFalse();
})->with(['above max' => [3, 3.01], 'negative above max' => [-5, -4.99]]);

it('rejects strings above the maximum length', function (
    int $max,
    string $value,
): void {
    $validator = new MaxValidator($max);

    expect($validator($value, 'name'))->toBeFalse();
})->with(['too long' => [3, 'abcd']]);

it('accepts arrays within the maximum size', function (
    int $max,
    array $value,
): void {
    $validator = new MaxValidator($max);

    expect($validator($value, 'items'))->toBeTrue();
})->with(['boundary' => [2, [1, 2]], 'fewer ok' => [2, [1]]]);

it('accepts numbers within the maximum', function (
    float|int $max,
    float|int $value,
): void {
    $validator = new MaxValidator($max);

    expect($validator($value, 'field'))->toBeTrue();
})->with(['at max' => [3, 3], 'below max' => [3, 2.99], 'negative ok' => [-5, -6]]);

it('accepts strings within the maximum length', function (
    int $max,
    string $value,
): void {
    $validator = new MaxValidator($max);

    expect($validator($value, 'name'))->toBeTrue();
})->with(['boundary' => [3, 'abc'], 'shorter ok' => [3, 'ab']]);
