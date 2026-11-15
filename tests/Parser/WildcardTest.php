<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\Exception\InvalidFieldValueException;
use HosmelQ\SearchSyntaxParser\Exception\ParseException;
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;
use HosmelQ\SearchSyntaxParser\Validation\AllowedFieldItemRules;

it('builds explicit wildcard patterns', function (
    string $query,
    null|string $field,
    string $operator,
    array $segments,
): void {
    expect(SearchParser::query($query)->allowAnyField()->allowLeadingWildcards()->build())->toBe([
        'field' => $field,
        'operator' => $operator,
        'segments' => $segments,
        'type' => 'wildcard',
    ]);
})->with([
    ['nike*', null, '=', ['nike', '']],
    ['*fee', null, '=', ['', 'fee']],
    ['co*fee', null, '=', ['co', 'fee']],
    ['a**b', null, '=', ['a', 'b']],
    ['***', null, '=', ['', '']],
    ['"a b"*', null, '=', ['a b', '']],
    ['name:nike*', 'name', '=', ['nike', '']],
    ['name:!=*fee', 'name', '!=', ['', 'fee']],
    ['name!=co*fee', 'name', '!=', ['co', 'fee']],
    ['name:"Co"*', 'name', '=', ['Co', '']],
    ["name:'Co'*", 'name', '=', ['Co', '']],
    ['name:"a*b"*', 'name', '=', ['a*b', '']],
    ['name:""*', 'name', '=', ['', '']],
]);

it('keeps quoted wildcard text literal', function (string $query, array $expected): void {
    expect(SearchParser::query($query)->allowAnyField()->build())->toBe($expected);
})->with([
    ['"co*fee"', ['quoted' => true, 'type' => 'term', 'value' => 'co*fee']],
    [
        'name:"*"',
        [
            'default' => false,
            'field' => 'name',
            'match' => 'exact',
            'operator' => '=',
            'quoted' => true,
            'type' => 'comparison',
            'value' => '*',
        ],
    ],
    [
        'name:"nike*"',
        [
            'default' => false,
            'field' => 'name',
            'match' => 'exact',
            'operator' => '=',
            'quoted' => true,
            'type' => 'comparison',
            'value' => 'nike*',
        ],
    ],
    ['name:*', ['default' => false, 'field' => 'name', 'type' => 'exists']],
]);

it('maps wildcard fields while preserving bare terms', function (): void {
    $result = SearchParser::query('name:jo* co*fee')
        ->allowedFields([AllowedField::string('name', 'full_name')])
        ->build();

    expect($result['left']['field'])->toBe('full_name')->and($result['right']['field'])->toBeNull();
});

it('expands mixed lists with the correct connective', function (
    string $operator,
    string $connective,
): void {
    $result = SearchParser::query('name:'.$operator.'jo*,"literal*",42')->allowAnyField()->build();
    $leafOperator = $operator === '' ? '=' : $operator;

    expect($result)->toBe([
        'left' => [
            'left' => [
                'field' => 'name',
                'operator' => $leafOperator,
                'segments' => ['jo', ''],
                'type' => 'wildcard',
            ],
            'operator' => $connective,
            'right' => [
                'default' => false,
                'field' => 'name',
                'match' => 'exact',
                'operator' => $leafOperator,
                'quoted' => true,
                'type' => 'comparison',
                'value' => 'literal*',
            ],
            'type' => 'binary',
        ],
        'operator' => $connective,
        'right' => [
            'default' => false,
            'field' => 'name',
            'match' => 'exact',
            'operator' => $leafOperator,
            'quoted' => false,
            'type' => 'comparison',
            'value' => 42,
        ],
        'type' => 'binary',
    ]);
})->with([['', 'OR'], ['!=', 'AND']]);

it('counts every expanded leaf but keeps literal lists as one clause', function (): void {
    expect(
        SearchParser::query('name:a,b,c')->allowAnyField()->maxClauses(1)->build()['type'],
    )->toBe(
        'in',
    )
        ->and(
            SearchParser::query('name:a,b*,c')->allowAnyField()->maxClauses(3)->build()['type'],
        )->toBe(
            'binary',
        )
        ->and(
            fn (): mixed
                => SearchParser::query('name:a,b*,c')->allowAnyField()->maxClauses(2)->build(),
        )
        ->toThrow(ParseException::class, 'maximum of 2 clauses');
});

it('rejects wildcard comparison and range patterns', function (string $query): void {
    SearchParser::query($query)->allowAnyField()->build();
})->throws(ParseException::class, 'Wildcard patterns are only supported')->with([
    'name:>jo*', 'name>=jo*', 'name:<"a b"*', 'name:<=*fee',
    'name:>a,jo*', 'name:>=jo*,a', 'name:[jo* TO z]', 'name:[a TO z*]',
]);

it('rejects an exact wildcard outside exists and open bounds', function (string $query): void {
    SearchParser::query($query)->allowAnyField()->build();
})->throws(ParseException::class)->with(['*', 'name:!=*', 'name!=*', 'name:>*', 'name:a,*']);

it('validates field patterns using the original pattern text', function (): void {
    expect(
        SearchParser::query('name:jo*')
            ->allowedFields([AllowedField::string('name')->max(3)])
            ->build()['segments'],
    )
        ->toBe(['jo', ''])
        ->and(
            fn (): mixed
                => SearchParser::query('name:joe*')
                    ->allowedFields([AllowedField::string('name')->max(3)])
                    ->build(),
        )
        ->toThrow(InvalidFieldValueException::class)
        ->and(
            fn (): mixed
                => SearchParser::query('age:2*')
                    ->allowedFields([AllowedField::integer('age')])
                    ->build(),
        )
        ->toThrow(InvalidFieldValueException::class);
});

it('validates mixed lists with array item and collection rules before expansion', function (): void {
    $field = AllowedField::array('name')
        ->size(2)
        ->each(
            fn (AllowedFieldItemRules $rules): AllowedFieldItemRules => $rules->string()->max(3),
        );

    expect(SearchParser::query('name:jo*,ann')->allowedFields([$field])->build()['operator'])->toBe(
        'OR',
    )
        ->and(
            fn (): mixed => SearchParser::query('name:jo*,anna')->allowedFields([$field])->build(),
        )
        ->toThrow(InvalidFieldValueException::class)
        ->and(
            fn (): mixed
                => SearchParser::query('name:jo*,ann,bob')->allowedFields([$field])->build(),
        )
        ->toThrow(InvalidFieldValueException::class);
});
