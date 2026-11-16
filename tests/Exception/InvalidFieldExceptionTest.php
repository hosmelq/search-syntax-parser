<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\Exception\InvalidFieldException;
use HosmelQ\SearchSyntaxParser\Exception\SearchSyntaxException;
use HosmelQ\SearchSyntaxParser\SearchParser;

it('exposes the external field and its character position', function (): void {
    try {
        SearchParser::query('  é name:x')->allowedFields(['age'])->build();

        test()->fail('Expected a field error.');
    } catch (InvalidFieldException $invalidFieldException) {
        expect($invalidFieldException)->toBeInstanceOf(SearchSyntaxException::class)
            ->not->toBeInstanceOf(InvalidArgumentException::class)
            ->and($invalidFieldException->getField())->toBe('name')
            ->and($invalidFieldException->getPosition())->toBe(4);
    }
});
