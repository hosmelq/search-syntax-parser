<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\Exception\InvalidFieldValueException;
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;

it('accepts UUIDs of any version and normalizes them to lowercase', function (string $value): void {
    $field = AllowedField::uuid('id');

    foreach ([$value, '"'.$value.'"'] as $input) {
        expect(
            SearchParser::query('id:'.$input)->allowedFields([$field])->parse()->getValue(),
        )->toBe(
            mb_strtolower($value),
        );
    }
})->with([
    '00000000-0000-0000-0000-000000000000',
    'FFFFFFFF-FFFF-FFFF-FFFF-FFFFFFFFFFFF',
    '550E8400-E29B-41D4-A716-446655440000',
    '550E8400-E29B-F1D4-0716-446655440000',
]);

it('accepts ULIDs within 128 bits and normalizes them to uppercase', function (
    string $value,
): void {
    $field = AllowedField::ulid('id');

    expect(
        SearchParser::query('id:"'.$value.'"')->allowedFields([$field])->parse()->getValue(),
    )->toBe(
        mb_strtoupper($value),
    );
})->with([
    '00000000000000000000000000',
    '7ZZZZZZZZZZZZZZZZZZZZZZZZZ',
    '01arz3ndektsv4rrffq69g5fav',
]);

it('rejects malformed UUIDs and ULIDs', function (string $type, string $value): void {
    SearchParser::query('id:"'.$value.'"')->allowedFields([AllowedField::$type('id')])->parse();
})->throws(InvalidFieldValueException::class)->with([
    ['uuid', '550e8400e29b41d4a716446655440000'],
    ['uuid', '550e8400-e29b-41d4-a716-44665544000g'],
    ['uuid', '550e8400-e29b-41d4-a716-4466554400000'],
    ['uuid', '{550e8400-e29b-41d4-a716-446655440000}'],
    ['uuid', "550e8400-e29b-41d4-a716-446655440000\n"],
    ['ulid', '8ZZZZZZZZZZZZZZZZZZZZZZZZZ'],
    ['ulid', '01ARZ3NDEKTSV4RRFFQ69G5FAI'],
    ['ulid', '01ARZ3NDEKTSV4RRFFQ69G5FAL'],
    ['ulid', '01ARZ3NDEKTSV4RRFFQ69G5FAO'],
    ['ulid', '01ARZ3NDEKTSV4RRFFQ69G5FAU'],
    ['ulid', '01ARZ3NDEKTSV4RRFFQ69G5FA'],
    ['ulid', '01ARZ3NDEKTSV4RRFFQ69G5FAV0'],
    ['ulid', '000000000000000000000000ß'],
    ['ulid', "01ARZ3NDEKTSV4RRFFQ69G5FAV\n"],
]);

it('supports UUID and ULID shared and positional item rules and defaults', function (
    string $type,
    string $value,
): void {
    $field = AllowedField::array('ids')
        ->each(fn ($rules) => $rules->$type())
        ->at(1, fn ($rules) => $rules->$type())
        ->default([$value, $value]);
    $expected = $type === 'uuid' ? mb_strtolower($value) : mb_strtoupper($value);

    expect(
        SearchParser::query('ids:'.$value.',"'.$value.'"')
            ->allowedFields([$field])
            ->parse()
            ->getValues(),
    )
        ->toBe([$expected, $expected])
        ->and(SearchParser::query('')->allowedFields([$field])->parse()->getValues())->toBe([
            $expected,
            $expected,
        ])
        ->and($field->hasCastType(1))->toBeFalse()
        ->and($field->hasCastType($value))->toBeTrue();
})->with([
    ['uuid', '550E8400-E29B-41D4-A716-446655440000'],
    ['ulid', '01arz3ndektsv4rrffq69g5fav'],
]);

it('rejects non-string identifier defaults as configuration errors', function (string $type): void {
    SearchParser::query('')->allowedFields([AllowedField::$type('id')->default(1)])->parse();
})->throws(InvalidArgumentException::class)->with(['uuid', 'ulid']);
