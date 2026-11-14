<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\Exception\InvalidFieldException;
use HosmelQ\SearchSyntaxParser\Exception\InvalidFieldValueException;
use HosmelQ\SearchSyntaxParser\Exception\ParseException;
use HosmelQ\SearchSyntaxParser\Exception\SearchSyntaxException;
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;

it('reports character positions in the exact original input', function (
    string $query,
    int $position,
): void {
    foreach ([
        SearchParser::query($query)->allowAnyField(),
        new SearchParser($query)->allowAnyField(),
    ] as $parser) {
        try {
            $parser->build();

            test()->fail('Expected a parse error.');
        } catch (ParseException $exception) {
            expect($exception)->toBeInstanceOf(SearchSyntaxException::class)
                ->and($exception->getPosition())->toBe($position);
        }
    }
})->with([
    ['  café )', 7],
    ['🙂 AND', 5],
    ['  name:', 7],
    ['  name:>', 8],
    ['é name:"unfinished', 7],
    ["é name:'unfinished", 7],
    ['é name:[1 to 3]', 10],
    ['é name:[1 TO 3', 14],
    ['é name:[1 TO ]', 13],
    ['é name:>*', 8],
    ['é name:>jo*', 8],
    ['é name:[jo* TO z]', 8],
    ['é name:[a TO z*]', 13],
    ['é name:a,*', 9],
    ['  OR', 2],
]);

it('reports the token that exceeds query limits', function (
    string $query,
    string $limit,
    int $position,
): void {
    $parser = SearchParser::query($query)->allowAnyField();

    match ($limit) {
        'length' => $parser->maxLength(3),
        'depth' => $parser->maxDepth(1),
        'clauses' => $parser->maxClauses(2),
    };

    try {
        $parser->build();

        test()->fail('Expected a query limit error.');
    } catch (ParseException $parseException) {
        expect($parseException->getPosition())->toBe($position);
    }
})->with([
    ['  éé', 'length', 3],
    ['é (NOT a)', 'depth', 3],
    ['é (-a)', 'depth', 3],
    ['é ((a))', 'depth', 3],
    ['é a name:b', 'clauses', 4],
    ['name:a,b*,c', 'clauses', 10],
    ['é name:a,b*', 'clauses', 9],
]);

it('exposes the external field and its character position', function (): void {
    try {
        SearchParser::query('  é name:x')->allowedFields(['age'])->build();

        test()->fail('Expected a field error.');
    } catch (InvalidFieldException $invalidFieldException) {
        expect($invalidFieldException)->toBeInstanceOf(SearchSyntaxException::class)
            ->not->toBeInstanceOf(InvalidArgumentException::class)
            ->and($invalidFieldException->getField())->toBe('name')
            ->and($invalidFieldException->getPosition())->toBe(4);
    }
});

it('positions field validation errors at the start of the value', function (
    string $query,
    int $position,
): void {
    try {
        SearchParser::query($query)
            ->allowedFields([AllowedField::integer('age', 'user_age')])
            ->build();

        test()->fail('Expected a value error.');
    } catch (InvalidFieldValueException $invalidFieldValueException) {
        expect($invalidFieldValueException)->toBeInstanceOf(SearchSyntaxException::class)
            ->not->toBeInstanceOf(InvalidArgumentException::class)
            ->and($invalidFieldValueException->getField())->toBe('age')
            ->and($invalidFieldValueException->getPosition())->toBe($position);
    }
})->with([
    ['  é age:bad', 8],
    ['  é age:1,bad', 8],
    ['  é age:[1 TO bad]', 8],
    ['  é age:2*', 8],
    ['  é age:-bad', 8],
]);

it('leaves positions unset when no location applies', function (string $query): void {
    try {
        SearchParser::query($query)->build();

        test()->fail('Expected an error.');
    } catch (SearchSyntaxException $searchSyntaxException) {
        expect($searchSyntaxException->getPosition())->toBeNull();
    }
})->with(['', '   ', "\xff", "é \xff"]);

it('leaves PCRE failure positions unset', function (): void {
    $limit = ini_get('pcre.backtrack_limit');

    ini_set('pcre.backtrack_limit', '1');

    try {
        SearchParser::query('name:Coffee')->allowAnyField()->build();

        test()->fail('Expected a tokenizer error.');
    } catch (ParseException $parseException) {
        expect($parseException->getPosition())->toBeNull();
    } finally {
        ini_set('pcre.backtrack_limit', (string) $limit);
    }
});

it('treats lowercase and mixed-case connectives as ordinary terms', function (
    string $query,
    string $normalized,
): void {
    expect(SearchParser::query($query)->allowAnyField()->build('string'))->toBe($normalized);
})->with([
    ['rock and roll', 'rock AND and AND roll'],
    ['this is not good', 'this AND is AND not AND good'],
    ['a Or b', 'a AND Or AND b'],
    ['and OR not', 'and OR not'],
    ['name:AND', 'name:"AND"'],
]);
