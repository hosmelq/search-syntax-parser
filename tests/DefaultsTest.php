<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\Adapter\ArrayAdapter;
use HosmelQ\SearchSyntaxParser\Exception\ParseException;
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;

it('applies defaults at the AST level in configured order', function (): void {
    $parser = SearchParser::query('coffee')->allowedFields([
        AllowedField::integer('age', 'user_age')->default(18),
        AllowedField::array('status')->default(['active', 'draft']),
        AllowedField::string('deleted')->default(null),
    ]);

    expect($parser->build('string'))->toBe(
        'coffee AND age:18 AND status:active,draft AND NOT deleted:*',
    )
        ->and($parser->build())->toBe(new ArrayAdapter($parser)->build($parser->parse()))
        ->and($parser->build()['left']['left']['right']['field'])->toBe('user_age');
});

it('supports every scalar default and singleton lists', function (
    mixed $default,
    array $expected,
): void {
    expect(
        SearchParser::query('')
            ->allowedFields([new AllowedField('value')->default($default)])
            ->build(),
    )->toBe(
        $expected,
    );
})->with([
    [
        null,
        [
            'operand' => ['field' => 'value', 'type' => 'exists'],
            'operator' => 'NOT',
            'type' => 'unary',
        ],
    ],
    [true, ['field' => 'value', 'operator' => '=', 'type' => 'comparison', 'value' => true]],
    [false, ['field' => 'value', 'operator' => '=', 'type' => 'comparison', 'value' => false]],
    [0, ['field' => 'value', 'operator' => '=', 'type' => 'comparison', 'value' => 0]],
    [1.5, ['field' => 'value', 'operator' => '=', 'type' => 'comparison', 'value' => 1.5]],
    ['', ['field' => 'value', 'operator' => '=', 'type' => 'comparison', 'value' => '']],
    [
        [true, 2, 3.5, 'four'],
        ['field' => 'value', 'operator' => '=', 'type' => 'in', 'values' => [true, 2, 3.5, 'four']],
    ],
    [['one'], ['field' => 'value', 'operator' => '=', 'type' => 'in', 'values' => ['one']]],
]);

it('uses defaults for empty and whitespace-only queries', function (string $query): void {
    expect(
        SearchParser::query($query)
            ->allowedFields([AllowedField::string('status')->default('active')])
            ->build('string'),
    )->toBe('status:active');
})->with(['', '   ', "\n\t", "\u{00A0}"]);

it('omits defaults for fields referenced anywhere in the AST', function (string $query): void {
    $parser = SearchParser::query($query)->allowedFields([
        new AllowedField('status')->default('fallback'),
    ]);

    expect($parser->build())->toBe(SearchParser::query($query)->build());
})->with([
    'status:active', 'NOT status:active', 'status:active,draft', 'status:[a TO z]',
    'status:*', 'NOT status:*', 'status:act*', 'status:act*,draft',
    'coffee OR (tea AND NOT status:active)',
]);

it('uses external names when deciding whether a default applies', function (): void {
    expect(SearchParser::query('status:active')->allowedFields([
        AllowedField::string('status', 'stored_status')->default('fallback'),
        AllowedField::string('stored_status', 'other_status')->default('draft'),
    ])->build('string'))->toBe('status:active AND stored_status:draft');
});

it('trusts defaults without casting validation or clause and depth limits', function (): void {
    $parser = SearchParser::query('coffee')->maxDepth(1)->maxClauses(1)->allowedFields([
        AllowedField::integer('age')->min(99)->default('trusted'),
        AllowedField::array('tags')->size(99)->default(['a', 'b']),
        AllowedField::string('missing')->default(null),
    ]);

    expect($parser->build('string'))->toBe('coffee AND age:trusted AND tags:a,b AND NOT missing:*');
});

it('still checks the raw query length when defaults exist', function (): void {
    SearchParser::query('    ')
        ->maxLength(3)
        ->allowedFields([AllowedField::string('status')->default('active')])
        ->parse();
})->throws(ParseException::class, 'maximum length');

it('observes defaults configured after allowedFields', function (): void {
    $field = AllowedField::string('status');
    $parser = SearchParser::query('')->allowedFields([$field]);

    $field->default('active');

    expect($parser->build('string'))->toBe('status:active');
});

it('rejects invalid defaults immediately', function (mixed $default): void {
    AllowedField::string('status')->default($default);
})->throws(InvalidArgumentException::class)->with([
    'empty list' => [[]],
    'associative array' => [['x' => 'value']],
    'sparse list' => [[1 => 'value']],
    'nested list' => [[['value']]],
    'null item' => [[null]],
    'object' => [new stdClass()],
    'object item' => [[new stdClass()]],
]);

it('supports defaults on numeric external field names', function (): void {
    expect(
        SearchParser::query('')
            ->allowedFields([AllowedField::integer('123')->default(7)])
            ->build('string'),
    )->toBe(
        '123:7',
    );
});
