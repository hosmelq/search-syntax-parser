<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\Exception\InvalidFieldValueException;
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;

it('distinguishes open bounds from quoted literal asterisks', function (
    string $query,
    null|string $from,
    null|string $to,
): void {
    $parser = SearchParser::query($query)->allowAnyField();
    $range = $parser->parse();

    expect($range->getFrom())->toBe($from)
        ->and($range->getTo())->toBe($to)
        ->and($parser->build())->toBe([
            'field' => 'x',
            'from' => $from,
            'to' => $to,
            'type' => 'range',
        ])
        ->and(SearchParser::query($parser->build('string'))->allowAnyField()->build())->toBe(
            $parser->build(),
        );
})->with([
    ['x:[* TO *]', null, null],
    ['x:["*" TO *]', '*', null],
    ['x:[* TO "*"]', null, '*'],
    ['x:["*" TO "*"]', '*', '*'],
    ['x:["a*b" TO "c*d"]', 'a*b', 'c*d'],
]);

it('casts and validates quoted range bounds', function (): void {
    $range = SearchParser::query('x:["1" TO "2"]')
        ->allowedFields([AllowedField::integer('x')->between(1, 2)])
        ->parse();

    expect($range->getFrom())->toBe(1)->and($range->getTo())->toBe(2);

    SearchParser::query('x:[* TO "*"]')->allowedFields([AllowedField::integer('x')])->parse();
})->throws(InvalidFieldValueException::class);
