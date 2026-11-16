<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\Validation\Validators\StringValidator;

it('rejects non-string values', function (mixed $value): void {
    $validator = new StringValidator();

    expect($validator($value, 'v'))->toBeFalse();
})->with([
    'int' => [10],
    'float' => [10.5],
    'bool true' => [true],
    'bool false' => [false],
    'null' => [null],
    'array empty' => [[]],
    'array non-empty' => [[1]],
]);

it('accepts strings', function (mixed $value): void {
    $validator = new StringValidator();

    expect($validator($value, 'v'))->toBeTrue();
})->with([
    'empty string' => [''],
    'alpha string' => ['abc'],
    'numeric string' => ['10'],
    'zero string' => ['0'],
    'bool-like string' => ['true'],
]);
