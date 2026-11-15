<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\Exception\InvalidFieldValueException;
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Tests\TestSupport\PriorityLevel;
use HosmelQ\SearchSyntaxParser\Tests\TestSupport\PublicationStatus;
use HosmelQ\SearchSyntaxParser\Tests\TestSupport\UnbackedStatus;
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;
use HosmelQ\SearchSyntaxParser\Validation\AllowedFieldItemRules;

it('validates dates and ISO datetimes while preserving raw text', function (string $value): void {
    foreach ([$value, '"'.$value.'"'] as $text) {
        expect(
            SearchParser::query('date:'.$text)
                ->allowedFields([AllowedField::date('date')])
                ->build()['value'],
        )->toBe($value);
    }
})->with([
    '2024-02-29', '2025-01-01', '2025-01-01T12:30', '2025-01-01T12:30Z',
    '2025-01-01T12:30:45', '2025-01-01T12:30:45.123456789Z',
    '2025-01-01T00:00:00+05', '2025-01-01T23:59:59-05:30',
]);

it('rejects invalid dates and times', function (string $value): void {
    SearchParser::query('date:"'.$value.'"')->allowedFields([AllowedField::date('date')])->build();
})->throws(InvalidFieldValueException::class)->with([
    '2025-02-29', '2025-02-30', '2025-13-01', '2025-00-01', '2025-01-00',
    '0000-01-01', '2025-1-1', 'tomorrow', '2025-01-01T25:00',
    '2025-01-01T24:00', '2025-01-01T12:60', '2025-01-01T12:30:60',
    '2025-01-01T12:30.1', '2025-01-01T12:30:00.',
    '2025-01-01T12:30+25:00', '2025-01-01T12:30-05:60',
    '2025-01-01T12:30Ztrailing', '2025-01-01 ',
]);

it('requires an exact custom date format without overflow or trailing input', function (
    string $value,
    bool $valid,
): void {
    $parser = SearchParser::query('date:"'.$value.'"')->allowedFields([
        AllowedField::date('date', 'd/m/Y'),
    ]);

    if ($valid) {
        expect($parser->build()['value'])->toBe($value);
    } else {
        expect(fn (): mixed => $parser->build())->toThrow(InvalidFieldValueException::class);
    }
})->with([
    ['29/02/2024', true], ['01/01/2025', true], ['29/02/2025', false],
    ['1/1/2025', false], ['01/01/2025tail', false], ["01/01/2025\0", false],
]);

it('casts backed enum values to their backing type', function (
    string $query,
    string $enum,
    int|string $expected,
): void {
    $parser = SearchParser::query($query)->allowedFields([
        AllowedField::enum('value', $enum, 'stored_value'),
    ]);

    expect($parser->build())->toBe([
        'default' => false,
        'field' => 'stored_value',
        'operator' => '=',
        'type' => 'comparison',
        'value' => $expected,
    ]);
})->with([
    ['value:draft', PublicationStatus::class, 'draft'],
    ['value:"published"', PublicationStatus::class, 'published'],
    ['value:2', PriorityLevel::class, 2],
    ['value:"1"', PriorityLevel::class, 1],
]);

it('rejects values outside an enum', function (): void {
    SearchParser::query('value:other')
        ->allowedFields([AllowedField::enum('value', PublicationStatus::class)])
        ->build();
})->throws(InvalidFieldValueException::class);

it('rejects enum rules without a backed enum', function (string $enum): void {
    expect(fn (): AllowedField => AllowedField::enum('value', $enum))->toThrow(
        InvalidArgumentException::class,
    )
        ->and(fn (): AllowedFieldItemRules => new AllowedFieldItemRules()->enum($enum))->toThrow(
            InvalidArgumentException::class,
        );
})->with([UnbackedStatus::class, stdClass::class]);

it('supports date and enum item rules in each and at', function (): void {
    $field = AllowedField::array('values')
        ->each(
            fn (AllowedFieldItemRules $rules): AllowedFieldItemRules
                => $rules->callback(fn (): bool => true),
        )
        ->at(0, fn (AllowedFieldItemRules $rules): AllowedFieldItemRules => $rules->date('d/m/Y'))
        ->at(
            1,
            fn (AllowedFieldItemRules $rules): AllowedFieldItemRules
                => $rules->enum(PriorityLevel::class),
        );

    expect(
        SearchParser::query('values:"29/02/2024",2')->allowedFields([$field])->build()['values'],
    )->toBe(
        ['29/02/2024', 2],
    );

    $dates = AllowedField::array('values')->each(
        fn (AllowedFieldItemRules $rules): AllowedFieldItemRules => $rules->date(),
    );
    $enums = AllowedField::array('values')->each(
        fn (AllowedFieldItemRules $rules): AllowedFieldItemRules
            => $rules->enum(PublicationStatus::class),
    );

    expect(
        SearchParser::query('values:2024-02-29,2025-01-01')
            ->allowedFields([$dates])
            ->build()['values'],
    )
        ->toBe(['2024-02-29', '2025-01-01'])
        ->and(
            SearchParser::query('values:draft,published')
                ->allowedFields([$enums])
                ->build()['values'],
        )
        ->toBe(['draft', 'published'])
        ->and(
            fn (): mixed
                => SearchParser::query('values:2025-02-30')->allowedFields([$dates])->build(),
        )
        ->toThrow(InvalidFieldValueException::class)
        ->and(
            fn (): mixed
                => SearchParser::query('values:draft,other')->allowedFields([$enums])->build(),
        )
        ->toThrow(InvalidFieldValueException::class);
});
