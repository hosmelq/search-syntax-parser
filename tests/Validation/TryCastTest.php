<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\Tests\TestSupport\PriorityLevel;
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;

it('probes typed values without turning invalid input into a query error', function (
    AllowedField $field,
    string $value,
    mixed $expected,
): void {
    expect($field->tryCast($value))->toBe($expected);
})->with([
    [AllowedField::integer('id')->min(1), '1042', 1_042],
    [AllowedField::integer('id'), 'abc', null],
    [AllowedField::integer('id'), '1.5', null],
    [AllowedField::integer('id')->min(1), '0', null],
    [AllowedField::numeric('price'), '2.5', 2.5],
    [AllowedField::decimal('price', 2), '2.50', '2.50'],
    [AllowedField::decimal('price', 2), '2.5', null],
    [AllowedField::boolean('active'), 'false', false],
    [AllowedField::integer('count'), '0', 0],
    [AllowedField::string('title'), '', ''],
    [AllowedField::string('code')->size(3), '123', '123'],
    [AllowedField::in('status', ['open', 'closed']), 'invalid', null],
    [AllowedField::enum('priority', PriorityLevel::class), '2', 2],
    [AllowedField::date('date'), '2025-02-30', null],
    [AllowedField::date('date'), '2024-02-29', '2024-02-29'],
    [
        AllowedField::callback(
            'id',
            fn ($value, $name): bool => $name === 'stored_id' && $value === 42,
            'stored_id',
        ),
        '42',
        42,
    ],
]);

it('resolves unquoted aliases and validates their results without recasting', function (): void {
    $id = 7;
    $field = AllowedField::integer('id')->min(5)->alias('@me', static function () use (&$id): int {
        return $id;
    });

    expect($field->tryCast('@me'))->toBe(7)
        ->and($field->tryCast('@me', quoted: true))->toBeNull()
        ->and(AllowedField::string('code')->alias('token', 7)->tryCast('token'))->toBeNull()
        ->and(new AllowedField('code')->tryCast('42', quoted: true))->toBe('42');

    $id = 3;

    expect($field->tryCast('@me'))->toBeNull();
});

it('probes array items with collection shared and positional rules', function (): void {
    $field = AllowedField::array('tuple')
        ->max(2)
        ->each(fn ($rules) => $rules->numeric()->min(0))
        ->at(1, fn ($rules) => $rules->integer()->max(10));

    expect($field->tryCast('2.5'))->toBe(2.5)
        ->and($field->tryCast('-1'))->toBeNull()
        ->and($field->tryCast('7', index: 1))->toBe(7)
        ->and($field->tryCast('2.5', index: 1))->toBeNull()
        ->and($field->tryCast('11', index: 1))->toBeNull()
        ->and(AllowedField::array('tags')->size(2)->tryCast('tag'))->toBeNull();
});

it('propagates configuration errors instead of hiding them', function (): void {
    $field = new AllowedField('id')->alias('bad', static fn (): array => []);

    expect(fn (): mixed => $field->tryCast('bad'))->toThrow(
        InvalidArgumentException::class,
        'A value alias must resolve to a bool, float, int, or string.',
    );
});
