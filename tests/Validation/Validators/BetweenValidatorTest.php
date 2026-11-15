<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\Validation\Field;
use HosmelQ\SearchSyntaxParser\Validation\Validators\BetweenValidator;

it('rejects arrays outside the size range', function (
    int $min,
    int $max,
    array $value,
): void {
    $validator = new BetweenValidator($min, $max);

    expect($validator($value, 'items'))->toBeFalse();
})->with(['too short' => [2, 3, [1]], 'too long' => [2, 3, [1, 2, 3, 4]]]);

it('rejects non-finite configuration bounds', function (float $bound, bool $minimum): void {
    Field::numeric('p')->between($minimum ? $bound : 1, $minimum ? 10 : $bound);
})
    ->throws(InvalidArgumentException::class, 'Size bounds must be finite.')
    ->with([INF, -INF, NAN])
    ->with([false, true]);

it('rejects numbers outside the range', function (
    float|int $min,
    float|int $max,
    float|int $value,
): void {
    $validator = new BetweenValidator($min, $max);

    expect($validator($value, 'field'))->toBeFalse();
})->with([
    'below min' => [1, 3, 0.99],
    'above max' => [1, 3, 3.01],
    'below decimal min' => [2.5, 3.5, 2.49],
    'above decimal max' => [2.5, 3.5, 3.51],
]);

it('rejects strings outside the length range', function (
    int $min,
    int $max,
    string $value,
): void {
    $validator = new BetweenValidator($min, $max);

    expect($validator($value, 'name'))->toBeFalse();
})->with(['too short' => [3, 5, 'ab'], 'too long' => [3, 5, 'abcdef']]);

it('rejects values that cannot be converted to a finite size', function (
    float|string $value,
): void {
    $validator = new BetweenValidator(1, 10);

    expect($validator($value, 'p'))->toBeFalse();
})->with([INF, -INF, NAN, ' 5', '5 ']);

it('accepts arrays within the size range', function (
    int $min,
    int $max,
    array $value,
): void {
    $validator = new BetweenValidator($min, $max);

    expect($validator($value, 'items'))->toBeTrue();
})->with(['boundary' => [1, 3, [1, 2, 3]], 'inside' => [2, 3, [1, 2]]]);

it('accepts numbers within the range', function (
    float|int $min,
    float|int $max,
    float|int $value,
): void {
    $validator = new BetweenValidator($min, $max);

    expect($validator($value, 'field'))->toBeTrue();
})->with([
    'inside range' => [1, 3, 2],
    'at min' => [1, 3, 1],
    'at max' => [1, 3, 3],
    'negative ok' => [-5, -1, -3],
    'inside decimal range' => [2.5, 3.5, 3.0],
]);

it('accepts strings within the length range', function (
    int $min,
    int $max,
    string $value,
): void {
    $validator = new BetweenValidator($min, $max);

    expect($validator($value, 'name'))->toBeTrue();
})->with([
    'boundary min' => [2, 4, 'ab'],
    'boundary max' => [2, 4, 'abcd'],
    'length ok' => [3, 5, 'abc'],
]);
