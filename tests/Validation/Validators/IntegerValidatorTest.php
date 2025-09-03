<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\Validation\Validators\IntegerValidator;

it('validates integer values', function (mixed $value, bool $expected): void {
    $validator = new IntegerValidator();

    expect($validator($value, 'v'))->toBe($expected);
})->with([
    'float number' => [10.5, false],
    'float string' => ['10.5', false],
    'scientific notation' => ['1e5', false],
    'non-numeric string' => ['abc', false],
    'empty string' => ['', false],
    'null' => [null, false],
    'array' => [[1], false],
    'padded int string' => ['02', false],
    'zero int' => [0, true],
    'negative int' => [-10, true],
    'int string' => ['10', true],
]);
