<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\AST\Node\ComparisonNode;
use HosmelQ\SearchSyntaxParser\AST\Node\RangeNode;
use HosmelQ\SearchSyntaxParser\Exception\ErrorReason;
use HosmelQ\SearchSyntaxParser\Exception\InvalidFieldOperatorException;
use HosmelQ\SearchSyntaxParser\Exception\InvalidFieldValueException;
use HosmelQ\SearchSyntaxParser\Exception\ParseException;
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;

it('parses compact bounds like bracket ranges and serializes them with brackets', function (
    string $bounds,
    mixed $from,
    mixed $to,
): void {
    $parser = SearchParser::query('x:'.$bounds)->allowAnyField();
    $node = $parser->parse();

    [$left, $right] = explode('..', $bounds, 2);

    $bracket = SearchParser::query('x:['.$left.' TO '.$right.']')->allowAnyField();

    expect($node)->toBeInstanceOf(RangeNode::class)
        ->and($node->getFrom())->toBe($from)
        ->and($node->getTo())->toBe($to)
        ->and($parser->build())->toBe($bracket->build())
        ->and($parser->build('string'))->toBe($bracket->build('string'))
        ->and($node->getSpan()->offset)->toBe(0)
        ->and($node->getSpan()->length)->toBe(mb_strlen('x:'.$bounds));
})->with([
    ['1..5', 1, 5],
    ['1.5..2.75', 1.5, 2.75],
    ['-5..5', -5, 5],
    ['-5..-1', -5, -1],
    ['-2.5..-0.5', -2.5, -0.5],
    ['10..*', 10, null],
    ['*..20', null, 20],
    ['*..*', null, null],
    ['2024-01-01..2024-03-31', '2024-01-01', '2024-03-31'],
    ['2024-01-01T10:00..2024-01-01T12:00', '2024-01-01T10:00', '2024-01-01T12:00'],
    [
        '2024-01-01T10:30:45.123+05:30..2024-01-01T12:30:45.999Z',
        '2024-01-01T10:30:45.123+05:30',
        '2024-01-01T12:30:45.999Z',
    ],
    ['*..2024-01-01T12:00', null, '2024-01-01T12:00'],
    ['2024-01-01T10:00..*', '2024-01-01T10:00', null],
    ['2024..2024-06', 2_024, '2024-06'],
    ['-30d..now', '-30d', 'now'],
    ['-30d..2024-01-01T12:00', '-30d', '2024-01-01T12:00'],
]);

it('uses field rules for decimals partial dates relative dates and aliases', function (): void {
    expect(SearchParser::query('price:1.50..2.00')->allowedFields([
        AllowedField::decimal('price', 2)->scale(100),
    ])->build())->toBe(['field' => 'price', 'from' => 150, 'to' => 200, 'type' => 'range'])
        ->and(SearchParser::query('created:2024..2024-06')->allowedFields([
            AllowedField::date('created')->allowPartialDates(),
        ])->parse()->getFrom())->toBe('2024')
        ->and(SearchParser::query('created:-30d..now')->allowedFields([
            AllowedField::date('created'),
        ])->parse()->getTo())->toBe('now')
        ->and(SearchParser::query('price:low..high')->allowedFields([
            AllowedField::integer('price')->alias('low', 1)->alias('high', 5),
        ])->parse()->getTo())->toBe(5);
});

it('preserves literal text when either side is absent or the value is quoted', function (
    string $query,
    string $value,
): void {
    expect(SearchParser::query($query)->allowAnyField()->parse()->getValue())->toBe($value);
})->with([
    ['path:../x', '../x'], ['title:...', '...'], ['x:1..', '1..'], ['x:..2', '..2'],
    ['x:"1..2"', '1..2'], ['"1..2"', '1..2'], ['1..2', '1..2'],
]);

it('handles ignored compact bounds exactly like ignored bracket bounds', function (
    string $query,
    mixed $from,
    mixed $to,
    bool $removed,
): void {
    $node = SearchParser::query($query)
        ->allowedFields([AllowedField::integer('x')->ignore(0, 10)])
        ->parse();

    if ($removed) {
        expect($node)->toBeNull();

        return;
    }

    expect($node->getFrom())->toBe($from)->and($node->getTo())->toBe($to);
})->with([
    ['x:0..5', null, 5, false], ['x:5..10', 5, null, false], ['x:0..10', null, null, true],
    ['x:*..10', null, null, false],
]);

it('rejects compact ranges before resolving aliases when ranges are disabled', function (): void {
    try {
        SearchParser::query('é x:first..5')->allowedFields([
            AllowedField::integer('x')
                ->withoutRanges()
                ->alias(
                    'first',
                    static fn (): never => throw new LogicException('Aliases must not run.'),
                ),
        ])->parse();

        test()->fail('Expected a range restriction error.');
    } catch (InvalidFieldOperatorException $invalidFieldOperatorException) {
        expect($invalidFieldOperatorException->getField())->toBe('x')
            ->and($invalidFieldOperatorException->getReason())->toBe(
                ErrorReason::OperatorNotAllowed,
            )
            ->and($invalidFieldOperatorException->getParameters())->toBe([
                'field' => 'x',
                'clause' => 'ranges',
            ])
            ->and($invalidFieldOperatorException->getPosition())->toBe(4);
    }
});

it('validates both compact bounds and reports their range start', function (string $query): void {
    try {
        SearchParser::query($query)
            ->allowedFields([AllowedField::integer('x')->between(1, 5)])
            ->parse();

        test()->fail('Expected an invalid bound error.');
    } catch (InvalidFieldValueException $invalidFieldValueException) {
        expect($invalidFieldValueException->getReason())->toBe(ErrorReason::InvalidFieldValue)
            ->and($invalidFieldValueException->getField())->toBe('x')
            ->and($invalidFieldValueException->getPosition())->toBe(4);
    }
})->with(['é x:0..5', 'é x:1..6', 'é x:*..bad']);

it('rejects list and comparison combinations like the bracket notation', function (
    string $compact,
    string $bracket,
    ErrorReason $reason,
): void {
    foreach ([$compact, $bracket] as $query) {
        try {
            SearchParser::query($query)->allowAnyField()->parse();

            test()->fail('Expected invalid range syntax.');
        } catch (ParseException $exception) {
            expect($exception->getReason())->toBe($reason);
        }
    }
})->with([
    ['x:1..2,3', 'x:[1 TO 2],3', ErrorReason::UnexpectedToken],
    ['x:3,1..2', 'x:3,[1 TO 2]', ErrorReason::ExpectedValue],
    ['x:>1..2', 'x:>[1 TO 2]', ErrorReason::ExpectedValue],
    ['x:!=*..2', 'x:!=[* TO 2]', ErrorReason::ExpectedValue],
    ['x>=1..2', 'x>=[1 TO 2]', ErrorReason::ExpectedValue],
    ['x:3,*..2', 'x:3,[* TO 2]', ErrorReason::ExpectedValue],
]);

it('rejects wildcard patterns in compact bounds', function (string $query, int $position): void {
    try {
        SearchParser::query($query)->allowAnyField()->parse();

        test()->fail('Expected an invalid wildcard bound.');
    } catch (ParseException $parseException) {
        expect($parseException->getReason())->toBe(ErrorReason::InvalidWildcardPattern)
            ->and($parseException->getPosition())->toBe($position);
    }
})->with([['x:a*..z', 2], ['x:a..*z', 5]]);

it('keeps ellipses, repeated dots and paths as literal text', function (
    string $query,
    string $value,
): void {
    $node = SearchParser::query($query)->allowAnyField()->parse();

    expect($node)->toBeInstanceOf(ComparisonNode::class)->and($node->getValue())->toBe($value);
})->with([
    'trailing ellipsis' => ['title:wait...', 'wait...'],
    'inner ellipsis' => ['title:a...b', 'a...b'],
    'decimal ellipsis' => ['v:1...5', '1...5'],
    'two separators' => ['x:a..b..c', 'a..b..c'],
    'relative path' => ['path:src/../lib', 'src/../lib'],
    'dot path' => ['path:./../x', './../x'],
]);
