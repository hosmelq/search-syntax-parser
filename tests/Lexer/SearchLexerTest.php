<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\SearchParser;

it('preserves complete words as terms', function (string $input): void {
    expect(SearchParser::query($input)->build())->toBe(['type' => 'term', 'value' => $input]);
})->with([
    'title', 'category_1', '1field', 'nested.path', 'niño', '東京', '🙂',
    't-shirt', 'ABC-123', 'john@example.com', 'price=10', 'hello!world', '!',
    "O'Brien", '007', '12.50', 'TRUE', '12*', '*fee', 'co*fee', 'TO',
]);

it('unescapes only supported characters in quoted values', function (
    string $input,
    string $expected,
): void {
    expect(SearchParser::query('title:'.$input)->parse()->getValue())->toBe($expected);
})->with([
    ['"hello\\"world"', 'hello"world'],
    ['"back\\\\slash"', 'back\slash'],
    ["'single\\'quote'", "single'quote"],
    ['"back\\nslash"', 'back\nslash'],
    ['"a\\\\\'b"', 'a\\\'b'],
    ['"Co"*', 'Co*'],
    ["'Co'*", 'Co*'],
    ['"*"', '*'],
    ['""', ''],
]);

it('recognizes case insensitive boolean operators', function (): void {
    $result = SearchParser::query('a AND b Or not c')->build();

    expect($result['operator'])->toBe('OR')
        ->and($result['left']['operator'])->toBe('AND')
        ->and($result['right']['operator'])->toBe('NOT');
});
