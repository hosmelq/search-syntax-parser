<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\AST\Node\BinaryOperatorNode;
use HosmelQ\SearchSyntaxParser\AST\Node\ComparisonNode;
use HosmelQ\SearchSyntaxParser\AST\Node\ExistsNode;
use HosmelQ\SearchSyntaxParser\AST\Node\InNode;
use HosmelQ\SearchSyntaxParser\AST\Node\NodeInterface;
use HosmelQ\SearchSyntaxParser\AST\Node\RangeNode;
use HosmelQ\SearchSyntaxParser\AST\Node\TermNode;
use HosmelQ\SearchSyntaxParser\AST\Node\UnaryOperatorNode;
use HosmelQ\SearchSyntaxParser\AST\Node\WildcardNode;
use HosmelQ\SearchSyntaxParser\AST\Span;
use HosmelQ\SearchSyntaxParser\Exception\ErrorReason;
use HosmelQ\SearchSyntaxParser\Exception\InvalidFieldException;
use HosmelQ\SearchSyntaxParser\Exception\InvalidFieldOperatorException;
use HosmelQ\SearchSyntaxParser\Exception\InvalidFieldValueException;
use HosmelQ\SearchSyntaxParser\Exception\ParseException;
use HosmelQ\SearchSyntaxParser\Exception\SearchSyntaxException;
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\Field;
use HosmelQ\SearchSyntaxParser\Validation\FieldItemRules;

it('checks every pattern in a list before running aliases or validators', function (): void {
    $field = Field::string('name')->alias(
        'first',
        static fn (): never => throw new LogicException('Aliases must not run.'),
    );

    SearchParser::query('name:first,a*')->allowedFields([$field->minWildcardLength(2)])->parse();
})->throws(InvalidFieldOperatorException::class);

it('checks operator and clause restrictions before ignoring values', function (
    string $query,
    string $restriction,
): void {
    $field = Field::string('status')->alias('@all', static function (): never {
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
        Field::string('status')->ignore('ALL'),
    ])->parse();
})->throws(ParseException::class, 'maximum of 1 clauses')->with([
    'status:ALL status:ALL',
    'status:ALL,act*',
    'status:act*,ALL',
]);

it('does not allow leading free text patterns through a field opt in', function (): void {
    SearchParser::query('*son')->allowedFields([
        Field::string('name')->allowLeadingWildcards(),
    ])->parse();
})->throws(ParseException::class);

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

it('keeps explicit usage restrictions ahead of directive aliases', function (): void {
    SearchParser::query('mode:@me')->allowedFields([
        Field::string('mode')->directive()->operators('!=')->alias(
            '@me',
            static function (): never {
                throw new LogicException('Disallowed operators must not resolve aliases.');
            },
        ),
    ])->parse();
})->throws(InvalidFieldOperatorException::class, "Operator '='");

it('positions field validation errors at the start of the value', function (
    string $query,
    int $position,
): void {
    try {
        SearchParser::query($query)->allowedFields([Field::integer('age', 'user_age')])->build();

        test()->fail('Expected a value error.');
    } catch (InvalidFieldValueException $invalidFieldValueException) {
        expect($invalidFieldValueException)->toBeInstanceOf(SearchSyntaxException::class)
            ->not->toBeInstanceOf(InvalidArgumentException::class)
            ->and($invalidFieldValueException->getField())->toBe('age')
            ->and($invalidFieldValueException->getPosition())->toBe($position);
    }
})->with([
    ['  é age:bad', 8],
    ['  é age:1,bad', 8],
    ['  é age:[1 TO bad]', 8],
    ['  é age:2*', 8],
    ['  é age:-bad', 8],
]);

it('rejects a separated minus in relative values', function (): void {
    $fields = [Field::date('created')];

    expect(
        fn (): null|NodeInterface
                => SearchParser::query('created:>- 7d')->allowedFields($fields)->parse(),
    )->toThrow(ParseException::class);
});

it('rejects an exact wildcard outside exists and open bounds', function (string $query): void {
    SearchParser::query($query)->allowAnyField()->build();
})->throws(ParseException::class)->with(['*', 'name:!=*', 'name!=*', 'name:>*', 'name:a,*']);

it('rejects compact ranges before resolving aliases when ranges are disabled', function (): void {
    try {
        SearchParser::query('é x:first..5')->allowedFields([
            Field::integer('x')
                ->withoutRanges()
                ->alias(
                    'first',
                    static fn (): never => throw new LogicException('Aliases must not run.'),
                ),
        ])->parse();

        test()->fail('Expected a range restriction error.');
    } catch (InvalidFieldOperatorException $invalidFieldOperatorException) {
        expect($invalidFieldOperatorException->getField())->toBe('x')
            ->and($invalidFieldOperatorException->getReason())->toBe(
                ErrorReason::OperatorNotAllowed,
            )
            ->and($invalidFieldOperatorException->getParameters())->toBe([
                'field' => 'x',
                'operation' => 'ranges',
            ])
            ->and($invalidFieldOperatorException->getPosition())->toBe(4);
    }
});

it('rejects configured leading wildcards despite the global opt-in', function (): void {
    SearchParser::query('name:*son')->allowLeadingWildcards()->allowedFields([
        Field::string('name'),
    ])->parse();
})->throws(InvalidFieldOperatorException::class);

it('rejects directives below OR or negation before resolving aliases even if filters vanish', function (
    string $query,
    int $position,
): void {
    $parser = SearchParser::query($query)->allowedFields([
        Field::string('mode')->directive()->ignore('only')->alias(
            '@me',
            static function (): never {
                throw new LogicException('Placement must be checked before resolving aliases.');
            },
        ),
        Field::string('status')->ignore('any'),
    ]);

    try {
        $parser->parse();

        test()->fail('Expected an invalid directive placement.');
    } catch (InvalidFieldOperatorException $invalidFieldOperatorException) {
        expect($invalidFieldOperatorException->getField())->toBe('mode')
            ->and($invalidFieldOperatorException->getReason())->toBe(
                ErrorReason::DirectiveNotTopLevel,
            )
            ->and($invalidFieldOperatorException->getPosition())->toBe($position)
            ->and($invalidFieldOperatorException->getParameters())->toBe(['field' => 'mode']);
    }
})->with([
    ['mode:@me OR coffee', 0],
    ['coffee OR mode:@me', 10],
    ['é OR (tea AND mode:@me)', 14],
    ['(mode:@me AND tea) OR coffee', 1],
    ['NOT mode:@me', 4],
    ['-mode:@me', 1],
    ['NOT (tea AND (mode:@me))', 14],
    ['NOT NOT mode:@me', 8],
    ['mode:only OR status:any', 0],
    ['status:any OR (mode:only)', 15],
    ['(mode:only OR status:any) AND tea', 1],
]);

it('rejects expanded lists exceeding the clause limit', function (): void {
    expect(
        fn (): mixed
                => SearchParser::query('name:a,b*,c')->allowAnyField()->maxClauses(2)->build(),
    )->toThrow(ParseException::class, 'maximum of 2 clauses');
});

it('rejects field patterns that fail validation of the original text', function (): void {
    expect(
        fn (): mixed
                => SearchParser::query('name:joe*')
                    ->allowedFields([Field::string('name')->max(3)])
                    ->build(),
    )
        ->toThrow(InvalidFieldValueException::class)
        ->and(
            fn (): mixed
                => SearchParser::query('age:2*')->allowedFields([Field::integer('age')])->build(),
        )
        ->toThrow(InvalidFieldValueException::class);
});

it('rejects leading free text wildcards with a parse reason and position', function (): void {
    try {
        SearchParser::query('é *son')->parse();

        test()->fail('Expected a leading wildcard error.');
    } catch (ParseException $parseException) {
        expect($parseException->getReason())->toBe(ErrorReason::LeadingWildcardNotAllowed)
            ->and($parseException->getParameters())->toBeEmpty()
            ->and($parseException->getPosition())->toBe(2);
    }
});

it('rejects leaf clauses above the default limit', function (string $input): void {
    SearchParser::query($input)->allowAnyField()->parse();
})->throws(ParseException::class, 'maximum of 1024 clauses')->with([
    str_repeat('a ', 1_025),
    implode(' OR ', array_fill(0, 1_025, 'f:*')),
]);

it('rejects list and comparison combinations like the bracket notation', function (
    string $compact,
    string $bracket,
    ErrorReason $reason,
): void {
    foreach ([$compact, $bracket] as $query) {
        try {
            SearchParser::query($query)->allowAnyField()->parse();

            test()->fail('Expected invalid range syntax.');
        } catch (ParseException $exception) {
            expect($exception->getReason())->toBe($reason);
        }
    }
})->with([
    ['x:1..2,3', 'x:[1 TO 2],3', ErrorReason::UnexpectedToken],
    ['x:3,1..2', 'x:3,[1 TO 2]', ErrorReason::ExpectedValue],
    ['x:>1..2', 'x:>[1 TO 2]', ErrorReason::ExpectedValue],
    ['x:!=*..2', 'x:!=[* TO 2]', ErrorReason::ExpectedValue],
    ['x>=1..2', 'x>=[1 TO 2]', ErrorReason::ExpectedValue],
    ['x:3,*..2', 'x:3,[* TO 2]', ErrorReason::ExpectedValue],
]);

it('rejects mixed clauses exceeding the clause limit', function (): void {
    $parser = SearchParser::query('a:1,2,3 b:[1 TO 3] c:1 d:*')->allowAnyField()->maxClauses(4);

    expect(fn (): mixed => $parser->maxClauses(3)->build())->toThrow(
        ParseException::class,
        'maximum of 3 clauses',
    );
});

it('rejects mixed lists that fail array item or collection rules', function (): void {
    $field = Field::array('name')
        ->size(2)
        ->each(
            fn (FieldItemRules $rules): FieldItemRules => $rules->string()->max(3),
        );

    expect(
        fn (): mixed => SearchParser::query('name:jo*,anna')->allowedFields([$field])->build(),
    )
        ->toThrow(InvalidFieldValueException::class)
        ->and(
            fn (): mixed
                => SearchParser::query('name:jo*,ann,bob')->allowedFields([$field])->build(),
        )
        ->toThrow(InvalidFieldValueException::class);
});

it('rejects nesting above the default limit', function (string $input): void {
    SearchParser::query($input)->allowAnyField()->build();
})->throws(ParseException::class, 'maximum depth of 32')->with([
    str_repeat('(', 33).'a'.str_repeat(')', 33),
    str_repeat('NOT ', 33).'a',
    str_repeat('-', 33).'a',
    str_repeat('(NOT ', 17).'a'.str_repeat(')', 17),
]);

it('rejects quoted asterisks as numeric range bounds', function (): void {
    SearchParser::query('x:[* TO "*"]')->allowedFields([Field::integer('x')])->parse();
})->throws(InvalidFieldValueException::class);

it('rejects repeated directives including an ignored first mention', function (): void {
    try {
        SearchParser::query('é mode:any AND (mode:only)')->allowedFields([
            Field::string('mode')->directive()->ignore('any')->default('without'),
        ])->parse();

        test()->fail('Expected a duplicate directive.');
    } catch (InvalidFieldOperatorException $invalidFieldOperatorException) {
        expect($invalidFieldOperatorException->getReason())->toBe(ErrorReason::DuplicateDirective)
            ->and($invalidFieldOperatorException->getField())->toBe('mode')
            ->and($invalidFieldOperatorException->getPosition())->toBe(16);
    }
});

it('rejects syntax that cannot be a value', function (string $input): void {
    SearchParser::query($input)->allowAnyField()->parse();
})->throws(ParseException::class, 'Expected value.')->with([
    'title:(a OR b)', 'title:[1 TO (b)]', 'title:a,(b)', 'title:- 5',
    'title:-AND', "title:-\u{00A0}5", 'title:>', 'title:]', '*',
]);

it('rejects wildcard comparison and range patterns', function (string $query): void {
    SearchParser::query($query)->allowAnyField()->build();
})->throws(ParseException::class, 'Wildcard patterns are only supported')->with([
    'name:>jo*', 'name>=jo*', 'name:<"a b"*', 'name:<=*fee',
    'name:>a,jo*', 'name:>=jo*,a', 'name:[jo* TO z]', 'name:[a TO z*]',
]);

it('rejects wildcard patterns in compact bounds', function (string $query, int $position): void {
    try {
        SearchParser::query($query)->allowAnyField()->parse();

        test()->fail('Expected an invalid wildcard bound.');
    } catch (ParseException $parseException) {
        expect($parseException->getReason())->toBe(ErrorReason::InvalidWildcardPattern)
            ->and($parseException->getPosition())->toBe($position);
    }
})->with([['x:a*..z', 2], ['x:a..*z', 5]]);

it('rejects wildcard patterns on converted fields before resolving other values', function (
    string $query,
    bool $scale,
): void {
    $field = $scale
        ? Field::numeric('value')->scale(100)
        : Field::string('value')->map(['x' => 1]);

    $field->alias('@fail', static function (): never {
        throw new LogicException('Wildcard restrictions must run before alias resolution.');
    });

    SearchParser::query($query)->allowedFields([$field])->parse();
})->throws(InvalidFieldOperatorException::class)->with([
    'value:ab*', 'value:!=ab*', 'value:"a b"*', 'value:@fail,ab*', 'value:-ab*',
])->with([false, true]);

it('reports character positions in the exact original input', function (
    string $query,
    int $position,
): void {
    foreach ([
        SearchParser::query($query)->allowAnyField(),
        new SearchParser($query)->allowAnyField(),
    ] as $parser) {
        try {
            $parser->build();

            test()->fail('Expected a parse error.');
        } catch (ParseException $exception) {
            expect($exception)->toBeInstanceOf(SearchSyntaxException::class)
                ->and($exception->getPosition())->toBe($position);
        }
    }
})->with([
    ['  café )', 7],
    ['🙂 AND', 5],
    ['  name:', 7],
    ['  name:>', 8],
    ['é name:"unfinished', 7],
    ["é name:'unfinished", 7],
    ['é name:[1 to 3]', 10],
    ['é name:[1 TO 3', 14],
    ['é name:[1 TO ]', 13],
    ['é name:>*', 8],
    ['é name:>jo*', 8],
    ['é name:[jo* TO z]', 8],
    ['é name:[a TO z*]', 13],
    ['é name:a,*', 9],
    ['  OR', 2],
]);

it('reports invalid wildcard usage instead of a disabled clause', function (
    Field $field,
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
    'not equal with exists disabled' => [Field::integer('age')->withoutExists(), 'age:!=*'],
    'ordering with exists disabled' => [Field::integer('age')->withoutExists(), 'age:>*'],
    'bare list item with wildcards disabled' => [
        Field::string('n')->withoutWildcards(),
        'n:a,*',
    ],
]);

it('reports times on fields without time at the value in comparisons, lists and ranges', function (
    string $query,
    int $position,
): void {
    try {
        SearchParser::query($query)->allowedFields([
            Field::date('due')->withoutTime(),
        ])->parse();

        test()->fail('Expected an invalid date value.');
    } catch (InvalidFieldValueException $invalidFieldValueException) {
        expect($invalidFieldValueException->getField())->toBe('due')
            ->and($invalidFieldValueException->getPosition())->toBe($position);
    }
})->with([
    ['due:<now', 5],
    ['due:today,2024-03-15T10:00', 4],
    ['due:[today TO -7d]', 4],
    ['due:today..now', 4],
]);

it('requires a scalar equality directive before aliases or casting', function (
    string $clause,
    int $position,
): void {
    try {
        SearchParser::query($clause)->allowedFields([
            Field::string('mode')->directive()->alias('@me', static function (): never {
                throw new LogicException('Clause restrictions must precede alias resolution.');
            }),
        ])->parse();

        test()->fail('Expected an invalid directive clause.');
    } catch (InvalidFieldOperatorException $invalidFieldOperatorException) {
        expect($invalidFieldOperatorException->getReason())->toBe(
            ErrorReason::DirectiveInvalidClause,
        )
            ->and($invalidFieldOperatorException->getField())->toBe('mode')
            ->and($invalidFieldOperatorException->getPosition())->toBe($position);
    }
})->with([
    ['mode:@me,only', 5],
    ['mode:[@me TO only]', 5],
    ['mode:1..3', 5],
    ['mode:only*', 5],
    ['mode:"only"*', 5],
    ['mode:*', 5],
    ['mode:!=@me', 5],
    ['mode:>@me', 5],
    ['mode>=@me', 4],
    ['mode:<@me', 5],
    ['mode<=@me', 4],
]);

it('still validates wildcard patterns whose text matches an ignored value', function (
    string $query,
): void {
    SearchParser::query($query)->allowedFields([
        Field::integer('age')->ignore('ALL', 'ALL*'),
    ])->parse();
})->throws(InvalidFieldValueException::class)->with(['age:ALL*', 'age:ALL,ALL*']);

it('throws on expected token mismatch', function (): void {
    SearchParser::query('price:[10 50]')->allowAnyField()->build();
})->throws(ParseException::class, 'Unexpected token, expected TO but got Word.');

it('throws on invalid wildcard usage', function (): void {
    SearchParser::query('price>*')->allowAnyField()->build();
})->throws(
    ParseException::class,
    "Wildcard is only supported with ':' for exists queries; use NOT price:* for negation.",
);

it('throws on invalid wildcard usage with colon and operator', function (): void {
    SearchParser::query('price:>*')->allowAnyField()->build();
})->throws(
    ParseException::class,
    "Wildcard is only supported with ':' for exists queries; use NOT price:* for negation.",
);

it('throws on missing value', function (string $input): void {
    SearchParser::query($input)->allowAnyField()->build();
})->throws(ParseException::class, 'Expected value.')->with([
    'colon only' => 'price:',
    'operator only' => 'price:>',
]);

it('throws on unexpected end of input', function (): void {
    SearchParser::query('title:Coffee AND')->allowAnyField()->build();
})->throws(ParseException::class, 'Unexpected end of input.');

it('throws on unexpected token after complete parse', function (): void {
    SearchParser::query('title:Coffee )')->allowAnyField()->build();
})->throws(ParseException::class, 'Unexpected token after query: ).');

it('throws when any value in list fails validation', function (): void {
    $parser = SearchParser::query('age:1,foo')->allowedFields([
        Field::integer('age'),
    ]);

    $parser->build();
})->throws(InvalidFieldValueException::class, "Invalid value for field 'age'.");

it('throws when field is not allowed', function (): void {
    $parser = SearchParser::query('name:john')->allowedFields(['age']);

    $parser->build();
})->throws(InvalidFieldException::class, 'not allowed.');

it('throws when field value fails validation', function (): void {
    $parser = SearchParser::query('age:john')->allowedFields([
        Field::integer('age'),
    ]);

    $parser->build();
})->throws(InvalidFieldValueException::class, "Invalid value for field 'age'.");

it('throws when field value fails validation with operator (no colon)', function (): void {
    $parser = SearchParser::query('age>john')->allowedFields([
        Field::integer('age'),
    ]);

    $parser->build();
})->throws(InvalidFieldValueException::class, "Invalid value for field 'age'.");

it('validates alias results in every value context', function (string $query): void {
    SearchParser::query($query)->allowedFields([
        Field::integer('x')->min(10)->alias('@me', 7),
    ])->parse();
})->throws(InvalidFieldValueException::class)->with([
    'x:@me',
    'x:10,@me',
    'x:[@me TO 10]',
    'x:[10 TO @me]',
]);

it('validates both compact bounds and reports their range start', function (string $query): void {
    try {
        SearchParser::query($query)->allowedFields([Field::integer('x')->between(1, 5)])->parse();

        test()->fail('Expected an invalid bound error.');
    } catch (InvalidFieldValueException $invalidFieldValueException) {
        expect($invalidFieldValueException->getReason())->toBe(ErrorReason::InvalidFieldValue)
            ->and($invalidFieldValueException->getField())->toBe('x')
            ->and($invalidFieldValueException->getPosition())->toBe(4);
    }
})->with(['é x:0..5', 'é x:1..6', 'é x:*..bad']);

it('validates both range bounds with the field rules', function (string $input): void {
    SearchParser::query($input)->allowedFields([Field::integer('age')->min(0)->max(3)])->parse();
})->throws(InvalidFieldValueException::class)->with([
    'age:[abc TO xyz]', 'age:[abc TO 2]', 'age:[1 TO xyz]', 'age:[1 TO 5]', 'age:[-1 TO 2]',
]);

it('validates directives at their value position', function (): void {
    try {
        SearchParser::query('é limit:zero')->allowedFields([
            Field::integer('limit')->directive()->min(1),
        ])->parse();

        test()->fail('Expected an invalid directive value.');
    } catch (InvalidFieldValueException $invalidFieldValueException) {
        expect($invalidFieldValueException->getField())
            ->toBe('limit')
            ->and($invalidFieldValueException->getPosition())
            ->toBe(8);
    }
});

it('validates quoted asterisk range bounds as literal values', function (): void {
    SearchParser::query('x:["*" TO 5]')->allowedFields([Field::integer('x')])->parse();
})->throws(InvalidFieldValueException::class);

it('accepts a sort inside nested AND groups', function (): void {
    $parser = SearchParser::query('(coffee AND ((sort:created)))')->allowedSorts(['created']);

    expect($parser->build())->toBe(SearchParser::query('coffee')->build())
        ->and($parser->getSort()->getDirection())->toBe('asc');
});

it('accepts field patterns whose original text passes validation', function (): void {
    expect(
        SearchParser::query('name:jo*')
            ->allowedFields([Field::string('name')->max(3)])
            ->build()['segments'],
    )->toBe(['jo', '']);
});

it('accepts ISO datetimes as complete values', function (string $datetime): void {
    expect(
        SearchParser::query('created_at:>'.$datetime)->allowAnyField()->parse()->getValue(),
    )->toBe(
        $datetime,
    );
})->with([
    '2025-01-01T12:30', '2025-01-01T12:30:45', '2025-01-01T12:30:45.123Z',
    '2025-01-01T12:30:45Z', '2025-01-01T12:30:45+05:30', '2025-01-01t12:30:45.123-0530',
]);

it('accepts keyword tokens in lists and range bounds', function (): void {
    expect(SearchParser::query('title:and,or,not')->allowAnyField()->parse()->getValues())->toBe([
        'and',
        'or',
        'not',
    ])
        ->and(SearchParser::query('title:[AND TO OR]')->allowAnyField()->build())->toBe([
            'field' => 'title',
            'from' => 'AND',
            'to' => 'OR',
            'type' => 'range',
        ]);
});

it('accepts negative comparisons, lists and ranges', function (): void {
    expect(SearchParser::query('temp:>-5')->allowAnyField()->parse()->getValue())->toBe(-5)
        ->and(SearchParser::query('temp>-5')->allowAnyField()->parse()->getValue())->toBe(-5)
        ->and(SearchParser::query('temp:-5,-1')->allowAnyField()->parse()->getValues())->toBe([
            -5,
            -1,
        ])
        ->and(SearchParser::query('temp:[-5 TO 5]')->allowAnyField()->build())->toBe([
            'field' => 'temp',
            'from' => -5,
            'to' => 5,
            'type' => 'range',
        ]);
});

it('accepts quoted URLs and times', function (string $value): void {
    expect(SearchParser::query('value:"'.$value.'"')->allowAnyField()->parse()->getValue())->toBe(
        $value,
    );
})->with(['https://example.com', '12:30']);

it('allows literal special characters and negative scalar directive values', function (): void {
    $parser = SearchParser::query('mode:"a*,b..c" offset:-3')->allowedFields([
        Field::string('mode')->directive(),
        Field::integer('offset')->directive(),
    ]);

    $parser->parse();

    expect($parser->getDirectives())->toBe(['mode' => 'a*,b..c', 'offset' => -3]);
});

it('allows the depth and clause boundaries', function (): void {
    expect(
        SearchParser::query(str_repeat('(', 32).'a'.str_repeat(')', 32))->parse()->getValue(),
    )->toBe(
        'a',
    )
        ->and(SearchParser::query(str_repeat('NOT ', 32).'a')->build()['operator'])->toBe('NOT')
        ->and(SearchParser::query(str_repeat('a ', 1_024))->parse()->getOperator())->toBe('AND');
});

it('bounds AND and OR spans by the surviving operands at both edges', function (
    string $query,
    int $offset,
    int $length,
): void {
    $fields = [Field::string('status')->ignore('ALL'), Field::string('title')];
    $span = SearchParser::query($query)->allowedFields($fields)->parse()->getSpan();

    expect($span?->offset)->toBe($offset)->and($span?->length)->toBe($length);
})->with([
    'removed before an OR' => ['status:ALL title:x OR title:y', 11, 18],
    'removed after an OR' => ['title:x OR title:y status:ALL', 0, 18],
    'removed in the middle' => ['title:x status:ALL title:y', 0, 26],
    'group keeps its parentheses on the left' => ['(status:ALL title:x) OR title:y', 0, 31],
    'group keeps its parentheses on the right' => ['title:x OR (title:y status:ALL)', 0, 31],
]);

it('builds explicit wildcard patterns', function (
    string $query,
    null|string $field,
    string $operator,
    array $segments,
): void {
    expect(SearchParser::query($query)->allowAnyField()->allowLeadingWildcards()->build())->toBe([
        'field' => $field,
        'operator' => $operator,
        'segments' => $segments,
        'type' => 'wildcard',
    ]);
})->with([
    ['nike*', null, '=', ['nike', '']],
    ['*fee', null, '=', ['', 'fee']],
    ['co*fee', null, '=', ['co', 'fee']],
    ['a**b', null, '=', ['a', 'b']],
    ['***', null, '=', ['', '']],
    ['"a b"*', null, '=', ['a b', '']],
    ['name:nike*', 'name', '=', ['nike', '']],
    ['name:!=*fee', 'name', '!=', ['', 'fee']],
    ['name!=co*fee', 'name', '!=', ['co', 'fee']],
    ['name:"Co"*', 'name', '=', ['Co', '']],
    ["name:'Co'*", 'name', '=', ['Co', '']],
    ['name:"a*b"*', 'name', '=', ['a*b', '']],
    ['name:""*', 'name', '=', ['', '']],
]);

it('casts and validates quoted range bounds', function (): void {
    $range = SearchParser::query('x:["1" TO "2"]')
        ->allowedFields([Field::integer('x')->between(1, 2)])
        ->parse();

    expect($range->getFrom())->toBe(1)->and($range->getTo())->toBe(2);
});

it('casts range bounds using the field type', function (): void {
    expect(
        SearchParser::query('price:[5.00 TO 10.50]')
            ->allowedFields([Field::decimal('price', 2)->max(11)])
            ->build(),
    )->toBe(
        [
            'field' => 'price',
            'from' => '5.00',
            'to' => '10.50',
            'type' => 'range',
        ],
    );
});

it('counts every expanded leaf but keeps literal lists as one clause', function (): void {
    expect(
        SearchParser::query('name:a,b,c')->allowAnyField()->maxClauses(1)->build()['type'],
    )->toBe(
        'in',
    )
        ->and(
            SearchParser::query('name:a,b*,c')->allowAnyField()->maxClauses(3)->build()['type'],
        )->toBe(
            'binary',
        );
});

it('counts lists, ranges, comparisons and exists queries once each', function (): void {
    $parser = SearchParser::query('a:1,2,3 b:[1 TO 3] c:1 d:*')->allowAnyField()->maxClauses(4);

    expect($parser->parse()->getOperator())->toBe('AND');
});

it('distinguishes open bounds from quoted literal asterisks', function (
    string $query,
    null|string $from,
    null|string $to,
): void {
    $parser = SearchParser::query($query)->allowAnyField();
    $range = $parser->parse();

    expect($range->getFrom())->toBe($from)
        ->and($range->getTo())->toBe($to)
        ->and($parser->build())->toBe([
            'field' => 'x',
            'from' => $from,
            'to' => $to,
            'type' => 'range',
        ])
        ->and(SearchParser::query($parser->build('string'))->allowAnyField()->build())->toBe(
            $parser->build(),
        );
})->with([
    ['x:[* TO *]', null, null],
    ['x:["*" TO *]', '*', null],
    ['x:[* TO "*"]', null, '*'],
    ['x:["*" TO "*"]', '*', '*'],
    ['x:["a*b" TO "c*d"]', 'a*b', 'c*d'],
]);

it('does not ignore wildcard patterns existence checks or bare terms', function (
    string $query,
    string $expected,
): void {
    expect(SearchParser::query($query)->allowedFields([
        Field::string('status')->ignore('ALL', 'ALL*', '*'),
    ])->build('string'))->toBe($expected);
})->with([
    ['status:ALL*', 'status:ALL*'],
    ['status:*', 'status:*'],
    ['NOT status:*', 'NOT status:*'],
    ['ALL', 'ALL'],
    ['ALL*', 'ALL*'],
]);

it('does not validate ignored values in any scalar value context', function (string $query): void {
    $field = Field::callback('status', static function (): never {
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

it('expands mixed lists that pass array item and collection rules', function (): void {
    $field = Field::array('name')
        ->size(2)
        ->each(
            fn (FieldItemRules $rules): FieldItemRules => $rules->string()->max(3),
        );

    expect(SearchParser::query('name:jo*,ann')->allowedFields([$field])->build()['operator'])->toBe(
        'OR',
    );
});

it('expands mixed lists with the correct connective', function (
    string $operator,
    string $connective,
): void {
    $result = SearchParser::query('name:'.$operator.'jo*,"literal*",42')->allowAnyField()->build();
    $leafOperator = $operator === '' ? '=' : $operator;

    expect($result)->toBe([
        'left' => [
            'left' => [
                'field' => 'name',
                'operator' => $leafOperator,
                'segments' => ['jo', ''],
                'type' => 'wildcard',
            ],
            'operator' => $connective,
            'right' => [
                'default' => false,
                'field' => 'name',
                'match' => 'exact',
                'operator' => $leafOperator,
                'quoted' => true,
                'type' => 'comparison',
                'value' => 'literal*',
            ],
            'type' => 'binary',
        ],
        'operator' => $connective,
        'right' => [
            'default' => false,
            'field' => 'name',
            'match' => 'exact',
            'operator' => $leafOperator,
            'quoted' => false,
            'type' => 'comparison',
            'value' => 42,
        ],
        'type' => 'binary',
    ]);
})->with([['', 'OR'], ['!=', 'AND']]);

it('extracts directives through nested AND groups while preserving filter semantics', function (): void {
    $parser = SearchParser::query('((mode:only AND coffee) AND (tea OR milk))')->allowedFields([
        Field::in('mode', ['only', 'without'])->directive(),
    ]);

    expect($parser->build())->toBe(SearchParser::query('coffee AND (tea OR milk)')->build())
        ->and($parser->getDirectives())->toBe(['mode' => 'only'])
        ->and($parser->getTypedDirectives())->toBe(['mode' => 'only']);
});

it('filters ignored list items while retaining an InNode even for one remaining item', function (
    string $query,
    array $expected,
    string $operator,
): void {
    $node = SearchParser::query($query)->allowedFields([
        Field::in('status', ['active', 'draft'])->ignore('ALL'),
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
        Field::string('status')->ignore('ALL', 'act*'),
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

it('gives every expanded wildcard list node the whole clause span', function (
    string $operator,
): void {
    $query = '  name:'.$operator.'café*,bob,"🙂"*  ';
    $node = SearchParser::query($query)->allowAnyField()->parse();
    $span = new Span(2, mb_strlen($query) - 4);

    expect($node->getSpan())->toEqual($span)
        ->and($node->getLeft()->getSpan())->toEqual($span)
        ->and($node->getLeft()->getLeft()->getSpan())->toEqual($span)
        ->and($node->getLeft()->getRight()->getSpan())->toEqual($span)
        ->and($node->getRight()->getSpan())->toEqual($span);
})->with(['', '!=']);

it('handles ignored compact bounds exactly like ignored bracket bounds', function (
    string $query,
    mixed $from,
    mixed $to,
    bool $removed,
): void {
    $node = SearchParser::query($query)
        ->allowedFields([Field::integer('x')->ignore(0, 10)])
        ->parse();

    if ($removed) {
        expect($node)->toBeNull();

        return;
    }

    expect($node->getFrom())->toBe($from)->and($node->getTo())->toBe($to);
})->with([
    ['x:0..5', null, 5, false], ['x:5..10', 5, null, false], ['x:0..10', null, null, true],
    ['x:*..10', null, null, false],
]);

it('keeps inner group spans and includes closing parentheses in outer nodes', function (): void {
    $node = SearchParser::query('NOT (a OR b) AND (c)')->parse();

    expect($node->getSpan())->toEqual(new Span(0, 20))
        ->and($node->getLeft()->getSpan())->toEqual(new Span(0, 12))
        ->and($node->getLeft()->getOperand()->getSpan())->toEqual(new Span(5, 6))
        ->and($node->getLeft()->getOperand()->getLeft()->getSpan())->toEqual(new Span(5, 1))
        ->and($node->getRight()->getSpan())->toEqual(new Span(18, 1));
});

it('keeps negation above AND and AND above OR', function (): void {
    $ast = SearchParser::query('a OR b c AND NOT d')->parse();

    expect($ast->getOperator())->toBe('OR')
        ->and($ast->getRight()->getOperator())->toBe('AND')
        ->and($ast->getRight()->getLeft()->getOperator())->toBe('AND')
        ->and($ast->getRight()->getRight()->getOperator())->toBe('NOT')
        ->and(SearchParser::query('(a OR b) c')->parse()->getLeft()->getOperator())->toBe('OR');
});

it('keeps negation and surviving binary subtrees with their original spans', function (): void {
    $query = 'NOT (status:ALL OR (title:x AND title:y))';
    $parser = SearchParser::query($query)->allowedFields([
        'title',
        Field::string('status')->ignore('ALL'),
    ]);
    $node = $parser->parse();

    expect($parser->build('string'))->toBe('NOT (title:x AND title:y)')
        ->and($node->getSpan())->toEqual(new Span(0, mb_strlen($query)))
        ->and($node->getOperand()->getSpan())->toEqual(new Span(20, 19))
        ->and($node->getOperand()->getLeft()->getSpan())->toEqual(new Span(20, 7));
});

it('keeps ordinary text trailing and infix patterns quoted asterisks and exists unaffected', function (
    string $query,
): void {
    expect(SearchParser::query($query)->allowedFields([
        Field::string('name')->minWildcardLength(3),
    ])->parse())->not->toBeNull();
})->with(['name:son*', 'name:s*on', 'name:"*son"', 'name:"*"', 'name:*', 'name:x']);

it('keeps punctuation and unicode values intact', function (
    string $input,
    string $value,
): void {
    expect(SearchParser::query($input)->allowAnyField()->build())->toBe([
        'default' => false,
        'field' => mb_strstr($input, ':', true),
        'match' => 'exact',
        'operator' => '=',
        'quoted' => str_contains($input, '"'),
        'type' => 'comparison',
        'value' => $value,
    ]);
})->with([
    ['nombre:José', 'José'],
    ['niño:José', 'José'],
    ['title:t-shirt', 't-shirt'],
    ['sku:ABC-123', 'ABC-123'],
    ['email:john@example.com', 'john@example.com'],
    ['title:-foo', '-foo'],
    ['title:and', 'and'],
    ['title:OR', 'OR'],
    ['title:Not', 'Not'],
]);

it('keeps quoted wildcard text literal', function (string $query, array $expected): void {
    expect(SearchParser::query($query)->allowAnyField()->build())->toBe($expected);
})->with([
    ['"co*fee"', ['quoted' => true, 'type' => 'term', 'value' => 'co*fee']],
    [
        'name:"*"',
        [
            'default' => false,
            'field' => 'name',
            'match' => 'exact',
            'operator' => '=',
            'quoted' => true,
            'type' => 'comparison',
            'value' => '*',
        ],
    ],
    [
        'name:"nike*"',
        [
            'default' => false,
            'field' => 'name',
            'match' => 'exact',
            'operator' => '=',
            'quoted' => true,
            'type' => 'comparison',
            'value' => 'nike*',
        ],
    ],
    ['name:*', ['default' => false, 'field' => 'name', 'type' => 'exists']],
]);

it('keeps text attached to a datetime in the same value', function (string $value): void {
    expect(SearchParser::query('file:'.$value)->allowAnyField()->parse()->getValue())->toBe($value);
})->with(['2025-01-01T12:30:00-backup', '2025-01-01T12:30Zfoo', '2025-01-01T12:30:45+05:30abc']);

it('keeps unquoted * range bounds open without casting or validating them', function (
    string $query,
    null|int $from,
    null|int $to,
): void {
    expect(
        SearchParser::query($query)->allowedFields([Field::integer('x')->max(100)])->build(),
    )->toBe(
        [
            'field' => 'x',
            'from' => $from,
            'to' => $to,
            'type' => 'range',
        ],
    );
})->with([
    ['x:[* TO 50]', null, 50],
    ['x:[10 TO *]', 10, null],
    ['x:[* TO *]', null, null],
]);

it('lexes relative dates in every field position', function (string $value): void {
    $fields = [Field::date('created')];

    foreach (['=', '!=', '>', '>=', '<', '<='] as $operator) {
        $written = $operator === '=' ? '' : $operator;
        $node = SearchParser::query('created:'.$written.$value)->allowedFields($fields)->parse();

        expect($node->getValue())->toBe($value)->and($node->getOperator())->toBe($operator);
    }

    $range = SearchParser::query('created:['.$value.' TO '.$value.']')
        ->allowedFields($fields)
        ->parse();

    expect(
        SearchParser::query('created:'.$value.',now,'.$value)
            ->allowedFields($fields)
            ->parse()
            ->getValues(),
    )
        ->toBe([$value, 'now', $value])
        ->and($range->getFrom())->toBe($value)
        ->and($range->getTo())->toBe($value);
})->with(['-7d', '+3d', 'today', 'now-1h', 'ToMoRrOw+1M']);

it('never resolves quoted aliases inside lists or either range bound', function (): void {
    $field = Field::string('x')->alias('yes', 'resolved');

    expect(SearchParser::query('x:"yes",yes')->allowedFields([$field])->parse()->getValues())
        ->toBe(['yes', 'resolved'])
        ->and(SearchParser::query('x:[yes TO "yes"]')->allowedFields([$field])->parse()->getTo())
        ->toBe('yes');
});

it('parses boolean identifier values into booleans', function (): void {
    $astTrue = SearchParser::query('flag:true')->allowAnyField()->parse();
    $astFalse = SearchParser::query('flag:false')->allowAnyField()->parse();

    expect($astTrue)->toBeInstanceOf(ComparisonNode::class)
        ->getField()->toBe('flag')
        ->getOperator()->toBe('=')
        ->getValue()->toBeTrue()
        ->and($astFalse)->toBeInstanceOf(ComparisonNode::class)
        ->getField()->toBe('flag')
        ->getOperator()->toBe('=')
        ->getValue()->toBeFalse();
});

it('parses exists queries', function (): void {
    $ast = SearchParser::query('category:*')->allowAnyField()->parse();

    expect($ast)->toBeInstanceOf(ExistsNode::class)->getField()->toBe('category');
});

it('parses field comparisons', function (): void {
    $ast = SearchParser::query('price:>10')->allowAnyField()->parse();

    expect($ast)->toBeInstanceOf(ComparisonNode::class)
        ->getField()->toBe('price')
        ->getOperator()->toBe('>')
        ->getValue()->toBe(10);
});

it('parses field comparisons without colon', function (): void {
    $ast = SearchParser::query('price>10')->allowAnyField()->parse();

    expect($ast)->toBeInstanceOf(ComparisonNode::class)
        ->getField()->toBe('price')
        ->getOperator()->toBe('>')
        ->getValue()->toBe(10);
});

it('parses float numbers into floats', function (): void {
    $ast = SearchParser::query('score:10.5')->allowAnyField()->parse();

    expect($ast)->toBeInstanceOf(ComparisonNode::class)
        ->getField()->toBe('score')
        ->getOperator()->toBe('=')
        ->getValue()->toBe(10.5);
});

it('parses implicit AND at AST level', function (): void {
    $ast = SearchParser::query('title:Coffee price:<10')->allowAnyField()->parse();
    $left = $ast->getLeft();
    $right = $ast->getRight();

    expect($ast)->toBeInstanceOf(BinaryOperatorNode::class)
        ->getOperator()->toBe('AND')
        ->and($left)->toBeInstanceOf(ComparisonNode::class)
        ->getField()->toBe('title')
        ->getOperator()->toBe('=')
        ->getValue()->toBe('Coffee')
        ->and($right)->toBeInstanceOf(ComparisonNode::class)
        ->getField()->toBe('price')
        ->getOperator()->toBe('<')
        ->getValue()->toBe(10);
});

it('parses in lists with comma-separated values', function (): void {
    $ast = SearchParser::query('status:A,B')->allowAnyField()->parse();

    expect($ast)->toBeInstanceOf(InNode::class)
        ->getField()->toBe('status')
        ->getOperator()->toBe('=')
        ->getValues()->toBe(['A', 'B']);
});

it('parses in-list with not-equal operator', function (): void {
    $ast = SearchParser::query('status:!=A,B')->allowAnyField()->parse();

    expect($ast)->toBeInstanceOf(InNode::class)
        ->getField()->toBe('status')
        ->getOperator()->toBe('!=')
        ->getValues()->toBe(['A', 'B']);
});

it('parses negative relative values in ranges', function (): void {
    $fields = [Field::date('created')];
    $range = SearchParser::query('created:[-30d TO now]')->allowedFields($fields)->parse();

    expect($range->getFrom())->toBe('-30d')->and($range->getTo())->toBe('now');
});

it('parses NOT modifier and minus as unary', function (): void {
    $notAst = SearchParser::query('NOT title:Coffee')->allowAnyField()->parse();
    $minusAst = SearchParser::query('-title:Coffee')->allowAnyField()->parse();

    foreach ([$notAst, $minusAst] as $ast) {
        expect($ast)->toBeInstanceOf(UnaryOperatorNode::class)->getOperator()->toBe('NOT');

        $operand = $ast->getOperand();

        expect($operand)->toBeInstanceOf(ComparisonNode::class)
            ->getField()->toBe('title')
            ->getOperator()->toBe('=')
            ->getValue()->toBe('Coffee');
    }
});

it('parses numeric and date ranges', function (): void {
    $numeric = SearchParser::query('price:[10 TO 50]')->allowAnyField()->parse();
    $date = SearchParser::query('date:[2025-01-01 TO 2025-12-31]')->allowAnyField()->parse();

    expect($numeric)->toBeInstanceOf(RangeNode::class)
        ->getField()->toBe('price')
        ->getFrom()->toBe(10)
        ->getTo()->toBe(50)
        ->and($date)->toBeInstanceOf(RangeNode::class)
        ->getField()->toBe('date')
        ->getFrom()->toBe('2025-01-01')
        ->getTo()->toBe('2025-12-31');
});

it('parses standalone boolean terms as lowercase strings', function (): void {
    $trueTerm = SearchParser::query('true')->parse();
    $falseTerm = SearchParser::query('false')->parse();

    expect($trueTerm)->toBeInstanceOf(TermNode::class)
        ->getValue()->toBe('true')
        ->and($falseTerm)->toBeInstanceOf(TermNode::class)
        ->getValue()->toBe('false');
});

it('parses standalone term identifiers and numbers', function (): void {
    $termString = SearchParser::query('Coffee')->parse();
    $termInt = SearchParser::query('123')->parse();
    $termFloat = SearchParser::query('12.5')->parse();

    expect($termString)->toBeInstanceOf(TermNode::class)
        ->getValue()->toBe('Coffee')
        ->and($termInt)->toBeInstanceOf(TermNode::class)
        ->getValue()->toBe('123')
        ->and($termFloat)->toBeInstanceOf(TermNode::class)
        ->getValue()->toBe('12.5');
});

it('parses string ranges', function (): void {
    $parser = SearchParser::query('title:["A" TO "Z"]')->allowAnyField();
    $ast = $parser->parse();

    expect($ast)->toBeInstanceOf(RangeNode::class)
        ->getField()->toBe('title')
        ->getFrom()->toBe('A')
        ->getTo()->toBe('Z');
});

it('parses wildcard suffix on quoted strings', function (): void {
    $ast = SearchParser::query('title:"Co"*')->allowAnyField()->parse();

    expect($ast)->toBeInstanceOf(WildcardNode::class)
        ->getField()->toBe('title')
        ->getOperator()->toBe('=')
        ->getSegments()->toBe(['Co', '']);
});

it('parses wildcard suffix on values', function (): void {
    $ast = SearchParser::query('brand:nike*')->allowAnyField()->parse();

    expect($ast)->toBeInstanceOf(WildcardNode::class)
        ->getField()->toBe('brand')
        ->getOperator()->toBe('=')
        ->getSegments()->toBe(['nike', '']);
});

it('preserves adjacency for negative values after unicode and escaped quotes', function (
    string $prefix,
): void {
    expect(
        SearchParser::query($prefix.' temp:>-5')->allowAnyField()->parse()->getRight()->getValue(),
    )->toBe(
        -5,
    );
})->with(['nombre:José', 'title:"a\\\"b"']);

it('preserves literal text when either side is absent or the value is quoted', function (
    string $query,
    string $value,
): void {
    expect(SearchParser::query($query)->allowAnyField()->parse()->getValue())->toBe($value);
})->with([
    ['path:../x', '../x'], ['title:...', '...'], ['x:1..', '1..'], ['x:..2', '..2'],
    ['x:"1..2"', '1..2'], ['"1..2"', '1..2'], ['1..2', '1..2'],
]);

it('preserves quotes on cast scalar values', function (
    Field $field,
    string $query,
    string $expected,
): void {
    $parser = SearchParser::query($query)->allowedFields([$field]);

    expect($parser->build('string'))->toBe($expected)
        ->and(SearchParser::query($expected)->allowedFields([$field])->build())->toBe(
            $parser->build(),
        );
})->with([
    [Field::integer('x'), 'x:"2"', 'x:"2"'],
    [Field::numeric('x'), 'x:"1.50"', 'x:"1.5"'],
    [Field::boolean('x'), 'x:"TRUE"', 'x:"true"'],
]);

it('removes ignored comparisons and simplifies boolean expressions without moving surviving spans', function (
    string $query,
): void {
    $parser = SearchParser::query($query)->allowedFields([
        'title',
        Field::in('status', ['active'])->ignore('ALL'),
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

it('removes ignored directives after casting and aliases and suppresses their defaults', function (
    string $query,
): void {
    $parser = SearchParser::query($query)->allowedFields([
        Field::integer('limit')->directive()->min(1)->ignore(0)->alias('any', 0)->default(20),
    ]);

    expect($parser->build('string'))->toBeNull()
        ->and($parser->getDirectives())->toBeEmpty()
        ->and($parser->getTypedDirectives())->toBeEmpty();
})->with(['limit:0', 'limit:any']);

it('removes ranges only when both bounds are ignored values', function (): void {
    expect(SearchParser::query('title:x AND age:[ALL TO ALL]')->allowedFields([
        'title',
        Field::integer('age')->ignore('ALL'),
    ])->build('string'))->toBe('title:x');
});

it('reserves TO only as the separator inside a range', function (): void {
    $ast = SearchParser::query('go to market')->parse();

    expect($ast->getLeft()->getRight()->getValue())->toBe('to')
        ->and(SearchParser::query('title:TO')->allowAnyField()->parse()->getValue())->toBe('TO');
});

it('resolves aliases in lists and range bounds', function (): void {
    $field = Field::integer('x')->alias('@me', 7)->alias('@max', static fn (): int => 10);
    $list = SearchParser::query('x:@me,@max')->allowedFields([$field])->parse();
    $range = SearchParser::query('x:[@me TO @max]')->allowedFields([$field])->parse();

    expect($list->getValues())->toBe([7, 10])
        ->and($range->getFrom())->toBe(7)->and($range->getTo())->toBe(10);
});

it('retains literal quotes in an expanded wildcard list', function (): void {
    $parser = SearchParser::query('title:all,mu*,"cup",plate')->allowedFields([
        Field::string('title')->ignore('all'),
    ]);
    $node = $parser->parse();

    expect($node->getLeft()->getRight()->isQuoted())->toBeTrue()
        ->and($node->getRight()->isQuoted())->toBeFalse()
        ->and($parser->build('string'))->toBe('title:mu* OR title:"cup" OR title:plate');
});

it('retains per-item quotes while renumbering a list after ignored values', function (): void {
    $parser = SearchParser::query('title:all,mug,"cup",ALL,plate')->allowedFields([
        Field::string('title')->ignore('all'),
    ]);
    $node = $parser->parse();

    expect($node->getValues())->toBe(['mug', 'cup', 'plate'])
        ->and([$node->isQuoted(0), $node->isQuoted(1), $node->isQuoted(2)])->toBe([
            false,
            true,
            false,
        ])
        ->and($parser->build()['quoted'])->toBe([false, true, false])
        ->and($parser->build('string'))->toBe('title:mug,"cup",plate');
});

it('scopes leading wildcard permission to the field or unconfigured input', function (): void {
    expect(SearchParser::query('name:*son')->allowedFields([
        Field::string('name')->allowLeadingWildcards(),
    ])->parse()->getSegments())->toBe(['', 'son'])
        ->and(
            SearchParser::query('*son name:*son')
                ->allowAnyField()
                ->allowLeadingWildcards()
                ->build()['operator'],
        )->toBe(
            'AND',
        );
});

it('spans the exact source consumed by each parsed node kind', function (
    string $query,
    string $type,
    int $offset,
    int $length,
): void {
    $node = SearchParser::query($query)->allowAnyField()->parse();

    expect($node->getType())->toBe($type)
        ->and($node->getSpan())->toEqual(new Span($offset, $length));
})->with([
    ['title:Coffee', 'Comparison', 0, 12],
    ['  title:Coffee  ', 'Comparison', 2, 12],
    ['"Cold Brew"', 'Term', 0, 11],
    ['  café  ', 'Term', 2, 4],
    ["\u{2003}🙂e\u{0301}", 'Term', 1, 3],
    ['name:"a\\"b"', 'Comparison', 0, 11],
    ['âge:>-5', 'Comparison', 0, 7],
    ['name:*', 'Exists', 0, 6],
    ['name:a,"b c"', 'In', 0, 12],
    ['âge:[-5 TO 10]', 'Range', 0, 14],
    ['"café noir"*', 'Wildcard', 0, 12],
    ['name:"café noir"*', 'Wildcard', 0, 17],
    ['NOT (café OR 🙂)', 'UnaryOperator', 0, 15],
    ['-(café)', 'UnaryOperator', 0, 7],
    ['(café) AND (🙂)', 'BinaryOperator', 0, 14],
    [' a OR b ', 'BinaryOperator', 1, 6],
    [' ( café ) ', 'Term', 3, 4],
]);

it('starts surviving AND and OR spans at the first remaining operand', function (
    string $query,
    int $offset,
    int $length,
): void {
    $fields = [Field::string('status')->ignore('ALL'), Field::string('title')];
    $ast = SearchParser::query($query)->allowedFields($fields)->parse();
    $node = $ast instanceof UnaryOperatorNode ? $ast->getOperand() : $ast;

    expect($node->getSpan()?->offset)->toBe($offset)->and($node->getSpan()?->length)->toBe($length);
})->with([
    'implicit AND' => ['status:ALL title:x title:y', 11, 15],
    'explicit AND' => ['status:ALL AND title:x AND title:y', 15, 19],
    'OR inside NOT' => ['NOT (status:ALL OR title:x OR title:y)', 19, 18],
]);

it('supports partial dates in lists and both range bounds', function (): void {
    $fields = [Field::date('created')->allowPartialDates()];
    $range = SearchParser::query('created:[2023 TO 2024-06]')->allowedFields($fields)->parse();

    expect(
        SearchParser::query('created:2024,2024-03')->allowedFields($fields)->parse()->getValues(),
    )
        ->toBe(['2024', '2024-03'])
        ->and($range->getFrom())->toBe('2023')
        ->and($range->getTo())->toBe('2024-06');
});

it('treats lowercase and mixed-case connectives as ordinary terms', function (
    string $query,
    string $normalized,
): void {
    expect(SearchParser::query($query)->allowAnyField()->build('string'))->toBe($normalized);
})->with([
    ['rock and roll', 'rock AND and AND roll'],
    ['this is not good', 'this AND is AND not AND good'],
    ['a Or b', 'a AND Or AND b'],
    ['and OR not', 'and OR not'],
    ['name:AND', 'name:AND'],
]);

it('turns ignored range bounds into open bounds without changing the range span', function (
    string $query,
    null|int $from,
    null|int $to,
): void {
    $node = SearchParser::query($query)->allowedFields([
        Field::integer('age')->between(1, 10)->ignore('ALL'),
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

it('uses field rules for decimals partial dates relative dates and aliases', function (): void {
    expect(SearchParser::query('price:1.50..2.00')->allowedFields([
        Field::decimal('price', 2)->scale(100),
    ])->build())->toBe(['field' => 'price', 'from' => 150, 'to' => 200, 'type' => 'range'])
        ->and(SearchParser::query('created:2024..2024-06')->allowedFields([
            Field::date('created')->allowPartialDates(),
        ])->parse()->getFrom())->toBe('2024')
        ->and(SearchParser::query('created:-30d..now')->allowedFields([
            Field::date('created'),
        ])->parse()->getTo())->toBe('now')
        ->and(SearchParser::query('price:low..high')->allowedFields([
            Field::integer('price')->alias('low', 1)->alias('high', 5),
        ])->parse()->getTo())->toBe(5);
});

it('validates array collections and items only after filtering ignored values', function (): void {
    $parser = SearchParser::query('tags:ALL,active,ALL')->allowedFields([
        Field::array('tags')->ignore('ALL')->size(1)->each(
            fn (FieldItemRules $rules): FieldItemRules => $rules->in(['active']),
        ),
    ]);

    expect($parser->parse()->getValues())->toBe(['active']);
});

it('validates remaining list items with the index rules of their written positions', function (): void {
    $field = Field::array('pair')
        ->ignore(0)
        ->at(
            0,
            fn (FieldItemRules $rules): FieldItemRules => $rules->integer()->max(10),
        )
        ->at(
            1,
            fn (FieldItemRules $rules): FieldItemRules => $rules->integer()->min(100),
        );

    expect(SearchParser::query('pair:0,200')->allowedFields([$field])->parse()->getValues())->toBe([
        200,
    ]);
});
