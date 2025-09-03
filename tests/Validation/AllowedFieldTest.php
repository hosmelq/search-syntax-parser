<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\Validation\AllowedField;

it('returns name and internal name', function (): void {
    $field = new AllowedField('age');
    $fieldWithInternalName = new AllowedField('age', 'user_age');

    expect($field)
        ->getName()->toBe('age')
        ->getInternalName()->toBe('age')
        ->and($fieldWithInternalName->getInternalName())->toBe('user_age');
});

it('handles defaults and nullability', function (): void {
    $fieldWithNullDefault = (new AllowedField('status'))->default(null);
    $fieldWithDefault = (new AllowedField('status'))->default('DRAFT');

    expect($fieldWithNullDefault)
        ->hasDefault()->toBeTrue()
        ->getDefault()->toBeNull()
        ->isNullable()->toBeTrue()
        ->and($fieldWithDefault)
        ->hasDefault()->toBeTrue()
        ->getDefault()->toBe('DRAFT')
        ->isNullable()->toBeFalse();
});

it('validates ignored and nullable values', function (): void {
    $fieldWithIgnoredValues = AllowedField::integer('count')->ignore('N/A', null);
    $nullableField = AllowedField::integer('count')->nullable();

    expect($fieldWithIgnoredValues)
        ->validate('N/A')->toBeTrue()
        ->validate(null)->toBeTrue()
        ->and($nullableField)
        ->validate(null)->toBeTrue();
});

it('validates boolean field', function (): void {
    $booleanField = AllowedField::boolean('flag');

    expect($booleanField)
        ->validate('true')->toBeFalse()
        ->validate(2)->toBeFalse()
        ->validate(true)->toBeTrue()
        ->validate('0')->toBeTrue();
});

it('validates integer field', function (): void {
    $integerField = AllowedField::integer('qty');

    expect($integerField)
        ->validate('10.0')->toBeFalse()
        ->validate('10')->toBeTrue()
        ->validate(-2)->toBeTrue();
});

it('validates numeric field', function (): void {
    $numericField = AllowedField::numeric('amount');

    expect($numericField)
        ->validate('abc')->toBeFalse()
        ->validate('10.5')->toBeTrue()
        ->validate(0)->toBeTrue();
});

it('validates string field', function (): void {
    $stringField = AllowedField::string('name');

    expect($stringField)
        ->validate(123)->toBeFalse()
        ->validate('abc')->toBeTrue();
});

it('validates decimal field (exact and ranged)', function (): void {
    $exactDecimalField = AllowedField::decimal('price', 2);
    $rangedDecimalField = AllowedField::decimal('ratio', 1, 3);

    expect($exactDecimalField)
        ->validate('10')->toBeFalse()
        ->validate('10.12')->toBeTrue()
        ->and($rangedDecimalField)
        ->validate('10.1234')->toBeFalse()
        ->validate('10.12')->toBeTrue();
});

it('validates in field', function (): void {
    $inField = AllowedField::in('status', ['A', 'B', '1']);

    expect($inField)
        ->validate('C')->toBeFalse()
        ->validate('A')->toBeTrue()
        ->validate(1)->toBeTrue();
});

it('validates chained constraints for min, max, between, and size', function (): void {
    $rangeField = AllowedField::numeric('age')->min(18)->max(65);
    $betweenField = AllowedField::numeric('score')->between(0, 100);
    $sizeField = AllowedField::string('code')->size(3);

    expect($rangeField)
        ->validate(17)->toBeFalse()
        ->validate(66)->toBeFalse()
        ->validate(18)->toBeTrue()
        ->validate(65)->toBeTrue()
        ->and($betweenField)
        ->validate(101)->toBeFalse()
        ->validate(50)->toBeTrue()
        ->and($sizeField)
        ->validate('ab')->toBeFalse()
        ->validate('abc')->toBeTrue();
});
