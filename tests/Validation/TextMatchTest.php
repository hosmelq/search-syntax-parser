<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\Adapter\ArrayAdapter;
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;
use HosmelQ\SearchSyntaxParser\Validation\TextMatch;

it('exposes effective text matches for comparisons and mixed quoted lists', function (
    string $mode,
    TextMatch $expected,
): void {
    $field = AllowedField::string('title')->$mode();
    $parser = SearchParser::query('title:mug,"cup",plate')->allowedFields([$field]);
    $list = $parser->parse();

    expect($field->getTextMatch($list, 0))->toBe($expected)
        ->and($field->getTextMatch($list, 1))->toBe(TextMatch::Exact)
        ->and($parser->build()['match'])->toBe([$expected->value, 'exact', $expected->value])
        ->and(SearchParser::query('title:mug')->allowedFields([$field])->build()['match'])->toBe(
            $expected->value,
        )
        ->and(SearchParser::query('title:"mug"')->allowedFields([$field])->build()['match'])->toBe(
            'exact',
        );
})->with([
    ['prefix', TextMatch::Prefix], ['contains', TextMatch::Contains], ['words', TextMatch::Words],
]);

it('uses only the last configured text match mode', function (): void {
    $field = AllowedField::string('title')->prefix()->contains()->words()->prefix();

    expect(SearchParser::query('title:mug')->allowedFields([$field])->build()['match'])->toBe(
        'prefix',
    );
});

it('preserves non-exact modes when serializing numeric-looking strings and keyword values', function (): void {
    $field = AllowedField::string('title')->prefix();
    $parser = SearchParser::query('title:123,true,AND,=5')->allowedFields([$field]);
    $text = $parser->build('string');

    expect($text)->toBe('title:123,true,AND,=5')
        ->and(SearchParser::query($text)->allowedFields([$field])->build())->toBe($parser->build());
});

it('allows text matching on arrays with shared string rules', function (): void {
    $field = AllowedField::array('tags')
        ->each(fn ($rules) => $rules->string())
        ->at(1, fn ($rules) => $rules->max(10))
        ->contains();

    expect(SearchParser::query('tags:mug,"cup"')->allowedFields([$field])->build()['match'])
        ->toBe(['contains', 'exact']);
});

it('rejects text modes on fields without a string cast type', function (
    AllowedField $field,
    string $mode,
): void {
    $field->$mode();
})->throws(InvalidArgumentException::class)->with([
    new AllowedField('x'), AllowedField::integer('x'), AllowedField::numeric('x'),
    AllowedField::decimal('x', 2), AllowedField::boolean('x'), AllowedField::date('x'),
    AllowedField::in('x', ['text']), AllowedField::uuid('x'), AllowedField::ulid('x'),
    AllowedField::array('x'),
    AllowedField::array('x')->at(0, fn ($rules) => $rules->string()),
    AllowedField::array('x')->each(fn ($rules) => $rules->integer()),
    AllowedField::array('x')
        ->each(fn ($rules) => $rules->string())
        ->at(0, fn ($rules) => $rules->integer()),
])->with(['prefix', 'contains', 'words']);

it('rejects incompatible item type changes after enabling a text mode', function (
    bool $positional,
): void {
    $field = AllowedField::array('x')->each(fn ($rules) => $rules->string())->prefix();

    if ($positional) {
        $field->at(0, fn ($rules) => $rules->integer());
    } else {
        $field->each(fn ($rules) => $rules->integer());
    }
})->throws(InvalidArgumentException::class)->with([false, true]);

it('marks every comparison and list with exact matching when no mode is configured', function (): void {
    $parser = SearchParser::query('x:1,2')->allowAnyField();

    expect($parser->build()['match'])->toBe(['exact', 'exact'])
        ->and(new ArrayAdapter()->build($parser->parse())['match'])->toBe(['exact', 'exact'])
        ->and(SearchParser::query('x:1')->allowAnyField()->build()['match'])->toBe('exact');
});

it('keeps default text values unquoted and explicit wildcard patterns independent', function (): void {
    $field = AllowedField::string('title')->words()->default('mug');
    $parser = SearchParser::query('title:mu*,"cup"')->allowedFields([$field]);

    expect(SearchParser::query('')->allowedFields([$field])->build()['match'])->toBe('words')
        ->and($parser->build()['left']['type'])->toBe('wildcard')
        ->and($parser->build()['left'])->not->toHaveKey('match')
        ->and($parser->build()['right']['match'])->toBe('exact');
});

it('returns the configured mode without a node', function (): void {
    expect(AllowedField::string('name')->getTextMatch())->toBe(TextMatch::Exact)
        ->and(AllowedField::string('name')->words()->getTextMatch())->toBe(TextMatch::Words);
});
