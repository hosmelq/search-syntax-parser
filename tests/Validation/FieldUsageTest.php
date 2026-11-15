<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\Exception\ErrorReason;
use HosmelQ\SearchSyntaxParser\Exception\InvalidFieldOperatorException;
use HosmelQ\SearchSyntaxParser\Exception\ParseException;
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;

it('rejects restricted comparisons and clauses at their operator or value start', function (
    string $query,
    string $restriction,
    int $position,
    string $message,
    array $parameters,
): void {
    $field = AllowedField::integer('status', 'internal_status')->alias(
        '@me',
        static function (): never {
            throw new LogicException('The alias must not run for a disallowed clause.');
        },
    );

    match ($restriction) {
        'operators' => $field->operators('=', '!='),
        'equality' => $field->operators('!='),
        'lists' => $field->withoutLists(),
        'ranges' => $field->withoutRanges(),
        'wildcards' => $field->withoutWildcards(),
        'exists' => $field->withoutExists(),
    };

    try {
        SearchParser::query($query)->allowedFields([$field])->parse();

        test()->fail('Expected a field operator error before casting or validation.');
    } catch (InvalidFieldOperatorException $invalidFieldOperatorException) {
        expect($invalidFieldOperatorException->getField())->toBe('status')
            ->and($invalidFieldOperatorException->getPosition())->toBe($position)
            ->and($invalidFieldOperatorException->getMessage())->toBe($message)
            ->and($invalidFieldOperatorException->getReason())->toBe(
                ErrorReason::OperatorNotAllowed,
            )
            ->and($invalidFieldOperatorException->getParameters())->toBe([
                'field' => 'status',
                ...$parameters,
            ]);
    }
})->with([
    [
        'é status:>@me',
        'operators',
        9,
        "Operator '>' is not allowed for field 'status'.",
        ['operator' => '>'],
    ],
    [
        'status<=bad',
        'operators',
        6,
        "Operator '<=' is not allowed for field 'status'.",
        ['operator' => '<='],
    ],
    [
        'status:>bad,word',
        'operators',
        7,
        "Operator '>' is not allowed for field 'status'.",
        ['operator' => '>'],
    ],
    [
        'status:>bad*',
        'operators',
        7,
        "Operator '>' is not allowed for field 'status'.",
        ['operator' => '>'],
    ],
    [
        'é status:@me',
        'equality',
        9,
        "Operator '=' is not allowed for field 'status'.",
        ['operator' => '='],
    ],
    [
        'status:@me,bad',
        'lists',
        7,
        "Lists are not allowed for field 'status'.",
        ['clause' => 'lists'],
    ],
    [
        'status:!=@me,bad',
        'lists',
        7,
        "Lists are not allowed for field 'status'.",
        ['clause' => 'lists'],
    ],
    [
        'é status:[@me TO bad]',
        'ranges',
        9,
        "Ranges are not allowed for field 'status'.",
        ['clause' => 'ranges'],
    ],
    [
        'status:bad*',
        'wildcards',
        7,
        "Wildcards are not allowed for field 'status'.",
        ['clause' => 'wildcards'],
    ],
    [
        'status:@me,bad*',
        'wildcards',
        7,
        "Wildcards are not allowed for field 'status'.",
        ['clause' => 'wildcards'],
    ],
    [
        'status:!=@me,"bad"*',
        'wildcards',
        7,
        "Wildcards are not allowed for field 'status'.",
        ['clause' => 'wildcards'],
    ],
    [
        'status:@me,-bad*',
        'wildcards',
        7,
        "Wildcards are not allowed for field 'status'.",
        ['clause' => 'wildcards'],
    ],
    [
        'é status:*',
        'exists',
        9,
        "Exists queries are not allowed for field 'status'.",
        ['clause' => 'exists'],
    ],
]);

it('allows all six comparison operators when configured', function (string $operator): void {
    $queryOperator = $operator === '=' ? '' : $operator;
    $node = SearchParser::query('status:'.$queryOperator.'5')
        ->allowedFields([AllowedField::integer('status')->operators($operator)])
        ->parse();

    expect($node->getOperator())->toBe($operator)->and($node->getValue())->toBe(5);
})->with(['=', '!=', '>', '>=', '<', '<=']);

it('rejects unknown configured operators', function (string $operator): void {
    AllowedField::string('status')->operators('=', $operator);
})->throws(InvalidArgumentException::class)->with(['LIKE', '==', ':', '', 'AND']);

it('permits literal asterisks and existence when wildcard patterns are disabled', function (): void {
    $field = AllowedField::string('status')->withoutWildcards()->operators('!=');

    expect(SearchParser::query('status:!="*"')->allowedFields([$field])->parse()->getValue())->toBe(
        '*',
    )
        ->and(SearchParser::query('status:*')->allowedFields([$field])->parse()->getType())->toBe(
            'Exists',
        )
        ->and(
            SearchParser::query('status:["a*b" TO "*"]')->allowedFields([$field])->parse()->getTo(),
        )->toBe(
            '*',
        );
});

it('trusts defaults despite every configured usage restriction', function (): void {
    $fields = [
        AllowedField::string('scalar')->operators('!=')->default('active'),
        AllowedField::array('list')->withoutLists()->default(['one', 'two']),
        AllowedField::string('missing')->withoutExists()->default(null),
        AllowedField::string('literal')->withoutWildcards()->default('literal*'),
    ];
    $result = SearchParser::query('')->allowedFields($fields)->build();

    expect($result['left']['left']['left']['value'])->toBe('active')
        ->and($result['left']['left']['right']['values'])->toBe(['one', 'two'])
        ->and($result['left']['right']['operand']['type'])->toBe('exists')
        ->and($result['right']['value'])->toBe('literal*');
});

it('disables every comparison operator when the configured subset is empty', function (): void {
    SearchParser::query('status:active')->allowedFields([
        AllowedField::string('status')->operators(),
    ])->parse();
})->throws(InvalidFieldOperatorException::class, "Operator '=' is not allowed for field 'status'.");

it('reports invalid wildcard usage instead of a disabled clause', function (
    AllowedField $field,
    string $query,
): void {
    try {
        SearchParser::query($query)->allowedFields([$field])->parse();
    } catch (ParseException $parseException) {
        expect($parseException->getReason())->toBe(ErrorReason::InvalidWildcardUsage);

        return;
    }

    throw new RuntimeException('Expected a parse exception.');
})->with([
    'not equal with exists disabled' => [AllowedField::integer('age')->withoutExists(), 'age:!=*'],
    'ordering with exists disabled' => [AllowedField::integer('age')->withoutExists(), 'age:>*'],
    'bare list item with wildcards disabled' => [
        AllowedField::string('n')->withoutWildcards(),
        'n:a,*',
    ],
]);

it('rejects negated wildcard patterns when wildcards are disabled', function (string $query): void {
    try {
        SearchParser::query($query)
            ->allowedFields([AllowedField::string('x')->withoutWildcards()])
            ->parse();
    } catch (InvalidFieldOperatorException $invalidFieldOperatorException) {
        expect($invalidFieldOperatorException->getParameters())->toBe([
            'field' => 'x',
            'clause' => 'wildcards',
        ]);

        return;
    }

    throw new RuntimeException('Expected an operator exception.');
})->with(['x:-*', 'x:a,-*', 'x:-a*']);
