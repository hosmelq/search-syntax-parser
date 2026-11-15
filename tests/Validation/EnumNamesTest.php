<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Tests\TestSupport\AmbiguousEnumNames;
use HosmelQ\SearchSyntaxParser\Tests\TestSupport\AmbiguousEnumValues;
use HosmelQ\SearchSyntaxParser\Tests\TestSupport\OverlappingEnumTokens;
use HosmelQ\SearchSyntaxParser\Tests\TestSupport\PriorityLevel;
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;
use HosmelQ\SearchSyntaxParser\Validation\AllowedFieldItemRules;

it('resolves integer-backed enum names and values regardless of case or quotes', function (
    string $token,
    int $expected,
): void {
    $field = AllowedField::enum('priority', PriorityLevel::class);

    foreach ([$token, '"'.$token.'"'] as $value) {
        expect(
            SearchParser::query('priority:'.$value)->allowedFields([$field])->parse()->getValue(),
        )->toBe(
            $expected,
        );
    }
})->with([['1', 1], ['Low', 1], ['LOW', 1], ['low', 1], ['High', 2], ['high', 2]]);

it('prefers backing values over another case name while accepting both forms', function (): void {
    $field = AllowedField::enum('status', OverlappingEnumTokens::class);

    expect(
        SearchParser::query('status:ENABLED,Active,OPEN')
            ->allowedFields([$field])
            ->parse()
            ->getValues(),
    )
        ->toBe(['enabled', 'enabled', 'open'])
        ->and($field->tryCast('Enabled'))->toBe('enabled')
        ->and(
            SearchParser::query('')
                ->allowedFields([$field->default('ACTIVE')])
                ->parse()
                ->getValue(),
        )->toBe(
            'enabled',
        );
});

it('supports enum names in shared and positional rules and range bounds', function (): void {
    $field = AllowedField::array('priority')
        ->each(fn ($rules) => $rules->enum(PriorityLevel::class))
        ->at(1, fn ($rules) => $rules->enum(PriorityLevel::class));

    expect(
        SearchParser::query('priority:low,HIGH')->allowedFields([$field])->parse()->getValues(),
    )->toBe(
        [1, 2],
    )
        ->and(SearchParser::query('priority:[low TO HIGH]')->allowedFields([
            AllowedField::enum('priority', PriorityLevel::class),
        ])->build()['to'])->toBe(2);
});

it('rejects enums whose backing values or names collide ignoring case', function (
    string $enum,
): void {
    expect(fn (): AllowedField => AllowedField::enum('status', $enum))->toThrow(
        InvalidArgumentException::class,
    )
        ->and(fn (): AllowedFieldItemRules => new AllowedFieldItemRules()->enum($enum))->toThrow(
            InvalidArgumentException::class,
        );
})->with([AmbiguousEnumNames::class, AmbiguousEnumValues::class]);
