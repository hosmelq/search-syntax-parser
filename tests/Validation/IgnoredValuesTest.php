<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\AST\Node\ComparisonNode;
use HosmelQ\SearchSyntaxParser\AST\Node\InNode;
use HosmelQ\SearchSyntaxParser\AST\Node\UnaryOperatorNode;
use HosmelQ\SearchSyntaxParser\AST\Span;
use HosmelQ\SearchSyntaxParser\Exception\ErrorReason;
use HosmelQ\SearchSyntaxParser\Exception\InvalidFieldOperatorException;
use HosmelQ\SearchSyntaxParser\Exception\InvalidFieldValueException;
use HosmelQ\SearchSyntaxParser\Exception\ParseException;
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;
use HosmelQ\SearchSyntaxParser\Validation\AllowedFieldItemRules;

it('removes ignored comparisons and simplifies boolean expressions without moving surviving spans', function (
    string $query,
): void {
    $parser = SearchParser::query($query)->allowedFields([
        'title',
        AllowedField::in('status', ['active'])->ignore('ALL'),
    ]);
    $node = $parser->parse();

    expect($node)->toBeInstanceOf(ComparisonNode::class)
        ->and($parser->build('string'))->toBe('title:x')
        ->and($node->getSpan())->toEqual(new Span(mb_strpos($query, 'title:x'), 7));
})->with([
    'title:x AND status:ALL',
    'status:ALL AND title:x',
    'title:x OR status:ALL',
    'status:ALL OR title:x',
    'status:ALL title:x',
    'title:x status:ALL',
    'NOT status:ALL OR title:x',
    '-status:ALL AND title:x',
    'NOT NOT status:ALL AND title:x',
    '-(status:ALL) AND (title:x)',
    'title:x AND (status:ALL OR NOT status:ALL)',
    '((status:ALL)) OR (title:x)',
    'title:x AND status:!=ALL',
    'title:x AND status:>ALL',
    'title:x AND status:>=ALL',
    'title:x AND status:<ALL',
    'title:x AND status:<=ALL',
]);

it('keeps negation and surviving binary subtrees with their original spans', function (): void {
    $query = 'NOT (status:ALL OR (title:x AND title:y))';
    $parser = SearchParser::query($query)->allowedFields([
        'title',
        AllowedField::string('status')->ignore('ALL'),
    ]);
    $node = $parser->parse();

    expect($parser->build('string'))->toBe('NOT (title:x AND title:y)')
        ->and($node->getSpan())->toEqual(new Span(0, mb_strlen($query)))
        ->and($node->getOperand()->getSpan())->toEqual(new Span(20, 19))
        ->and($node->getOperand()->getLeft()->getSpan())->toEqual(new Span(20, 7));
});

it('filters ignored list items while retaining an InNode even for one remaining item', function (
    string $query,
    array $expected,
    string $operator,
): void {
    $node = SearchParser::query($query)->allowedFields([
        AllowedField::in('status', ['active', 'draft'])->ignore('ALL'),
    ])->parse();

    expect($node)->toBeInstanceOf(InNode::class)
        ->getValues()->toBe($expected)
        ->getOperator()->toBe($operator)
        ->getSpan()->toEqual(new Span(0, mb_strlen($query)));
})->with([
    ['status:ALL,active,ALL,draft,ALL', ['active', 'draft'], '='],
    ['status:ALL,active', ['active'], '='],
    ['status:active,ALL', ['active'], '='],
    ['status:!=ALL,active,ALL', ['active'], '!='],
]);

it('filters literals from wildcard-expanded lists without ignoring patterns', function (
    string $query,
    string $expected,
): void {
    $parser = SearchParser::query($query)->allowedFields([
        AllowedField::string('status')->ignore('ALL', 'act*'),
    ]);

    expect($parser->build('string'))->toBe($expected)
        ->and($parser->parse()->getSpan())->toEqual(new Span(0, mb_strlen($query)));
})->with([
    ['status:ALL,act*,draft,ALL', 'status:act* OR status:draft'],
    ['status:!=ALL,act*,draft,ALL', 'status:!=act* AND status:!=draft'],
    ['status:ALL,act*,ALL', 'status:act*'],
    ['status:act*,ALL', 'status:act*'],
    ['status:ALL,"act*",act*', 'status:act*'],
]);

it('validates array collections and items only after filtering ignored values', function (): void {
    $parser = SearchParser::query('tags:ALL,active,ALL')->allowedFields([
        AllowedField::array('tags')->ignore('ALL')->size(1)->each(
            fn (AllowedFieldItemRules $rules): AllowedFieldItemRules => $rules->in(['active']),
        ),
    ]);

    expect($parser->parse()->getValues())->toBe(['active']);
});

it('turns ignored range bounds into open bounds without changing the range span', function (
    string $query,
    null|int $from,
    null|int $to,
): void {
    $node = SearchParser::query($query)->allowedFields([
        AllowedField::integer('age')->between(1, 10)->ignore('ALL'),
    ])->parse();

    expect($node->getFrom())->toBe($from)
        ->and($node->getTo())->toBe($to)
        ->and($node->getSpan())->toEqual(new Span(0, mb_strlen($query)));
})->with([
    ['age:[ALL TO 10]', null, 10],
    ['age:[1 TO ALL]', 1, null],
    ['age:[ALL TO *]', null, null],
    ['age:[* TO ALL]', null, null],
]);

it('removes ranges only when both bounds are ignored values', function (): void {
    expect(SearchParser::query('title:x AND age:[ALL TO ALL]')->allowedFields([
        'title',
        AllowedField::integer('age')->ignore('ALL'),
    ])->build('string'))->toBe('title:x');
});

it('matches ignored values strictly after casting and alias resolution', function (): void {
    $fields = [
        'title',
        AllowedField::integer('age')->alias('@all', 0)->min(1)->ignore(0),
        new AllowedField('code')->ignore(0),
    ];

    expect(SearchParser::query('title:x age:"0" age:@all')->allowedFields($fields)->build('string'))
        ->toBe('title:x')
        ->and(SearchParser::query('code:"0"')->allowedFields($fields)->parse()->getValue())->toBe(
            '0',
        )
        ->and(
            SearchParser::query('age:@all,2')->allowedFields($fields)->parse()->getValues(),
        )->toBe(
            [2],
        )
        ->and(
            SearchParser::query('age:[@all TO 2]')->allowedFields($fields)->parse()->getFrom(),
        )->toBeNull();
});

it('does not validate ignored values in any scalar value context', function (string $query): void {
    $field = AllowedField::callback('status', static function (): never {
        throw new LogicException('Ignored values must not be validated.');
    })->alias('@all', 'ALL')->ignore('ALL');

    expect(SearchParser::query($query)->allowedFields(['title', $field])->build('string'))->toBe(
        'title:x',
    );
})->with([
    'title:x status:ALL',
    'title:x status:@all',
    'title:x status:ALL,@all',
    'title:x status:[ALL TO @all]',
]);

it('does not ignore wildcard patterns existence checks or bare terms', function (
    string $query,
    string $expected,
): void {
    expect(SearchParser::query($query)->allowedFields([
        AllowedField::string('status')->ignore('ALL', 'ALL*', '*'),
    ])->build('string'))->toBe($expected);
})->with([
    ['status:ALL*', 'status:ALL*'],
    ['status:*', 'status:*'],
    ['NOT status:*', 'NOT status:*'],
    ['ALL', 'ALL'],
    ['ALL*', 'ALL*'],
]);

it('still validates wildcard patterns whose text matches an ignored value', function (
    string $query,
): void {
    SearchParser::query($query)->allowedFields([
        AllowedField::integer('age')->ignore('ALL', 'ALL*'),
    ])->parse();
})->throws(InvalidFieldValueException::class)->with(['age:ALL*', 'age:ALL,ALL*']);

it('checks operator and clause restrictions before ignoring values', function (
    string $query,
    string $restriction,
): void {
    $field = AllowedField::string('status')->alias('@all', static function (): never {
        throw new LogicException('Usage rules must run before aliases.');
    })->ignore('ALL', 'ALL*', '*');

    match ($restriction) {
        'operators' => $field->operators('!='),
        'lists' => $field->withoutLists(),
        'ranges' => $field->withoutRanges(),
        'wildcards' => $field->withoutWildcards(),
        'exists' => $field->withoutExists(),
    };

    SearchParser::query($query)->allowedFields([$field])->parse();
})->throws(InvalidFieldOperatorException::class)->with([
    ['status:ALL', 'operators'],
    ['status:@all', 'operators'],
    ['status:ALL,@all', 'lists'],
    ['status:[ALL TO @all]', 'ranges'],
    ['status:@all,ALL*', 'wildcards'],
    ['status:*', 'exists'],
]);

it('counts ignored clauses and expanded list items toward input limits', function (
    string $query,
): void {
    SearchParser::query($query)->maxClauses(1)->allowedFields([
        AllowedField::string('status')->ignore('ALL'),
    ])->parse();
})->throws(ParseException::class, 'maximum of 1 clauses')->with([
    'status:ALL status:ALL',
    'status:ALL,act*',
    'status:act*,ALL',
]);

it('suppresses defaults for removed clauses in every expression context', function (
    string $query,
): void {
    $parser = SearchParser::query($query)->allowedFields([
        AllowedField::string('status', 'stored_status')->ignore('ALL')->default('active'),
        AllowedField::string('region')->default('north'),
    ]);

    expect($parser->build('string'))->toBe('region:north')
        ->and($parser->build('string'))->toBe('region:north');
})->with([
    'status:ALL',
    'status:ALL,ALL',
    'status:[ALL TO ALL]',
    'NOT status:ALL',
    '(status:ALL OR NOT status:ALL)',
]);

it('does not ignore trusted defaults or treat bare terms as field mentions', function (): void {
    $field = AllowedField::string('status')->ignore('ALL')->default('ALL');

    expect(SearchParser::query('')->allowedFields([$field])->build('string'))->toBe('status:ALL')
        ->and(SearchParser::query('status')->allowedFields([$field])->build('string'))->toBe(
            'status AND status:ALL',
        );
});

it('reports no constraints exactly like an empty query when everything is ignored', function (
    string $query,
    string $operation,
    bool $withDefault,
): void {
    $field = AllowedField::string('status')->ignore('ALL');

    if ($withDefault) {
        $field->default('active');
    }

    $parser = SearchParser::query($query)->allowedFields([$field]);

    try {
        $operation === 'parse' ? $parser->parse() : $parser->build();

        test()->fail('Expected an empty query error.');
    } catch (ParseException $parseException) {
        expect($parseException->getMessage())->toBe(ParseException::emptyQuery()->getMessage())
            ->and($parseException->getReason())->toBe(ErrorReason::EmptyQuery)
            ->and($parseException->getParameters())->toBeEmpty()
            ->and($parseException->getPosition())->toBeNull();
    }
})->with([
    'status:ALL',
    'status:ALL,ALL',
    'status:[ALL TO ALL]',
    'NOT status:ALL',
    '(status:ALL)',
    'status:ALL AND status:ALL',
    'status:ALL OR NOT (status:ALL)',
])->with(['parse', 'build'])->with([false, true]);

it('validates remaining list items with the index rules of their written positions', function (): void {
    $field = AllowedField::array('pair')
        ->ignore(0)
        ->at(
            0,
            fn (AllowedFieldItemRules $rules): AllowedFieldItemRules => $rules->integer()->max(10),
        )
        ->at(
            1,
            fn (AllowedFieldItemRules $rules): AllowedFieldItemRules => $rules->integer()->min(100),
        );

    expect(SearchParser::query('pair:0,200')->allowedFields([$field])->parse()->getValues())->toBe([
        200,
    ]);
});

it('starts surviving AND and OR spans at the first remaining operand', function (
    string $query,
    int $offset,
    int $length,
): void {
    $fields = [AllowedField::string('status')->ignore('ALL'), AllowedField::string('title')];
    $ast = SearchParser::query($query)->allowedFields($fields)->parse();
    $node = $ast instanceof UnaryOperatorNode ? $ast->getOperand() : $ast;

    expect($node->getSpan()?->offset)->toBe($offset)->and($node->getSpan()?->length)->toBe($length);
})->with([
    'implicit AND' => ['status:ALL title:x title:y', 11, 15],
    'explicit AND' => ['status:ALL AND title:x AND title:y', 15, 19],
    'OR inside NOT' => ['NOT (status:ALL OR title:x OR title:y)', 19, 18],
]);

it('bounds AND and OR spans by the surviving operands at both edges', function (
    string $query,
    int $offset,
    int $length,
): void {
    $fields = [AllowedField::string('status')->ignore('ALL'), AllowedField::string('title')];
    $span = SearchParser::query($query)->allowedFields($fields)->parse()->getSpan();

    expect($span?->offset)->toBe($offset)->and($span?->length)->toBe($length);
})->with([
    'removed before an OR' => ['status:ALL title:x OR title:y', 11, 18],
    'removed after an OR' => ['title:x OR title:y status:ALL', 0, 18],
    'removed in the middle' => ['title:x status:ALL title:y', 0, 26],
    'group keeps its parentheses on the left' => ['(status:ALL title:x) OR title:y', 0, 31],
    'group keeps its parentheses on the right' => ['title:x OR (title:y status:ALL)', 0, 31],
]);
