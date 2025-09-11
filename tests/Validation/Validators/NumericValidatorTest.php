<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\Validation\Validators\NumericValidator;

it('validates numeric values', function (mixed $value, bool $expected): void {
    $validator = new NumericValidator();

    expect($validator($value, 'v'))->toBe($expected);
})->with([
    'non-numeric string' => ['abc', false],
    'empty string' => ['', false],
    'space string' => [' ', false],
    'null' => [null, false],
    'array' => [[1, 2], false],
    'string with underscore' => ['1_000', false],
    'locale comma' => ['10,5', false],
    'zero int' => [0, true],
    'negative int' => [-1, true],
    'int string' => ['10', true],
    'float' => [10.5, true],
    'float string' => ['10.5', true],
    'scientific notation' => ['1e5', true],
    'leading zero' => ['010', true],
]);
