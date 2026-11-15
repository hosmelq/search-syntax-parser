<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;
use HosmelQ\SearchSyntaxParser\Validation\AllowedFieldItemRules;
use HosmelQ\SearchSyntaxParser\Validation\Validators\InValidator;

it('returns canonical allowed strings for Unicode and quoted input', function (): void {
    $field = AllowedField::in('status', ['open', 'Été']);

    expect(SearchParser::query('status:OPEN,"ÉTÉ"')->allowedFields([$field])->parse()->getValues())
        ->toBe(['open', 'Été'])
        ->and($field->tryCast('éTé'))->toBe('Été')
        ->and($field->validate('open'))->toBeTrue()
        ->and($field->validate('OPEN'))->toBeFalse()
        ->and(new InValidator(['open', 'Été'])(['open', 'Été'], 'status'))->toBeTrue()
        ->and(
            SearchParser::query('')->allowedFields([$field->default('OPEN')])->parse()->getValue(),
        )
        ->toBe('open');
});

it('canonicalizes case-insensitive shared and positional members', function (): void {
    $field = AllowedField::array('status')
        ->each(fn ($rules) => $rules->in(['open', 'closed']))
        ->at(1, fn ($rules) => $rules->in(['closed']));

    expect(SearchParser::query('status:OPEN,CLOSED')->allowedFields([$field])->parse()->getValues())
        ->toBe(['open', 'closed']);
});

it('rejects ambiguous allowed string values when configured', function (array $values): void {
    expect(fn (): AllowedField => AllowedField::in('status', $values))->toThrow(
        InvalidArgumentException::class,
    )
        ->and(fn (): AllowedFieldItemRules => new AllowedFieldItemRules()->in($values))->toThrow(
            InvalidArgumentException::class,
        );
})->with([
    [['open', 'OPEN']], [['Été', 'été']], [['same', 'same']], [['', '']],
]);

it('keeps the existing matching of non-string members', function (): void {
    $field = AllowedField::in('value', [true, false, 2, 1.5]);

    expect(
        SearchParser::query('value:TRUE,0,2,1.50')->allowedFields([$field])->parse()->getValues(),
    )->toBe([true, false, 2, 1.5]);
});

it('ignores strings case-insensitively before validation in every value context', function (
    string $query,
    null|string $expected,
): void {
    $field = AllowedField::in('status', ['open', 'closed'])->ignore('ÉTÉ');

    expect(SearchParser::query($query)->allowedFields([$field])->build('string'))->toBe($expected);
})->with([
    ['status:été', null],
    ['status:éTÉ,OPEN,"été",CLOSED', 'status:open,closed'],
    ['status:[été TO OPEN]', 'status:[* TO open]'],
    ['status:[OPEN TO été]', 'status:[open TO *]'],
]);

it('keeps non-string ignore values strict and alias tokens case-sensitive', function (): void {
    $field = new AllowedField('value')->ignore(0, false, 'all')->alias('ALL', 42);

    expect($field->isIgnored('0'))->toBeFalse()
        ->and($field->isIgnored(0.0))->toBeFalse()
        ->and($field->isIgnored(null))->toBeFalse()
        ->and(SearchParser::query('value:ALL')->allowedFields([$field])->parse()->getValue())->toBe(
            42,
        )
        ->and(SearchParser::query('value:All')->allowedFields([$field])->parse())->toBeNull()
        ->and(SearchParser::query('value:"ALL"')->allowedFields([$field])->parse())->toBeNull();
});
