<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\Validation\Validators\DecimalValidator;

it('validates decimal places equal to count when only min is provided', function (int $min, mixed $value, bool $expected): void {
    $validator = new DecimalValidator($min);

    expect($validator($value, 'price'))->toBe($expected);
})->with([
    'non-numeric' => [2, 'abc', false],
    'no decimals' => [2, '10', false],
    'one decimal' => [2, '10.1', false],
    'three decimals' => [2, '10.123', false],
    'scientific notation' => [2, '1e3', false],
    'two decimals string' => [2, '10.12', true],
    'two decimals number' => [2, 10.12, true],
]);

it('validates decimal places within range when min and max are provided', function (int $min, int $max, mixed $value, bool $expected): void {
    $validator = new DecimalValidator($min, $max);

    expect($validator($value, 'amount'))->toBe($expected);
})->with([
    'below range' => [1, 3, '10', false],
    'above range' => [1, 3, '10.1234', false],
    'lower bound' => [1, 3, '10.1', true],
    'middle' => [1, 3, '10.12', true],
    'upper bound' => [1, 3, '10.123', true],
]);
