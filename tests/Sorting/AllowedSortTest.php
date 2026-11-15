<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\Sorting\AllowedSort;

it('rejects an empty name', function (): void {
    AllowedSort::field('');
})->throws(InvalidArgumentException::class, 'A sort name cannot be empty.');

it('rejects an invalid default direction', function (): void {
    AllowedSort::field('created')->defaultDirection('up');
})->throws(InvalidArgumentException::class, 'A sort direction must be asc or desc.');

it('serializes the public name and default direction without its internal name', function (): void {
    expect(json_encode(AllowedSort::field('created', 'private_created'), JSON_THROW_ON_ERROR))
        ->toBe('{"direction":"asc","name":"created"}')
        ->and(
            AllowedSort::field('created', 'private_created')
                ->defaultDirection('DESC')
                ->jsonSerialize(),
        )
        ->toBe(['direction' => 'desc', 'name' => 'created']);
});
