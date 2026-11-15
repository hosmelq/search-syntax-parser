<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\Adapter\StringAdapter;
use HosmelQ\SearchSyntaxParser\AST\Visitor\NegationNormalFormTransformer;
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;

it('preserves field value quotes through parsing and string serialization', function (
    string $query,
    bool $quoted,
    string $text,
): void {
    $parser = SearchParser::query($query)->allowedFields([AllowedField::string('title')]);

    expect($parser->parse()->isQuoted())->toBe($quoted)
        ->and($parser->build()['quoted'])->toBe($quoted)
        ->and($parser->build('string'))->toBe($text);
})->with([
    ['title:mug', false, 'title:mug'],
    ['title:"mug"', true, 'title:"mug"'],
    ["title:'mug'", true, 'title:"mug"'],
    ['title>"mug"', true, 'title:>"mug"'],
]);

it('retains per-item quotes while renumbering a list after ignored values', function (): void {
    $parser = SearchParser::query('title:all,mug,"cup",ALL,plate')->allowedFields([
        AllowedField::string('title')->ignore('all'),
    ]);
    $node = $parser->parse();

    expect($node->getValues())->toBe(['mug', 'cup', 'plate'])
        ->and([$node->isQuoted(0), $node->isQuoted(1), $node->isQuoted(2)])->toBe([
            false,
            true,
            false,
        ])
        ->and($parser->build()['quoted'])->toBe([false, true, false])
        ->and($parser->build('string'))->toBe('title:mug,"cup",plate');
});

it('retains literal quotes in an expanded wildcard list', function (): void {
    $parser = SearchParser::query('title:all,mu*,"cup",plate')->allowedFields([
        AllowedField::string('title')->ignore('all'),
    ]);
    $node = $parser->parse();

    expect($node->getLeft()->getRight()->isQuoted())->toBeTrue()
        ->and($node->getRight()->isQuoted())->toBeFalse()
        ->and($parser->build('string'))->toBe('title:mu* OR title:"cup" OR title:plate');
});

it('keeps defaults unquoted even when their text requires quotes in query syntax', function (): void {
    $scalar = SearchParser::query('')->allowedFields([
        AllowedField::string('title')->default('a mug'),
    ])->parse();
    $list = SearchParser::query('')->allowedFields([
        AllowedField::string('title')->default(['a mug', 'cup']),
    ])->parse();

    expect($scalar->isQuoted())->toBeFalse()
        ->and($list->isQuoted(0))->toBeFalse()->and($list->isQuoted(1))->toBeFalse();
});

it('preserves quotes on cast scalar values', function (
    AllowedField $field,
    string $query,
    string $expected,
): void {
    $parser = SearchParser::query($query)->allowedFields([$field]);

    expect($parser->build('string'))->toBe($expected)
        ->and(SearchParser::query($expected)->allowedFields([$field])->build())->toBe(
            $parser->build(),
        );
})->with([
    [AllowedField::integer('x'), 'x:"2"', 'x:"2"'],
    [AllowedField::numeric('x'), 'x:"1.50"', 'x:"1.5"'],
    [AllowedField::boolean('x'), 'x:"TRUE"', 'x:"true"'],
]);

it('preserves comparison and per-item quoted flags through negation normalization', function (
    string $query,
    string $expected,
): void {
    $node = SearchParser::query($query)->allowAnyField()->parse();
    $normalized = new NegationNormalFormTransformer()->transform($node);

    expect(new StringAdapter()->build($normalized))->toBe($expected);
})->with([
    ['title:!="mug"', 'NOT title:"mug"'],
    ['title:!=mug,"cup"', 'NOT title:mug,"cup"'],
]);
