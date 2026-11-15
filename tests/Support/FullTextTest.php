<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\Exception\ParseException;
use HosmelQ\SearchSyntaxParser\Support\FullText;

it('rejects invalid UTF-8', function (): void {
    expect(fn (): FullText => new FullText("\xff"))->toThrow(ParseException::class, 'valid UTF-8');
});

it('extracts Unicode words without accepting backend query operators', function (
    string $value,
    array $words,
): void {
    expect(new FullText($value)->words)->toBe($words);
})->with([
    ['coffee+ceramic | tea & mug', ['coffee', 'ceramic', 'tea', 'mug']],
    ['%_!"coffee"~espresso*', ['coffee', 'espresso']],
    ['  Café 日本語 123 ', ['Café', '日本語', '123']],
    ["cafe\u{0301}", ["cafe\u{0301}"]],
    ['!!!', []],
    ['', []],
]);
