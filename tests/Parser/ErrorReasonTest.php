<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\Exception\ErrorReason;
use HosmelQ\SearchSyntaxParser\Exception\SearchSyntaxException;
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;

it('exposes a machine-readable reason and parameters for query failures', function (
    string $query,
    ErrorReason $reason,
    array $parameters,
): void {
    $parser = SearchParser::query($query);

    match ($reason) {
        ErrorReason::MaximumLength => $parser->maxLength(3),
        ErrorReason::MaximumDepth => $parser->maxDepth(1),
        ErrorReason::MaximumClauses => $parser->maxClauses(1),
        ErrorReason::FieldNotAllowed => $parser->allowedFields(['age', 'name']),
        ErrorReason::InvalidFieldValue => $parser->allowedFields([AllowedField::integer('age')]),
        ErrorReason::OperatorNotAllowed => $parser->allowedFields([
            AllowedField::integer('age')->operators('='),
        ]),
        default => $parser->allowAnyField(),
    };

    try {
        $parser->parse();

        test()->fail('Expected a query failure.');
    } catch (SearchSyntaxException $searchSyntaxException) {
        expect($searchSyntaxException->getReason())->toBe($reason)
            ->and($searchSyntaxException->getReason()->value)->toMatch(
                '/^[a-z][a-z0-9]*(?:_[a-z0-9]+)*$/',
            )
            ->and($searchSyntaxException->getParameters())->toBe($parameters);
    }
})->with([
    ['coffee )', ErrorReason::UnexpectedToken, ['token' => ')']],
    ['age:[1 to 2]', ErrorReason::ExpectedToken, ['expected' => 'TO', 'actual' => 'Word']],
    ['(coffee', ErrorReason::ExpectedToken, ['expected' => 'CloseParenthesis', 'actual' => 'NULL']],
    ['age:', ErrorReason::ExpectedValue, []],
    ['coffee AND', ErrorReason::UnexpectedEndOfInput, []],
    ['"coffee', ErrorReason::UnterminatedQuote, []],
    ["\xff", ErrorReason::InvalidUtf8, []],
    ['age:>*', ErrorReason::InvalidWildcardUsage, ['field' => 'age']],
    ['age:>a*', ErrorReason::InvalidWildcardPattern, []],
    ['coffee', ErrorReason::MaximumLength, ['limit' => 3]],
    ['((coffee))', ErrorReason::MaximumDepth, ['limit' => 1]],
    ['coffee tea', ErrorReason::MaximumClauses, ['limit' => 1]],
    [
        'status:active',
        ErrorReason::FieldNotAllowed,
        ['field' => 'status', 'allowed' => ['age', 'name']],
    ],
    ['age:old', ErrorReason::InvalidFieldValue, ['field' => 'age']],
    ['age:>10', ErrorReason::OperatorNotAllowed, ['field' => 'age', 'operator' => '>']],
]);

it('reports a tokenization failure reason without a source position', function (): void {
    $limit = ini_get('pcre.backtrack_limit');

    ini_set('pcre.backtrack_limit', '1');

    try {
        SearchParser::query('title:Coffee')->allowAnyField()->parse();

        test()->fail('Expected a tokenization failure.');
    } catch (SearchSyntaxException $searchSyntaxException) {
        expect($searchSyntaxException->getReason())->toBe(ErrorReason::TokenizationFailed)
            ->and($searchSyntaxException->getParameters())->toBeEmpty()
            ->and($searchSyntaxException->getPosition())->toBeNull();
    } finally {
        ini_set('pcre.backtrack_limit', (string) $limit);
    }
});

it('lets third-party subclasses describe unsupported operations', function (): void {
    $parameters = [
        'field' => 'age',
        'enabled' => true,
        'weight' => 1.5,
        'limit' => 32,
        'allowed' => ['='],
        'context' => null,
    ];
    $exception = new class (
        'Unsupported range.',
        ErrorReason::Unsupported,
        $parameters,
        4,
    ) extends SearchSyntaxException {};

    expect($exception->getMessage())->toBe('Unsupported range.')
        ->and($exception->getReason())->toBe(ErrorReason::Unsupported)
        ->and($exception->getReason()->value)->toBe('unsupported')
        ->and($exception->getParameters())->toBe($parameters)
        ->and($exception->getPosition())->toBe(4);
});
