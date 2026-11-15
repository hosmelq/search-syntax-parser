<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\Exception\ErrorReason;
use HosmelQ\SearchSyntaxParser\Exception\InvalidFieldOperatorException;
use HosmelQ\SearchSyntaxParser\Exception\InvalidFieldValueException;
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Sorting\AllowedSort;
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;

it('resolves sort keys and directions ignoring case with hyphenated keys', function (
    string $query,
    string $direction,
): void {
    $parser = SearchParser::query($query)->allowedSorts([
        AllowedSort::field('created-at', 'created_at')->defaultDirection('DESC'),
    ]);

    expect($parser->build())->toBeNull()
        ->and($parser->getSort()->getName())->toBe('created-at')
        ->and($parser->getSort()->getInternalName())->toBe('created_at')
        ->and($parser->getSort()->getDirection())->toBe($direction)
        ->and($parser->getSort()->isDefault())->toBeFalse()
        ->and($parser->build('string'))->toBe('sort:created-at-'.$direction);
})->with([
    ['sort:created-at', 'desc'],
    ['sort:CREATED-AT-aSc', 'asc'],
    ['SORT:Created-At-DeSc', 'desc'],
    ['sort:"created-at-asc"', 'asc'],
]);

it('reads only the final hyphen as a direction on a hyphenated key', function (): void {
    $parser = SearchParser::query('sort:created-at-desc')->allowedSorts([
        AllowedSort::field('created-at'),
    ]);

    $parser->parse();

    expect($parser->getSort()->getName())->toBe('created-at')
        ->and($parser->getSort()->getDirection())->toBe('desc')
        ->and($parser->getSort()->getInternalName())->toBe('created-at');
});

it('rejects a sort whose name is another sort plus a direction', function (string $name): void {
    expect(fn (): SearchParser => SearchParser::query('')->allowedSorts([
        AllowedSort::field('created'),
        AllowedSort::field($name),
    ]))->toThrow(InvalidArgumentException::class, 'is ambiguous with sort');
})->with(['created-desc', 'CREATED-ASC']);

it('keeps a sort directive name free when no sorts are configured', function (): void {
    $parser = SearchParser::query('sort:newest')->allowedFields([
        AllowedField::string('sort')->directive(),
    ]);

    expect($parser->parse())->toBeNull()
        ->and($parser->getDirectives())->toBe(['sort' => 'newest'])
        ->and($parser->getSort())->toBeNull()
        ->and($parser->isFieldAllowed('sort'))->toBeTrue()
        ->and(
            SearchParser::query('')->allowedSorts(['created'])->isFieldAllowed('sort'),
        )->toBeFalse();
});

it('applies default sorts only when no sort was typed and omits them from strings', function (): void {
    $parser = SearchParser::query('coffee')->allowedSorts(['created'])->defaultSort('CREATED-desc');

    expect($parser->build('string'))->toBe('coffee')
        ->and($parser->getSort()->getName())->toBe('created')
        ->and($parser->getSort()->getDirection())->toBe('desc')
        ->and($parser->getSort()->isDefault())->toBeTrue();

    $typed = SearchParser::query('sort:created')
        ->allowedSorts(['created'])
        ->defaultSort('created-desc');

    $typed->parse();

    expect($typed->getSort()->getDirection())
        ->toBe('asc')
        ->and($typed->getSort()->isDefault())
        ->toBeFalse();

    $empty = SearchParser::query('')
        ->allowedSorts([AllowedSort::field('created')->defaultDirection('desc')])
        ->defaultSort('created');

    expect($empty->build('string'))
        ->toBeNull()
        ->and($empty->getSort()->getDirection())
        ->toBe('desc');
});

it('reports unknown sort keys or directions at the value token', function (string $value): void {
    try {
        SearchParser::query('é sort:'.$value)
            ->allowedSorts(['created-at'])
            ->defaultSort('created-at')
            ->parse();

        test()->fail('Expected an invalid sort value.');
    } catch (InvalidFieldValueException $invalidFieldValueException) {
        expect($invalidFieldValueException->getField())->toBe('sort')
            ->and($invalidFieldValueException->getPosition())->toBe(7)
            ->and($invalidFieldValueException->getReason())->toBe(ErrorReason::InvalidFieldValue);
    }
})->with(['unknown', 'unknown-desc', 'created-at-up', 'created-at-descending', '""']);

it('enforces directive placement and shape for sorts', function (
    string $query,
    ErrorReason $reason,
    int $position,
): void {
    try {
        SearchParser::query($query)->allowedSorts(['created'])->parse();

        test()->fail('Expected a sort directive error.');
    } catch (InvalidFieldOperatorException $invalidFieldOperatorException) {
        expect($invalidFieldOperatorException->getField())->toBe('sort')
            ->and($invalidFieldOperatorException->getReason())->toBe($reason)
            ->and($invalidFieldOperatorException->getPosition())->toBe($position);
    }
})->with([
    ['sort:created OR coffee', ErrorReason::DirectiveNotTopLevel, 0],
    ['coffee OR (sort:created)', ErrorReason::DirectiveNotTopLevel, 11],
    ['NOT sort:created', ErrorReason::DirectiveNotTopLevel, 4],
    ['-sort:created', ErrorReason::DirectiveNotTopLevel, 1],
    ['sort:created sort:created-desc', ErrorReason::DuplicateDirective, 13],
    ['sort:created SORT:created-desc', ErrorReason::DuplicateDirective, 13],
    ['sort:created,created', ErrorReason::DirectiveInvalidClause, 5],
    ['sort:[a TO z]', ErrorReason::DirectiveInvalidClause, 5],
    ['sort:*', ErrorReason::DirectiveInvalidClause, 5],
    ['sort:created*', ErrorReason::DirectiveInvalidClause, 5],
    ['sort:!=created', ErrorReason::DirectiveInvalidClause, 5],
]);

it('accepts a sort inside nested AND groups', function (): void {
    $parser = SearchParser::query('(coffee AND ((sort:created)))')->allowedSorts(['created']);

    expect($parser->build())->toBe(SearchParser::query('coffee')->build())
        ->and($parser->getSort()->getDirection())->toBe('asc');
});

it('reserves sort only when configured regardless of configuration order or allowAnyField', function (
    bool $sortsFirst,
): void {
    $parser = SearchParser::query('sort:created')->allowAnyField();

    if ($sortsFirst) {
        $parser->allowedSorts(['created'])->allowedFields([AllowedField::string('sort')]);
    } else {
        $parser->allowedFields(['sort'])->allowedSorts(['created']);
    }
})->throws(InvalidArgumentException::class, 'reserved')->with([true, false]);

it('leaves unconfigured sort fields under ordinary field permissions', function (): void {
    $parser = SearchParser::query('sort:created')->allowedFields(['sort'])->allowedSorts([]);

    expect($parser->build()['field'])->toBe('sort')->and($parser->getSort())->toBeNull();
});

it('rejects invalid default sort configuration', function (string $default): void {
    SearchParser::query('')->allowedSorts(['created'])->defaultSort($default)->parse();
})->throws(InvalidArgumentException::class, 'default sort')->with(['unknown', 'created-up']);

it('rejects ambiguous sort declarations and invalid default directions', function (
    Closure $configure,
): void {
    $configure();
})->throws(InvalidArgumentException::class)->with([
    fn (): SearchParser => SearchParser::query('')->allowedSorts(['created', 'CREATED']),
    fn (): AllowedSort => AllowedSort::field('created')->defaultDirection('up'),
    fn (): AllowedSort => AllowedSort::field(''),
]);
