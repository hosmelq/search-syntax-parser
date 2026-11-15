<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\Exception\InvalidFieldValueException;
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\Field;

it('rejects malformed UUIDs', function (string $value): void {
    SearchParser::query('id:"'.$value.'"')->allowedFields([Field::uuid('id')])->parse();
})->throws(InvalidFieldValueException::class)->with([
    ['550e8400e29b41d4a716446655440000'],
    ['550e8400-e29b-41d4-a716-44665544000g'],
    ['550e8400-e29b-41d4-a716-4466554400000'],
    ['{550e8400-e29b-41d4-a716-446655440000}'],
    ["550e8400-e29b-41d4-a716-446655440000\n"],
]);
