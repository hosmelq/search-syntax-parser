<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\Tests\TestSupport\ValidatesAttributesTestHelper;

it('computes size for numbers, arrays, and strings', function (): void {
    $helper = new ValidatesAttributesTestHelper();

    expect($helper)
        ->sizeOf('num', 10)->toBe(10)
        ->sizeOf('float', 10.5)->toBe(10.5)
        ->sizeOf('arr', [1, 2, 3])->toBe(3)
        ->sizeOf('str', 'Coffee')->toBe(6)
        ->sizeOf('null', null)->toBe(0);
});

it('trims only strings and leaves other values untouched', function (): void {
    $helper = new ValidatesAttributesTestHelper();

    expect($helper)
        ->trimValue('  Coffee  ')->toBe('Coffee')
        ->trimValue(10)->toBe(10)
        ->trimValue([1, 2])->toBe([1, 2]);
});

it('requires a minimum number of parameters', function (): void {
    $helper = new ValidatesAttributesTestHelper();

    $helper->requireParams(1, ['x'], 'rule');

    expect(true)->toBeTrue();
});

it('throws when required parameters are missing', function (): void {
    $helper = new ValidatesAttributesTestHelper();

    $helper->requireParams(2, ['x'], 'between');
})->throws(InvalidArgumentException::class, 'requires at least 2 parameters');

it('trims string parameters in numeric comparisons', function (): void {
    $helper = new ValidatesAttributesTestHelper();

    expect($helper)
        ->min('a', 5, [' 5 '])->toBeTrue()
        ->max('a', 5, ["\n5\t"])->toBeTrue()
        ->size('a', 'Coffee', [' 6 '])->toBeTrue()
        ->between('a', 5, [' 5 ', ' 10 '])->toBeTrue();
});

it('returns false when numeric comparisons receive non-numeric parameters', function (): void {
    $helper = new ValidatesAttributesTestHelper();

    expect($helper)
        ->min('a', 5, ['abc'])->toBeFalse()
        ->max('a', 5, ['abc'])->toBeFalse()
        ->size('a', 5, ['abc'])->toBeFalse()
        ->between('a', 5, ['a', 'b'])->toBeFalse();
});
