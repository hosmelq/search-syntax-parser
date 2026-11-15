<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\Exception\ErrorReason;
use HosmelQ\SearchSyntaxParser\Exception\SearchSyntaxException;
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\Field;

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
        ErrorReason::InvalidFieldValue => $parser->allowedFields([Field::integer('age')]),
        ErrorReason::OperatorNotAllowed => $parser->allowedFields([
            Field::integer('age')->operators('='),
        ]),
        default => $parser->allowAnyField(),
    };

    try {
        $parser->parse();

        test()->fail('Expected a query failure.');
    } catch (SearchSyntaxException $searchSyntaxException) {
        expect($searchSyntaxException->getReason())->toBe($reason)
            ->and($searchSyntaxException->getReason()->value)->toMatch(
                '/^[a-z]+\.[a-z][a-z0-9]*(?:_[a-z0-9]+)*$/',
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

it('preserves structured context for adapter exceptions', function (): void {
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
        ErrorReason::OperatorNotAllowed,
        $parameters,
        4,
    ) extends SearchSyntaxException {};

    expect($exception->getMessage())->toBe('Unsupported range.')
        ->and($exception->getReason())->toBe(ErrorReason::OperatorNotAllowed)
        ->and($exception->getReason()->value)->toBe('field.operator_not_allowed')
        ->and($exception->getParameters())->toBe($parameters)
        ->and($exception->getPosition())->toBe(4);
});
