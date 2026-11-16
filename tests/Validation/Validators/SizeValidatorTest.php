<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\Validation\Field;
use HosmelQ\SearchSyntaxParser\Validation\Validators\SizeValidator;

it('rejects arrays with the wrong size', function (
    int $size,
    array $value,
): void {
    $validator = new SizeValidator($size);

    expect($validator($value, 'items'))->toBeFalse();
})->with(['too few' => [2, [1]], 'too many' => [2, [1, 2, 3]]]);

it('rejects non-finite configuration bounds', function (float $bound): void {
    Field::numeric('p')->size($bound);
})->throws(InvalidArgumentException::class, 'Size bounds must be finite.')->with([INF, -INF, NAN]);

it('rejects numbers that differ from the required size', function (
    float|int $expectedSize,
    float|int $value,
): void {
    $validator = new SizeValidator($expectedSize);

    expect($validator($value, 'field'))->toBeFalse();
})->with(['not equal smaller' => [3, 2.99], 'not equal larger' => [3, 3.01]]);

it('rejects strings with the wrong length', function (
    int $size,
    string $value,
): void {
    $validator = new SizeValidator($size);

    expect($validator($value, 'name'))->toBeFalse();
})->with(['too short' => [3, 'ab'], 'too long' => [3, 'abcd']]);

it('rejects values that cannot be converted to a finite size', function (
    float|string $value,
): void {
    $validator = new SizeValidator(5);

    expect($validator($value, 'p'))->toBeFalse();
})->with([INF, -INF, NAN, ' 5', '5 ']);

it('accepts arrays with the required size', function (
    int $size,
    array $value,
): void {
    $validator = new SizeValidator($size);

    expect($validator($value, 'items'))->toBeTrue();
})->with(['exact' => [2, [1, 2]], 'zero size' => [0, []]]);

it('accepts numbers equal to the required size', function (
    float|int $expectedSize,
    float|int $value,
): void {
    $validator = new SizeValidator($expectedSize);

    expect($validator($value, 'field'))->toBeTrue();
})->with(['equal int' => [3, 3], 'equal float' => [3.5, 3.5]]);

it('accepts strings with the required length', function (
    int $size,
    string $value,
): void {
    $validator = new SizeValidator($size);

    expect($validator($value, 'name'))->toBeTrue();
})->with(['exact' => [3, 'abc'], 'empty matches zero' => [0, '']]);
