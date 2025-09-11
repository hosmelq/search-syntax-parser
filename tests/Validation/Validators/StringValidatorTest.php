<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\Validation\Validators\StringValidator;

it('validates string values', function (mixed $value, bool $expected): void {
    $validator = new StringValidator();

    expect($validator($value, 'v'))->toBe($expected);
})->with([
    'int' => [10, false],
    'float' => [10.5, false],
    'bool true' => [true, false],
    'bool false' => [false, false],
    'null' => [null, false],
    'array empty' => [[], false],
    'array non-empty' => [[1], false],
    'empty string' => ['', true],
    'alpha string' => ['abc', true],
    'numeric string' => ['10', true],
    'zero string' => ['0', true],
    'bool-like string' => ['true', true],
]);
