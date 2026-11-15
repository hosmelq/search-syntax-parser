<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\Exception\InvalidFieldValueException;
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;

it('rejects malformed ULIDs', function (string $value): void {
    SearchParser::query('id:"'.$value.'"')->allowedFields([AllowedField::ulid('id')])->parse();
})->throws(InvalidFieldValueException::class)->with([
    ['8ZZZZZZZZZZZZZZZZZZZZZZZZZ'],
    ['01ARZ3NDEKTSV4RRFFQ69G5FAI'],
    ['01ARZ3NDEKTSV4RRFFQ69G5FAL'],
    ['01ARZ3NDEKTSV4RRFFQ69G5FAO'],
    ['01ARZ3NDEKTSV4RRFFQ69G5FAU'],
    ['01ARZ3NDEKTSV4RRFFQ69G5FA'],
    ['01ARZ3NDEKTSV4RRFFQ69G5FAV0'],
    ['000000000000000000000000ß'],
    ["01ARZ3NDEKTSV4RRFFQ69G5FAV\n"],
]);
