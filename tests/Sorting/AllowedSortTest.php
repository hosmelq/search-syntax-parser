<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\Sorting\AllowedSort;

it('rejects an empty name', function (): void {
    AllowedSort::field('');
})->throws(InvalidArgumentException::class, 'A sort name cannot be empty.');

it('rejects an invalid default direction', function (): void {
    AllowedSort::field('created')->defaultDirection('up');
})->throws(InvalidArgumentException::class, 'A sort direction must be asc or desc.');
