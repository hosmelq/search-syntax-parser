<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\Exception\ErrorReason;
use HosmelQ\SearchSyntaxParser\Exception\InvalidFieldOperatorException;
use HosmelQ\SearchSyntaxParser\Exception\ParseException;
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;

it('rejects leading field wildcards with their external field and character position', function (
    string $query,
    int $position,
    bool $configured,
): void {
    $parser = SearchParser::query($query);

    $configured
        ? $parser->allowedFields([AllowedField::string('name', 'full_name')])
        : $parser->allowAnyField();

    try {
        $parser->parse();

        test()->fail('Expected a leading wildcard error.');
    } catch (InvalidFieldOperatorException $invalidFieldOperatorException) {
        expect($invalidFieldOperatorException->getField())->toBe('name')
            ->and($invalidFieldOperatorException->getReason())->toBe(
                ErrorReason::LeadingWildcardNotAllowed,
            )
            ->and($invalidFieldOperatorException->getParameters())->toBe(['field' => 'name'])
            ->and($invalidFieldOperatorException->getPosition())->toBe($position);
    }
})->with([
    ['name:*son', 5, true],
    ['name:*son*', 5, true],
    ['é name:a,*b', 9, true],
    ['name:!=*son', 7, false],
    ['name:a,*b', 7, false],
    ['name:""*', 5, true],
]);

it('rejects leading free text wildcards with a parse reason and position', function (): void {
    try {
        SearchParser::query('é *son')->parse();

        test()->fail('Expected a leading wildcard error.');
    } catch (ParseException $parseException) {
        expect($parseException->getReason())->toBe(ErrorReason::LeadingWildcardNotAllowed)
            ->and($parseException->getParameters())->toBeEmpty()
            ->and($parseException->getPosition())->toBe(2);
    }
});

it('scopes leading wildcard permission to the field or unconfigured input', function (): void {
    expect(SearchParser::query('name:*son')->allowedFields([
        AllowedField::string('name')->allowLeadingWildcards(),
    ])->parse()->getSegments())->toBe(['', 'son'])
        ->and(
            SearchParser::query('*son name:*son')
                ->allowAnyField()
                ->allowLeadingWildcards()
                ->build()['operator'],
        )->toBe(
            'AND',
        );

    SearchParser::query('name:*son')->allowLeadingWildcards()->allowedFields([
        AllowedField::string('name'),
    ])->parse();
})->throws(InvalidFieldOperatorException::class);

it('does not allow leading free text patterns through a field opt in', function (): void {
    SearchParser::query('*son')->allowedFields([
        AllowedField::string('name')->allowLeadingWildcards(),
    ])->parse();
})->throws(ParseException::class);

it('counts literal Unicode characters across all wildcard segments', function (
    string $query,
    int $minimum,
    bool $valid,
    int $position,
): void {
    $parser = SearchParser::query($query)->allowedFields([
        AllowedField::string('name')->allowLeadingWildcards()->minWildcardLength($minimum),
    ]);

    if ($valid) {
        expect($parser->parse())->not->toBeNull();

        return;
    }

    try {
        $parser->parse();

        test()->fail('Expected a wildcard length error.');
    } catch (InvalidFieldOperatorException $invalidFieldOperatorException) {
        expect($invalidFieldOperatorException->getField())->toBe('name')
            ->and($invalidFieldOperatorException->getReason())->toBe(ErrorReason::WildcardTooShort)
            ->and($invalidFieldOperatorException->getParameters())->toBe([
                'field' => 'name',
                'minimum' => $minimum,
            ])
            ->and($invalidFieldOperatorException->getPosition())->toBe($position);
    }
})->with([
    ['name:café*', 4, true, 5],
    ['name:café*', 5, false, 5],
    ['name:a**b', 2, true, 5],
    ['name:a**b', 3, false, 5],
    ['name:valid,é*', 2, false, 11],
    ['name:*son*', 3, true, 5],
    ['name:***', 1, false, 5],
    ['name:"a*b"*', 3, true, 5],
]);

it('checks every pattern in a list before running aliases or validators', function (): void {
    $field = AllowedField::string('name')->alias(
        'first',
        static fn (): never => throw new LogicException('Aliases must not run.'),
    );

    SearchParser::query('name:first,a*')->allowedFields([$field->minWildcardLength(2)])->parse();
})->throws(InvalidFieldOperatorException::class);

it('keeps ordinary text trailing and infix patterns quoted asterisks and exists unaffected', function (
    string $query,
): void {
    expect(SearchParser::query($query)->allowedFields([
        AllowedField::string('name')->minWildcardLength(3),
    ])->parse())->not->toBeNull();
})->with(['name:son*', 'name:s*on', 'name:"*son"', 'name:"*"', 'name:*', 'name:x']);

it('retains withoutWildcards when leading patterns are enabled', function (): void {
    SearchParser::query('name:*son')->allowedFields([
        AllowedField::string('name')->allowLeadingWildcards()->withoutWildcards(),
    ])->parse();
})->throws(InvalidFieldOperatorException::class, 'Wildcards are not allowed');

it('rejects a negative minimum wildcard length', function (): void {
    AllowedField::string('name')->minWildcardLength(-1);
})->throws(InvalidArgumentException::class);

it('accepts only word prefixes as patterns on whole-word fields', function (): void {
    $body = AllowedField::string('body')->words();

    expect(SearchParser::query('body:coff*')->allowedFields([$body])->parse()->getSegments())
        ->toBe(['coff', ''])
        ->and(
            SearchParser::query('body:"coffee ma"*')
                ->allowedFields([$body])
                ->parse()
                ->getSegments(),
        )
        ->toBe(['coffee ma', ''])
        ->and(SearchParser::query('body:coff**')->allowedFields([$body])->parse()->getSegments())
        ->toBe(['coff', ''])
        ->and(
            SearchParser::query('body:co*ee')
                ->allowedFields([AllowedField::string('body')])
                ->parse()
                ->getSegments(),
        )
        ->toBe(['co', 'ee']);
});

it('rejects other patterns on whole-word fields at the pattern', function (
    string $query,
    int $position,
): void {
    try {
        SearchParser::query($query)
            ->allowedFields([AllowedField::string('body')->words()])
            ->parse();

        test()->fail('Expected a whole-word wildcard error.');
    } catch (InvalidFieldOperatorException $invalidFieldOperatorException) {
        expect($invalidFieldOperatorException->getReason())->toBe(
            ErrorReason::WordWildcardNotAllowed,
        )
            ->and($invalidFieldOperatorException->getField())->toBe('body')
            ->and($invalidFieldOperatorException->getPosition())->toBe($position);
    }
})->with([
    ['body:co*ee', 5],
    ['body:*offee', 5],
    ['body:co**ee*', 5],
    ['body:tea,co*ee', 9],
    ['body:!=co*ee', 7],
]);

it('rejects leading wildcards on whole-word fields in either configuration order', function (
    Closure $field,
): void {
    SearchParser::query('')->allowedFields([$field()]);
})->throws(
    InvalidArgumentException::class,
    "Whole-word field 'body' cannot allow leading wildcards.",
)->with(
    [
        fn (): AllowedField => AllowedField::string('body')->words()->allowLeadingWildcards(),
        fn (): AllowedField => AllowedField::string('body')->allowLeadingWildcards()->words(),
    ],
);
