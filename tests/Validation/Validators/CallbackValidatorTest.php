<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\Validation\Validators\CallbackValidator;

it('rejects values refused by the callback', function (): void {
    $validator = new CallbackValidator(function (mixed $value, string $attribute): bool {
        return is_numeric($value) && $attribute === 'age' && (int) $value >= 18;
    });

    expect($validator('seventeen', 'age'))->toBeFalse()
        ->and($validator(17, 'age'))->toBeFalse()
        ->and($validator(18, 'other'))->toBeFalse();
});

it('accepts values approved by the callback', function (): void {
    $validator = new CallbackValidator(function (mixed $value, string $attribute): bool {
        return is_numeric($value) && $attribute === 'age' && (int) $value >= 18;
    });

    expect($validator(18, 'age'))->toBeTrue()->and($validator(25, 'age'))->toBeTrue();
});
