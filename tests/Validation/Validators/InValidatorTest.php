<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\Validation\AllowedField;
use HosmelQ\SearchSyntaxParser\Validation\AllowedFieldItemRules;
use HosmelQ\SearchSyntaxParser\Validation\Validators\InValidator;

it('rejects ambiguous allowed string values when configured', function (array $values): void {
    expect(fn (): AllowedField => AllowedField::in('status', $values))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn (): AllowedFieldItemRules => new AllowedFieldItemRules()->in($values))
        ->toThrow(InvalidArgumentException::class);
})->with([[['open', 'OPEN']], [['Été', 'été']], [['same', 'same']], [['', '']]]);

it('rejects arrays with values outside the allowed list', function (
    array $allowed,
    array $value,
): void {
    $validator = new InValidator($allowed);

    expect($validator($value, 'tags'))->toBeFalse();
})->with([
    'contains nested array' => [['A', 'B'], ['A', ['B']]],
    'contains unsupported object' => [['A'], [new stdClass()]],
    'contains disallowed' => [['A', 'B'], ['A', 'C']],
]);

it('rejects scalar values outside the allowed list', function (array $allowed, mixed $value): void {
    $validator = new InValidator($allowed);

    expect($validator($value, 'status'))->toBeFalse();
})->with([
    'not in list' => [['A', 'B', '1'], 'C'],
    'not in list numeric string' => [[1, 2, 3], '4'],
    'unsupported object' => [['A'], new stdClass()],
    'unsupported allowed object' => [[new stdClass()], 'A'],
    'unsupported allowed array' => [[['A']], 'A'],
]);

it('accepts arrays of allowed values', function (array $allowed, array $value): void {
    $validator = new InValidator($allowed);

    expect($validator($value, 'tags'))->toBeTrue();
})->with([
    'mixed scalar values' => [['1', '1.5', ''], [true, 1.5, null]],
    'all allowed' => [['A', 'B'], ['A', 'B']],
    'empty array' => [['A', 'B'], []],
]);

it('accepts scalar values in the allowed list', function (array $allowed, mixed $value): void {
    $validator = new InValidator($allowed);

    expect($validator($value, 'status'))->toBeTrue();
})->with([
    'in list string' => [['A', 'B', '1'], 'A'],
    'in list numeric string' => [[1, 2, 3], '1'],
    'in list integer' => [['1', '2', '3'], 1],
    'in list float' => [['1.5'], 1.5],
    'in list boolean' => [['1'], true],
    'in list null' => [[''], null],
]);

it('compares stringable values in both inputs and allowed lists', function (): void {
    $value = new class () implements Stringable {
        public function __toString(): string
        {
            return 'A';
        }
    };

    expect((new InValidator(['A']))($value, 'status'))
        ->toBeTrue()
        ->and((new InValidator(['A']))([$value], 'status'))
        ->toBeTrue()
        ->and((new InValidator([$value]))('A', 'status'))
        ->toBeTrue();
});
