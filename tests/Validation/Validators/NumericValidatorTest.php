<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\Exception\InvalidFieldValueException;
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\Field;
use HosmelQ\SearchSyntaxParser\Validation\Validators\NumericValidator;

it('rejects non-finite numeric values', function (): void {
    expect(
        fn (): mixed
            => SearchParser::query('amount:1e400')
                ->allowedFields([Field::numeric('amount')->scale(100)])
                ->build(),
    )->toThrow(
        InvalidFieldValueException::class,
    );
});

it('rejects non-numeric values', function (mixed $value): void {
    $validator = new NumericValidator();

    expect($validator($value, 'v'))->toBeFalse();
})->with([
    'non-numeric string' => ['abc'],
    'empty string' => [''],
    'space string' => [' '],
    'null' => [null],
    'array' => [[1, 2]],
    'string with underscore' => ['1_000'],
    'locale comma' => ['10,5'],
]);

it('accepts numeric values', function (mixed $value): void {
    $validator = new NumericValidator();

    expect($validator($value, 'v'))->toBeTrue();
})->with([
    'zero int' => [0],
    'negative int' => [-1],
    'int string' => ['10'],
    'float' => [10.5],
    'float string' => ['10.5'],
    'scientific notation' => ['1e5'],
    'leading zero' => ['010'],
]);
