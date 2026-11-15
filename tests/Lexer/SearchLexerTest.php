<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\Lexer\SearchLexer;
use HosmelQ\SearchSyntaxParser\SearchParser;

it('preserves complete words as terms', function (string $input): void {
    expect(SearchParser::query($input)->build())->toBe([
        'quoted' => false,
        'type' => 'term',
        'value' => $input,
    ]);
})->with([
    'title', 'category_1', '1field', 'nested.path', 'niño', '東京', '🙂',
    't-shirt', 'ABC-123', 'john@example.com', 'price=10', 'hello!world', '!',
    "O'Brien", '007', '12.50', 'TRUE', 'TO',
]);

it('unescapes only supported characters in quoted values', function (
    string $input,
    string $expected,
): void {
    expect(SearchParser::query('title:'.$input)->allowAnyField()->parse()->getValue())->toBe(
        $expected,
    );
})->with([
    ['"hello\\"world"', 'hello"world'],
    ['"back\\\\slash"', 'back\slash'],
    ["'single\\'quote'", "single'quote"],
    ['"back\\nslash"', 'back\nslash'],
    ['"a\\\\\'b"', 'a\\\'b'],
    ['"*"', '*'],
    ['""', ''],
]);

it('recognizes uppercase boolean operators', function (): void {
    $result = SearchParser::query('a AND b OR NOT c')->build();

    expect($result['operator'])->toBe('OR')
        ->and($result['left']['operator'])->toBe('AND')
        ->and($result['right']['operator'])->toBe('NOT');
});

it('keeps compact range bounds together including datetime colons and Unicode positions', function (
    string $value,
    array $tokens,
): void {
    $input = 'é x:'.$value;
    $result = new SearchLexer()->tokenize($input);

    expect($result->values)->toBe(['é', 'x', ':', ...$tokens])
        ->and($result->offsets[3])->toBe(4)
        ->and($result->ends[array_key_last($result->ends)])->toBe(mb_strlen($input));
})->with([
    ['1.25..2.50', ['1.25..2.50']],
    ['-5..5', ['-', '5..5']],
    ['-2.5..-0.5', ['-', '2.5..-0.5']],
    ['*..20', ['*..20']],
    ['10..*', ['10..*']],
    ['*..*', ['*..*']],
    ['2024-01-01..2024-03-31', ['2024-01-01..2024-03-31']],
    ['2024-01-01T10:00..2024-01-01T12:00', ['2024-01-01T10:00..2024-01-01T12:00']],
    ['*..2024-01-01T12:00', ['*..2024-01-01T12:00']],
    ['2024-01-01T10:00..*', ['2024-01-01T10:00..*']],
    ['-30d..2024-01-01T12:00', ['-', '30d..2024-01-01T12:00']],
    ['2024..2024-06', ['2024..2024-06']],
    ['../x', ['../x']], ['...', ['...']],
]);
