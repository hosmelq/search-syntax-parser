<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\SearchParser;

it('preserves quoted terms in array output and string round trips', function (
    string $query,
    string $value,
    bool $quoted,
): void {
    $parser = SearchParser::query($query);
    $node = $parser->parse();
    $text = $parser->build('string');
    $roundTrip = SearchParser::query($text)->parse();

    expect($node->getValue())->toBe($value)
        ->and($node->isQuoted())->toBe($quoted)
        ->and($parser->build())->toBe(['quoted' => $quoted, 'type' => 'term', 'value' => $value])
        ->and($roundTrip->getValue())->toBe($value)
        ->and($roundTrip->isQuoted())->toBe($quoted);
})->with([
    ['coffee', 'coffee', false], ['007', '007', false], ['123', '123', false],
    ['TRUE', 'TRUE', false], ['12.50', '12.50', false], ['"Cold Brew"', 'Cold Brew', true],
    ['"coffee"', 'coffee', true], ["'007'", '007', true], ['""', '', true],
    ['"co*fee"', 'co*fee', true], ['"a\\"b"', 'a"b', true],
]);
