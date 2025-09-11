<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\Exception\InvalidFieldException;
use HosmelQ\SearchSyntaxParser\Exception\InvalidFieldValueException;
use HosmelQ\SearchSyntaxParser\Exception\ParseException;
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;

it('throws on empty query', function (): void {
    SearchParser::query('')->build();
})->throws(ParseException::class, 'Empty search query.');

it('throws on unexpected token after complete parse', function (): void {
    SearchParser::query('title:Coffee )')->build();
})->throws(ParseException::class, 'Unexpected token after query: ).');

it('throws on expected token mismatch', function (): void {
    SearchParser::query('price:[10 50]')->build();
})->throws(ParseException::class, 'Unexpected token, expected To but got Number.');

it('throws on invalid wildcard usage', function (): void {
    SearchParser::query('price>*')->build();
})->throws(ParseException::class, "Wildcard is only supported with ':' for exists queries; use NOT price:* for negation.");

it('throws on invalid wildcard usage with colon and operator', function (): void {
    SearchParser::query('price:>*')->build();
})->throws(ParseException::class, "Wildcard is only supported with ':' for exists queries; use NOT price:* for negation.");

it('throws on unexpected end of input', function (): void {
    SearchParser::query('title:Coffee AND')->build();
})->throws(ParseException::class, 'Unexpected end of input.');

it('throws on missing value', function (string $input): void {
    SearchParser::query($input)->build();
})->throws(ParseException::class, 'Expected value.')->with([
    'colon only' => 'price:',
    'operator only' => 'price:>',
]);

it('throws when field is not allowed', function (): void {
    $parser = SearchParser::query('name:john')->allowedFields(['age']);

    $parser->build();
})->throws(InvalidFieldException::class, 'not allowed.');

it('throws when field value fails validation', function (): void {
    $parser = SearchParser::query('age:john')->allowedFields([
        AllowedField::integer('age'),
    ]);

    $parser->build();
})->throws(InvalidFieldValueException::class, "Invalid value for field 'age'.");

it('throws when field value fails validation with operator (no colon)', function (): void {
    $parser = SearchParser::query('age>john')->allowedFields([
        AllowedField::integer('age'),
    ]);

    $parser->build();
})->throws(InvalidFieldValueException::class, "Invalid value for field 'age'.");

it('throws when any value in list fails validation', function (): void {
    $parser = SearchParser::query('age:1,foo')->allowedFields([
        AllowedField::integer('age'),
    ]);

    $parser->build();
})->throws(InvalidFieldValueException::class, "Invalid value for field 'age'.");
