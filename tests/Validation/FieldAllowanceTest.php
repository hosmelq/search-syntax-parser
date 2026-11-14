<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\Exception\ErrorReason;
use HosmelQ\SearchSyntaxParser\Exception\InvalidFieldException;
use HosmelQ\SearchSyntaxParser\Exception\InvalidFieldOperatorException;
use HosmelQ\SearchSyntaxParser\Exception\InvalidFieldValueException;
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;

it('rejects field queries by default with an empty allowlist and a character position', function (
    bool $configureEmptyList,
): void {
    $parser = SearchParser::query('  é title:x');

    if ($configureEmptyList) {
        $parser->allowedFields([]);
    }

    expect($parser->isFieldAllowed('title'))->toBeFalse();

    try {
        $parser->parse();

        test()->fail('Expected a field error.');
    } catch (InvalidFieldException $invalidFieldException) {
        expect($invalidFieldException)->getMessage()->toBe(
            "Field 'title' is not allowed. No fields are allowed.",
        )
            ->and($invalidFieldException->getField())->toBe('title')
            ->and($invalidFieldException->getReason())->toBe(ErrorReason::FieldNotAllowed)
            ->and($invalidFieldException->getParameters())->toBe([
                'field' => 'title',
                'allowed' => [],
            ])
            ->and($invalidFieldException->getPosition())->toBe(4);
    }
})->with([false, true]);

it('preserves the rejection message when allowed fields are configured', function (): void {
    SearchParser::query('title:x')->allowedFields(['age', 'status'])->parse();
})->throws(
    InvalidFieldException::class,
    "Field 'title' is not allowed. Allowed fields are: age, status.",
);

it('accepts bare terms without enabling fields', function (string $query, string $expected): void {
    expect(SearchParser::query($query)->build('string'))->toBe($expected);
})->with([
    ['coffee', 'coffee'],
    ['title', 'title'],
    ['NOT (coffee OR tea)', 'NOT (coffee OR tea)'],
    ['co*fee', 'co*fee'],
]);

it('explicitly accepts any field using normal value inference', function (): void {
    $parser = SearchParser::query('age:25 enabled:true')->allowAnyField();

    expect($parser->isFieldAllowed('anything'))->toBeTrue()
        ->and($parser->getAllowedFieldNames())->toBeEmpty()
        ->and($parser->getAllowedField('anything'))->toBeNull()
        ->and($parser->build()['left']['value'])->toBe(25)
        ->and($parser->build()['right']['value'])->toBeTrue();
});

it('keeps configured casting aliases mappings and defaults while allowing other fields', function (
    bool $enableFirst,
): void {
    $parser = SearchParser::query('age:@me rating:4.5');

    if ($enableFirst) {
        $parser->allowAnyField();
    }

    $parser->allowedFields([
        AllowedField::integer('age', 'stored_age')->alias('@me', 25)->min(18),
        AllowedField::string('status')->default('active'),
    ]);

    if (! $enableFirst) {
        $parser->allowAnyField();
    }

    $result = $parser->build();

    expect($parser->isFieldAllowed('age'))->toBeTrue()
        ->and($parser->isFieldAllowed('rating'))->toBeTrue()
        ->and($parser->getAllowedFieldNames())->toBe(['age', 'status'])
        ->and($result['left']['left']['field'])->toBe('stored_age')
        ->and($result['left']['left']['value'])->toBe(25)
        ->and($result['left']['right']['value'])->toBe(4.5)
        ->and($result['right']['value'])->toBe('active');
})->with([false, true]);

it('still enforces configured value validation when any field is allowed', function (): void {
    SearchParser::query('age:old')->allowAnyField()->allowedFields([
        AllowedField::integer('age'),
    ])->parse();
})->throws(InvalidFieldValueException::class);

it('still enforces configured operator rules when any field is allowed', function (): void {
    SearchParser::query('age:>10')->allowAnyField()->allowedFields([
        AllowedField::integer('age')->operators('='),
    ])->parse();
})->throws(InvalidFieldOperatorException::class);
