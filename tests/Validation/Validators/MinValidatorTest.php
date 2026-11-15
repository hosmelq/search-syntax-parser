<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\Validation\AllowedField;
use HosmelQ\SearchSyntaxParser\Validation\Validators\MinValidator;

it('rejects arrays below the minimum size', function (
    int $min,
    array $value,
): void {
    $validator = new MinValidator($min);

    expect($validator($value, 'items'))->toBeFalse();
})->with(['too few' => [2, [1]]]);

it('rejects non-finite configuration bounds', function (float $bound): void {
    AllowedField::numeric('p')->min($bound);
})->throws(InvalidArgumentException::class, 'Size bounds must be finite.')->with([INF, -INF, NAN]);

it('rejects numbers below the minimum', function (
    float|int $min,
    float|int $value,
): void {
    $validator = new MinValidator($min);

    expect($validator($value, 'field'))->toBeFalse();
})->with(['below min' => [3, 2.99], 'negative below min' => [-5, -5.01]]);

it('rejects strings below the minimum length', function (
    int $min,
    string $value,
): void {
    $validator = new MinValidator($min);

    expect($validator($value, 'name'))->toBeFalse();
})->with(['too short' => [3, 'ab']]);

it('rejects values that cannot be converted to a finite size', function (
    float|string $value,
): void {
    $validator = new MinValidator(5);

    expect($validator($value, 'p'))->toBeFalse();
})->with([INF, -INF, NAN, ' 5', '5 ']);

it('accepts arrays within the minimum size', function (
    int $min,
    array $value,
): void {
    $validator = new MinValidator($min);

    expect($validator($value, 'items'))->toBeTrue();
})->with(['boundary' => [2, [1, 2]], 'more ok' => [2, [1, 2, 3]]]);

it('accepts numbers within the minimum', function (
    float|int $min,
    float|int $value,
): void {
    $validator = new MinValidator($min);

    expect($validator($value, 'field'))->toBeTrue();
})->with(['at min' => [3, 3], 'above min' => [3, 3.01], 'negative threshold ok' => [-5, -5]]);

it('accepts strings within the minimum length', function (
    int $min,
    string $value,
): void {
    $validator = new MinValidator($min);

    expect($validator($value, 'name'))->toBeTrue();
})->with(['boundary' => [3, 'abc'], 'longer ok' => [3, 'abcd']]);
