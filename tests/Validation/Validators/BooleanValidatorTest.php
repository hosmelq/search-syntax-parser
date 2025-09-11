<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\Validation\Validators\BooleanValidator;

it('validates boolean values', function (mixed $value, bool $expected): void {
    $validator = new BooleanValidator();

    expect($validator($value, 'flag'))->toBe($expected);
})->with([
    'string true' => ['true', false],
    'string false' => ['false', false],
    'string yes' => ['yes', false],
    'string no' => ['no', false],
    'integer two' => [2, false],
    'string two' => ['2', false],
    'empty string' => ['', false],
    'null' => [null, false],
    'array' => [[true], false],
    'true bool' => [true, true],
    'false bool' => [false, true],
    'zero int' => [0, true],
    'one int' => [1, true],
    'zero string' => ['0', true],
    'one string' => ['1', true],
]);
