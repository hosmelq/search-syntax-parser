<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\Validation\Validators\BooleanValidator;

it('rejects values outside the boolean set', function (mixed $value): void {
    $validator = new BooleanValidator();

    expect($validator($value, 'flag'))->toBeFalse();
})->with([
    'string true' => ['true'],
    'string false' => ['false'],
    'string yes' => ['yes'],
    'string no' => ['no'],
    'integer two' => [2],
    'string two' => ['2'],
    'empty string' => [''],
    'null' => [null],
    'array' => [[true]],
]);

it('accepts booleans and their integer and string forms', function (mixed $value): void {
    $validator = new BooleanValidator();

    expect($validator($value, 'flag'))->toBeTrue();
})->with([
    'true bool' => [true],
    'false bool' => [false],
    'zero int' => [0],
    'one int' => [1],
    'zero string' => ['0'],
    'one string' => ['1'],
]);
