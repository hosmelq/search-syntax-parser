<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\Validation\Validators\SizeValidator;

it('validates numbers match size', function (float|int $expectedSize, float|int $value, bool $expected): void {
    $validator = new SizeValidator($expectedSize);

    expect($validator($value, 'field'))->toBe($expected);
})->with([
    'not equal smaller' => [3, 2.99, false],
    'not equal larger' => [3, 3.01, false],
    'equal int' => [3, 3, true],
    'equal float' => [3.5, 3.5, true],
]);

it('validates string length matches size', function (int $size, string $value, bool $expected): void {
    $validator = new SizeValidator($size);

    expect($validator($value, 'name'))->toBe($expected);
})->with([
    'too short' => [3, 'ab', false],
    'too long' => [3, 'abcd', false],
    'exact' => [3, 'abc', true],
    'empty matches zero' => [0, '', true],
]);

it('validates array size matches size', function (int $size, array $value, bool $expected): void {
    $validator = new SizeValidator($size);

    expect($validator($value, 'items'))->toBe($expected);
})->with([
    'too few' => [2, [1], false],
    'too many' => [2, [1, 2, 3], false],
    'exact' => [2, [1, 2], true],
    'zero size' => [0, [], true],
]);
