<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\Validation\AllowedField;
use HosmelQ\SearchSyntaxParser\Validation\AllowedFieldItemRules;

it('returns field name and internal name', function (): void {
    $field = new AllowedField('age');
    $fieldWithInternalName = new AllowedField('age', 'user_age');

    expect($field)
        ->getName()->toBe('age')
        ->getInternalName()->toBe('age')
        ->and($fieldWithInternalName->getInternalName())->toBe('user_age');
});

it('handles default values and nullability', function (): void {
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

it('validates boolean values', function (): void {
    $booleanField = AllowedField::boolean('flag');

    expect($booleanField)
        ->validate('true')->toBeFalse()
        ->validate(2)->toBeFalse()
        ->validate(true)->toBeTrue()
        ->validate('0')->toBeTrue();
});

it('validates integer values', function (): void {
    $integerField = AllowedField::integer('qty');

    expect($integerField)
        ->validate('10.0')->toBeFalse()
        ->validate('10')->toBeTrue()
        ->validate(-2)->toBeTrue();
});

it('validates numeric values', function (): void {
    $numericField = AllowedField::numeric('amount');

    expect($numericField)
        ->validate('abc')->toBeFalse()
        ->validate('10.5')->toBeTrue()
        ->validate(0)->toBeTrue();
});

it('validates string values', function (): void {
    $stringField = AllowedField::string('name');

    expect($stringField)
        ->validate(123)->toBeFalse()
        ->validate('abc')->toBeTrue();
});

it('validates decimal values (exact and ranged)', function (): void {
    $exactDecimalField = AllowedField::decimal('price', 2);
    $rangedDecimalField = AllowedField::decimal('ratio', 1, 3);

    expect($exactDecimalField)
        ->validate('10')->toBeFalse()
        ->validate('10.12')->toBeTrue()
        ->and($rangedDecimalField)
        ->validate('10.1234')->toBeFalse()
        ->validate('10.12')->toBeTrue();
});

it('validates allowed values (in)', function (): void {
    $inField = AllowedField::in('status', ['A', 'B', '1']);

    expect($inField)
        ->validate('C')->toBeFalse()
        ->validate('A')->toBeTrue()
        ->validate(1)->toBeTrue();
});

it('validates min, max, between, and size constraints', function (): void {
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

// covered below in 'validates rules applied to array items'

it('validates index-specific rules and combined rules', function (): void {
    $tuple = AllowedField::array('tuple')
        ->size(2)
        ->each(fn ($rules): AllowedFieldItemRules => $rules->string())
        ->at(0, fn ($rules): AllowedFieldItemRules => $rules->size(3))
        ->at(1, fn ($rules): AllowedFieldItemRules => $rules->max(3));

    expect($tuple->validate(['abcd', 'x']))->toBeFalse()
        ->and($tuple->validate(['abc', 'abcd']))->toBeFalse()
        ->and($tuple->validate(['abc', 'xy']))->toBeTrue();

    $missingIndexField = AllowedField::array('pair')
        ->at(1, fn ($rules): AllowedFieldItemRules => $rules->string()->max(3));

    expect($missingIndexField->validate([123]))->toBeTrue();
});

it('validates per-item rules on arrays', function (): void {
    $arrayWithLimits = AllowedField::array('keywords')
        ->max(2)
        ->each(fn ($rules): AllowedFieldItemRules => $rules->string()->max(5));

    expect($arrayWithLimits->validate(['a', 'b', 'c']))->toBeFalse()
        ->and($arrayWithLimits->validate(['aaaaaa']))->toBeFalse()
        ->and($arrayWithLimits->validate('not-array'))->toBeFalse()
        ->and($arrayWithLimits->validate(['aa', 'bbb']))->toBeTrue();

    $booleanRulesField = AllowedField::array('values')
        ->each(fn ($rules): AllowedFieldItemRules => $rules->boolean());

    expect($booleanRulesField->validate(['x']))->toBeFalse()
        ->and($booleanRulesField->validate([1, '0', true]))->toBeTrue();

    $callbackRulesField = AllowedField::array('values')
        ->each(fn ($rules): AllowedFieldItemRules => $rules->callback(fn ($value): bool => is_int($value)));

    expect($callbackRulesField->validate(['x']))->toBeFalse()
        ->and($callbackRulesField->validate([1, 2]))->toBeTrue();

    $decimalRulesField = AllowedField::array('values')
        ->each(fn ($rules): AllowedFieldItemRules => $rules->decimal(2));

    expect($decimalRulesField->validate(['1']))->toBeFalse()
        ->and($decimalRulesField->validate(['1.23']))->toBeTrue();

    $inRulesField = AllowedField::array('values')
        ->each(fn ($rules): AllowedFieldItemRules => $rules->in(['A', 'B', '1']));

    expect($inRulesField->validate(['C']))->toBeFalse()
        ->and($inRulesField->validate(['A', '1']))->toBeTrue();

    $integerRulesField = AllowedField::array('values')
        ->each(fn ($rules): AllowedFieldItemRules => $rules->integer()->min(0)->max(10));

    expect($integerRulesField->validate(['x']))->toBeFalse()
        ->and($integerRulesField->validate([5]))->toBeTrue();

    $numericBetweenField = AllowedField::array('values')
        ->each(fn ($rules): AllowedFieldItemRules => $rules->numeric()->between(1, 2));

    expect($numericBetweenField->validate([3]))->toBeFalse()
        ->and($numericBetweenField->validate([1.5]))->toBeTrue();
});
