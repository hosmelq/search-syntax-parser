<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\Validation\Validators\IntegerValidator;

it('rejects values that are not whole integers', function (mixed $value): void {
    $validator = new IntegerValidator();

    expect($validator($value, 'v'))->toBeFalse();
})->with([
    'float number' => [10.5],
    'float string' => ['10.5'],
    'scientific notation' => ['1e5'],
    'non-numeric string' => ['abc'],
    'empty string' => [''],
    'null' => [null],
    'array' => [[1]],
    'padded int string' => ['02'],
]);

it('accepts whole integers', function (mixed $value): void {
    $validator = new IntegerValidator();

    expect($validator($value, 'v'))->toBeTrue();
})->with(['zero int' => [0], 'negative int' => [-10], 'int string' => ['10']]);
