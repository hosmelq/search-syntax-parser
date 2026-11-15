<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\Exception\InvalidFieldValueException;
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\Field;
use HosmelQ\SearchSyntaxParser\Validation\Validators\DecimalValidator;

it('rejects decimal text with a trailing newline before any conversion', function (): void {
    $field = Field::decimal('amount', 0, 2)->map([7 => 'seven']);

    expect($field->validate("7.00\n"))
        ->toBeFalse()
        ->and(Field::decimal('amount', 0, 2)->scale(100)->validate("7.00\n"))
        ->toBeFalse()
        ->and(
            fn (): mixed
                => SearchParser::query("amount:\"7.00\n\"")->allowedFields([$field])->build(),
        )
        ->toThrow(InvalidFieldValueException::class);
});

it('rejects values outside the decimal count range', function (
    int $min,
    int $max,
    mixed $value,
): void {
    $validator = new DecimalValidator($min, $max);

    expect($validator($value, 'amount'))->toBeFalse();
})->with(['below range' => [1, 3, '10'], 'above range' => [1, 3, '10.1234']]);

it('rejects values with the wrong decimal count', function (int $min, mixed $value): void {
    $validator = new DecimalValidator($min);

    expect($validator($value, 'price'))->toBeFalse();
})->with([
    'non-numeric' => [2, 'abc'],
    'no decimals' => [2, '10'],
    'one decimal' => [2, '10.1'],
    'three decimals' => [2, '10.123'],
    'scientific notation' => [2, '1e3'],
]);

it('accepts values with the exact decimal count', function (int $min, mixed $value): void {
    $validator = new DecimalValidator($min);

    expect($validator($value, 'price'))->toBeTrue();
})->with(['two decimals string' => [2, '10.12'], 'two decimals number' => [2, 10.12]]);

it('accepts values within the decimal count range', function (
    int $min,
    int $max,
    mixed $value,
): void {
    $validator = new DecimalValidator($min, $max);

    expect($validator($value, 'amount'))->toBeTrue();
})->with([
    'lower bound' => [1, 3, '10.1'],
    'middle' => [1, 3, '10.12'],
    'upper bound' => [1, 3, '10.123'],
]);
