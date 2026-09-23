<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\Validation\Validators\InValidator;

it('validates scalar values against allowed list', function (array $allowed, mixed $value, bool $expected): void {
    $validator = new InValidator($allowed);

    expect($validator($value, 'status'))->toBe($expected);
})->with([
    'not in list' => [['A', 'B', '1'], 'C', false],
    'not in list numeric string' => [[1, 2, 3], '4', false],
    'in list string' => [['A', 'B', '1'], 'A', true],
    'in list numeric string' => [[1, 2, 3], '1', true],
    'in list integer' => [['1', '2', '3'], 1, true],
    'in list float' => [['1.5'], 1.5, true],
    'in list boolean' => [['1'], true, true],
    'in list null' => [[''], null, true],
    'unsupported object' => [['A'], new stdClass(), false],
    'unsupported allowed object' => [[new stdClass()], 'A', false],
    'unsupported allowed array' => [[['A']], 'A', false],
]);

it('validates arrays against allowed list', function (array $allowed, array $value, bool $expected): void {
    $validator = new InValidator($allowed);

    expect($validator($value, 'tags'))->toBe($expected);
})->with([
    'contains nested array' => [['A', 'B'], ['A', ['B']], false],
    'contains unsupported object' => [['A'], [new stdClass()], false],
    'mixed scalar values' => [['1', '1.5', ''], [true, 1.5, null], true],
    'contains disallowed' => [['A', 'B'], ['A', 'C'], false],
    'all allowed' => [['A', 'B'], ['A', 'B'], true],
    'empty array' => [['A', 'B'], [], true],
]);

it('compares stringable values in both inputs and allowed lists', function (): void {
    $value = new class () implements Stringable {
        public function __toString(): string
        {
            return 'A';
        }
    };

    expect((new InValidator(['A']))($value, 'status'))->toBeTrue()
        ->and((new InValidator(['A']))([$value], 'status'))->toBeTrue()
        ->and((new InValidator([$value]))('A', 'status'))->toBeTrue();
});
