<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\AST\Node\NodeInterface;
use HosmelQ\SearchSyntaxParser\Exception\InvalidFieldValueException;
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;

it('keeps relative grammar out of custom date formats', function (): void {
    expect(AllowedField::date('due', 'Y-m-d')->validate('today'))->toBeFalse();
});

it('rejects dates that do not match the custom format exactly', function (string $value): void {
    $parser = SearchParser::query('date:"'.$value.'"')->allowedFields([
        AllowedField::date('date', 'd/m/Y'),
    ]);

    expect(fn (): mixed => $parser->build())->toThrow(InvalidFieldValueException::class);
})->with([['29/02/2025'], ['1/1/2025'], ['01/01/2025tail'], ["01/01/2025\x00"]]);

it('rejects times and instant anchors on calendar date fields', function (string $value): void {
    $field = AllowedField::date('due')->withoutTime();
    $partial = AllowedField::date('due')->allowPartialDates()->withoutTime();

    expect($field->validate($value))
        ->toBeFalse()
        ->and($partial->validate($value))
        ->toBeFalse()
        ->and(AllowedField::date('due')->validate($value))
        ->toBeTrue();
})->with([
    ['2024-03-15T10'],
    ['2024-03-15T10:30Z'],
    ['2024-03-15T00:00:00+02:00'],
    ['now'],
    ['NOW-1d'],
    ['-7d'],
    ['+3d'],
]);

it('requires an explicit opt-in for partial dates and preserves their text', function (
    string $value,
): void {
    foreach ([$value, '"'.$value.'"'] as $text) {
        expect(
            fn (): null|NodeInterface
                => SearchParser::query('created:'.$text)
                    ->allowedFields([AllowedField::date('created')])
                    ->parse(),
        )
            ->toThrow(InvalidFieldValueException::class)
            ->and(
                SearchParser::query('created:'.$text)
                    ->allowedFields([AllowedField::date('created')->allowPartialDates()])
                    ->parse()
                    ->getValue(),
            )
            ->toBe($value);
    }
})->with(['2024', '2024-03']);

it('validates relative values only when they resolve within the supported years', function (): void {
    $field = AllowedField::date('created');

    expect($field->validate('today-100y'))
        ->toBeTrue()
        ->and($field->validate('today+999999y'))
        ->toBeFalse()
        ->and($field->validate('-999999w'))
        ->toBeFalse();
});

it('accepts calendar dates and day anchors on calendar date fields', function (
    string $value,
): void {
    $field = AllowedField::date('due')->withoutTime();
    $partial = AllowedField::date('due')->allowPartialDates()->withoutTime();

    expect($field->validate($value))
        ->toBeTrue()
        ->and($partial->validate($value))
        ->toBeTrue()
        ->and(AllowedField::date('due')->validate($value))
        ->toBeTrue();
})->with([['2024-03-15'], ['today'], ['YESTERDAY'], ['tomorrow+1w'], ['today-7d'], ['today+1M']]);

it('accepts dates matching the custom format exactly', function (string $value): void {
    $parser = SearchParser::query('date:"'.$value.'"')->allowedFields([
        AllowedField::date('date', 'd/m/Y'),
    ]);

    expect($parser->build()['value'])->toBe($value);
})->with([['29/02/2024'], ['01/01/2025']]);
