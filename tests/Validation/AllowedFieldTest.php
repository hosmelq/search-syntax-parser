<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\AST\Node\NodeInterface;
use HosmelQ\SearchSyntaxParser\Exception\ErrorReason;
use HosmelQ\SearchSyntaxParser\Exception\InvalidFieldException;
use HosmelQ\SearchSyntaxParser\Exception\InvalidFieldOperatorException;
use HosmelQ\SearchSyntaxParser\Exception\InvalidFieldValueException;
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Tests\Fixtures\PriorityLevel;
use HosmelQ\SearchSyntaxParser\Tests\Fixtures\PublicationStatus;
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;
use HosmelQ\SearchSyntaxParser\Validation\AllowedFieldItemRules;
use HosmelQ\SearchSyntaxParser\Validation\TextMatch;

it('applies pattern value casts validation aliases ignores and storage conversions', function (): void {
    $field = AllowedField::integer('metrics.*', 'counters.*')
        ->keys(fn (string $key): bool => in_array($key, ['views', 'likes'], true))
        ->alias('all', 2)
        ->min(1)
        ->ignore(0)
        ->scale(100);

    expect(
        SearchParser::query('metrics.views:all')->allowedFields([$field])->build()['value'],
    )->toBe(
        200,
    )
        ->and(
            SearchParser::query('metrics.likes:0,2')->allowedFields([$field])->build()['values'],
        )->toBe(
            [200],
        )
        ->and(SearchParser::query('metrics.views:0..2')->allowedFields([$field])->build())->toBe([
            'field' => 'counters.views',
            'from' => null,
            'to' => 200,
            'type' => 'range',
        ]);

    SearchParser::query('metrics.likes:bad')->allowedFields([$field])->parse();
})->throws(InvalidFieldValueException::class);

it('compares numeric-looking strings by value on untyped fields', function (string $value): void {
    SearchParser::query('age:'.$value)->allowedFields([new AllowedField('age')->max(100)])->build();
})->throws(InvalidFieldValueException::class)->with([
    '150',
    '0150',
    '"150"',
    '1e5',
    '99999999999999999999',
]);

it('counts literal Unicode characters across all wildcard segments', function (
    string $query,
    int $minimum,
    bool $valid,
    int $position,
): void {
    $parser = SearchParser::query($query)->allowedFields([
        AllowedField::string('name')->allowLeadingWildcards()->minWildcardLength($minimum),
    ]);

    if ($valid) {
        expect($parser->parse())->not->toBeNull();

        return;
    }

    try {
        $parser->parse();

        test()->fail('Expected a wildcard length error.');
    } catch (InvalidFieldOperatorException $invalidFieldOperatorException) {
        expect($invalidFieldOperatorException->getField())->toBe('name')
            ->and($invalidFieldOperatorException->getReason())->toBe(ErrorReason::WildcardTooShort)
            ->and($invalidFieldOperatorException->getParameters())->toBe([
                'field' => 'name',
                'minimum' => $minimum,
            ])
            ->and($invalidFieldOperatorException->getPosition())->toBe($position);
    }
})->with([
    ['name:café*', 4, true, 5],
    ['name:café*', 5, false, 5],
    ['name:a**b', 2, true, 5],
    ['name:a**b', 3, false, 5],
    ['name:valid,é*', 2, false, 11],
    ['name:*son*', 3, true, 5],
    ['name:***', 1, false, 5],
    ['name:"a*b"*', 3, true, 5],
]);

it('disables every comparison operator when the configured subset is empty', function (): void {
    SearchParser::query('status:active')->allowedFields([
        AllowedField::string('status')->operators(),
    ])->parse();
})->throws(InvalidFieldOperatorException::class, "Operator '=' is not allowed for field 'status'.");

it('does not cast alias results to satisfy field types', function (): void {
    SearchParser::query('x:token')->allowedFields([
        AllowedField::string('x')->alias('token', 7),
    ])->parse();
})->throws(InvalidFieldValueException::class);

it('ignores and validates user values before storage conversion including defaults', function (): void {
    $field = AllowedField::integer('amount')->max(10)->ignore('all', 0)->scale(100)->default('5');

    expect(SearchParser::query('amount:ALL,5,0')->allowedFields([$field])->build()['values'])->toBe(
        [500],
    )
        ->and(
            SearchParser::query('amount:[ALL TO 5]')->allowedFields([$field])->build()['from'],
        )->toBeNull()
        ->and(SearchParser::query('')->allowedFields([$field])->parse()->getValue())->toBe(5)
        ->and(SearchParser::query('')->allowedFields([$field])->build()['value'])->toBe(500)
        ->and(fn (): mixed => SearchParser::query('amount:11')->allowedFields([$field])->build())
        ->toThrow(InvalidFieldValueException::class);
});

it('inherits shared item types when index rules only constrain size', function (): void {
    $field = AllowedField::array('values')
        ->at(0, fn (AllowedFieldItemRules $rules): AllowedFieldItemRules => $rules->max(100))
        ->each(fn (AllowedFieldItemRules $rules): AllowedFieldItemRules => $rules->decimal(2));

    expect(
        fn (): mixed => SearchParser::query('values:150.00')->allowedFields([$field])->build(),
    )->toThrow(
        InvalidFieldValueException::class,
    )
        ->and(
            SearchParser::query('values:50.00')->allowedFields([$field])->parse()->getValue(),
        )->toBe(
            '50.00',
        );
});

it('matches ignored values strictly and validates nullability separately', function (): void {
    $fieldWithIgnoredValues = AllowedField::integer('count')->ignore('N/A', null);
    $nullableField = AllowedField::integer('count')->nullable();

    expect($fieldWithIgnoredValues)
        ->isIgnored('N/A')->toBeTrue()
        ->isIgnored(null)->toBeTrue()
        ->isIgnored(0)->toBeFalse()
        ->validate('N/A')->toBeFalse()
        ->validate(null)->toBeFalse()
        ->and($nullableField)
        ->validate(null)->toBeTrue();
});

it('measures numeric types numerically for every size rule', function (
    string $type,
    string $rule,
): void {
    $field = match ($type) {
        'integer' => AllowedField::integer('value'),
        'numeric' => AllowedField::numeric('value'),
        'decimal' => AllowedField::decimal('value', 2),
    };
    $field = match ($rule) {
        'min' => $field->min(100),
        'max' => $field->max(100),
        'between' => $field->between(100, 200),
        'size' => $field->size(150),
    };
    $value = $type === 'decimal' ? '150.00' : '150';

    if ($rule === 'max') {
        expect(
            fn (): mixed
                => SearchParser::query('value:"'.$value.'"')->allowedFields([$field])->build(),
        )->toThrow(
            InvalidFieldValueException::class,
        );
    } else {
        expect(
            SearchParser::query('value:"'.$value.'"')->allowedFields([$field])->parse()->getValue(),
        )->toBe(
            $type === 'decimal' ? $value : 150,
        );
    }
})->with(['integer', 'numeric', 'decimal'])->with(['min', 'max', 'between', 'size']);

it('points array fields to item rules for partial dates', function (): void {
    expect(fn (): AllowedField => AllowedField::array('dates')->allowPartialDates())->toThrow(
        InvalidArgumentException::class,
        'On array fields, allow partial dates on the each() or at() date rules.',
    );
});

it('probes array items with collection shared and positional rules', function (): void {
    $field = AllowedField::array('tuple')
        ->max(2)
        ->each(fn ($rules) => $rules->numeric()->min(0))
        ->at(1, fn ($rules) => $rules->integer()->max(10));

    expect($field->tryCast('2.5'))->toBe(2.5)
        ->and($field->tryCast('-1'))->toBeNull()
        ->and($field->tryCast('7', index: 1))->toBe(7)
        ->and($field->tryCast('2.5', index: 1))->toBeNull()
        ->and($field->tryCast('11', index: 1))->toBeNull()
        ->and(AllowedField::array('tags')->size(2)->tryCast('tag'))->toBeNull();
});

it('probes typed values without turning invalid input into a query error', function (
    AllowedField $field,
    string $value,
    mixed $expected,
): void {
    expect($field->tryCast($value))->toBe($expected);
})->with([
    [AllowedField::integer('id')->min(1), '1042', 1_042],
    [AllowedField::integer('id'), 'abc', null],
    [AllowedField::integer('id'), '1.5', null],
    [AllowedField::integer('id')->min(1), '0', null],
    [AllowedField::numeric('price'), '2.5', 2.5],
    [AllowedField::decimal('price', 2), '2.50', '2.50'],
    [AllowedField::decimal('price', 2), '2.5', null],
    [AllowedField::boolean('active'), 'false', false],
    [AllowedField::integer('count'), '0', 0],
    [AllowedField::string('title'), '', ''],
    [AllowedField::string('code')->size(3), '123', '123'],
    [AllowedField::in('status', ['open', 'closed']), 'invalid', null],
    [AllowedField::enum('priority', PriorityLevel::class), '2', 2],
    [AllowedField::date('date'), '2025-02-30', null],
    [AllowedField::date('date'), '2024-02-29', '2024-02-29'],
    [
        AllowedField::callback(
            'id',
            fn ($value, $name): bool => $name === 'stored_id' && $value === 42,
            'stored_id',
        ),
        '42',
        42,
    ],
]);

it('propagates configuration errors instead of hiding them', function (): void {
    $field = new AllowedField('id')->alias('bad', static fn (): array => []);

    expect(fn (): mixed => $field->tryCast('bad'))->toThrow(
        InvalidArgumentException::class,
        'A value alias must resolve to a bool, float, int, or string.',
    );
});

it('rejects a closure returned by an alias closure', function (): void {
    SearchParser::query('x:token')->allowedFields([
        new AllowedField('x')->alias('token', static fn (): Closure => static fn (): int => 1),
    ])->parse();
})->throws(InvalidArgumentException::class);

it('rejects a negative minimum wildcard length', function (): void {
    AllowedField::string('name')->minWildcardLength(-1);
})->throws(InvalidArgumentException::class);

it('rejects a second storage conversion', function (): void {
    expect(fn (): AllowedField => AllowedField::numeric('amount')->scale(100)->map([1 => 2]))
        ->toThrow(InvalidArgumentException::class, 'A field accepts only one storage conversion.');
});

it('rejects alias closures returning nonscalar values', function (mixed $value): void {
    SearchParser::query('x:token')->allowedFields([
        new AllowedField('x')->alias('token', static fn (): mixed => $value),
    ])->parse();
})->throws(InvalidArgumentException::class)->with([null, [[]], [(object) []]]);

it('rejects alias tokens that contain wildcards', function (): void {
    AllowedField::integer('owner')->alias('a*', 5);
})->throws(InvalidArgumentException::class, 'A value alias token cannot contain a wildcard.');

it('rejects an internal name without a pattern suffix', function (): void {
    AllowedField::string('meta.*', 'attributes');
})->throws(InvalidArgumentException::class);

it('rejects collection and pattern directive declarations and non-scalar defaults in either order', function (
    Closure $configure,
): void {
    $configure();
})->throws(InvalidArgumentException::class)->with([
    fn (): AllowedField => AllowedField::array('mode')->directive(),
    fn (): AllowedField => AllowedField::string('meta.*')->keys('/.*/')->directive(),
    fn (): AllowedField => AllowedField::string('mode')->directive()->default(null),
    fn (): AllowedField => AllowedField::string('mode')->directive()->default(['only']),
    fn (): AllowedField => AllowedField::string('mode')->default(null)->directive(),
    fn (): AllowedField => AllowedField::string('mode')->default(['only'])->directive(),
]);

it('rejects dates without time on other types, custom formats and array fields', function (
    Closure $configure,
): void {
    $configure();
})->throws(InvalidArgumentException::class)->with([
    fn (): AllowedField => AllowedField::string('value')->withoutTime(),
    fn (): AllowedField => AllowedField::array('value')->withoutTime(),
    fn (): AllowedField => AllowedField::date('value', 'Y-m-d')->withoutTime(),
    fn (): AllowedFieldItemRules => new AllowedFieldItemRules()->withoutTime(),
    fn (): AllowedFieldItemRules => new AllowedFieldItemRules()->date('Y-m-d')->withoutTime(),
    fn (): AllowedFieldItemRules => new AllowedFieldItemRules()->date()->withoutTime()->string(),
    fn (): AllowedFieldItemRules
        => new AllowedFieldItemRules()->date()->withoutTime()->date('Y-m-d'),
]);

it('rejects decimal item maxima regardless of rule order', function (
    string $scope,
    bool $sizeFirst,
): void {
    $configure = function (AllowedFieldItemRules $rules) use ($sizeFirst): void {
        if ($sizeFirst) {
            $rules->max(100)->decimal(2);
        } else {
            $rules->decimal(2)->max(100);
        }
    };
    $field = AllowedField::array('values');

    $scope === 'each' ? $field->each($configure) : $field->at(0, $configure);

    SearchParser::query('values:150.00')->allowedFields([$field])->build();
})->throws(InvalidFieldValueException::class)->with(['each', 'at'])->with([true, false]);

it('rejects decimal map keys equal by value in any configuration order', function (
    Closure $field,
): void {
    SearchParser::query('')->allowedFields([$field()]);
})->throws(
    InvalidArgumentException::class,
    "Storage map keys of decimal field 'amount' must be unique by value.",
)->with(
    [
        fn (): AllowedField
            => AllowedField::decimal('amount', 0, 2)->map(['1.5' => 'a', '1.50' => 'b']),
        fn (): AllowedField => AllowedField::decimal('amount', 0, 2)->map([7 => 'a', '7.0' => 'b']),
        fn (): AllowedField => AllowedField::array('amount')
            ->map(['1.5' => 'a', '1.50' => 'b'])
            ->each(
                fn (AllowedFieldItemRules $rules): AllowedFieldItemRules => $rules->decimal(0, 2),
            ),
        fn (): AllowedField => AllowedField::array('amount')
            ->map(['1.5' => 'a', '1.50' => 'b'])
            ->at(
                1,
                fn (AllowedFieldItemRules $rules): AllowedFieldItemRules => $rules->decimal(0, 2),
            ),
    ],
);

it('rejects defaults on pattern fields including null defaults', function (mixed $value): void {
    AllowedField::string('meta.*')->keys('/^.+$/D')->default($value);
})->throws(InvalidArgumentException::class, 'cannot have defaults')->with([
    null,
    'blue',
    [['red', 'blue']],
]);

it('rejects empty and wildcard keys even when any field is allowed', function (
    string $query,
): void {
    expect(fn (): mixed => SearchParser::query($query)->allowAnyField()->allowedFields([
        AllowedField::string('meta.*')->keys(static fn (string $key): bool => true),
    ])->build())->toThrow(InvalidFieldException::class);
})->with(['meta.:x', 'meta.*:x', 'meta.a*b:x']);

it('rejects incompatible item type changes after enabling a text mode', function (
    bool $positional,
): void {
    $field = AllowedField::array('x')->each(fn ($rules) => $rules->string())->prefix();

    if ($positional) {
        $field->at(0, fn ($rules) => $rules->integer());
    } else {
        $field->each(fn ($rules) => $rules->integer());
    }
})->throws(InvalidArgumentException::class)->with([false, true]);

it('rejects invalid defaults immediately', function (mixed $default): void {
    AllowedField::string('status')->default($default);
})->throws(InvalidArgumentException::class)->with([
    'empty list' => [[]],
    'associative array' => [['x' => 'value']],
    'sparse list' => [[1 => 'value']],
    'nested list' => [[['value']]],
    'null item' => [[null]],
    'object' => [new stdClass()],
    'object item' => [[new stdClass()]],
]);

it('rejects invalid regexes and key rules on exact fields', function (): void {
    expect(fn (): AllowedField => AllowedField::string('meta.*')->keys('/['))->toThrow(
        InvalidArgumentException::class,
    )
        ->and(fn (): AllowedField => AllowedField::string('meta.color')->keys('/^.+$/D'))->toThrow(
            InvalidArgumentException::class,
        );
});

it('rejects leading field wildcards with their external field and character position', function (
    string $query,
    int $position,
    bool $configured,
): void {
    $parser = SearchParser::query($query);

    $configured
        ? $parser->allowedFields([AllowedField::string('name', 'full_name')])
        : $parser->allowAnyField();

    try {
        $parser->parse();

        test()->fail('Expected a leading wildcard error.');
    } catch (InvalidFieldOperatorException $invalidFieldOperatorException) {
        expect($invalidFieldOperatorException->getField())->toBe('name')
            ->and($invalidFieldOperatorException->getReason())->toBe(
                ErrorReason::LeadingWildcardNotAllowed,
            )
            ->and($invalidFieldOperatorException->getParameters())->toBe(['field' => 'name'])
            ->and($invalidFieldOperatorException->getPosition())->toBe($position);
    }
})->with([
    ['name:*son', 5, true],
    ['name:*son*', 5, true],
    ['é name:a,*b', 9, true],
    ['name:!=*son', 7, false],
    ['name:a,*b', 7, false],
    ['name:""*', 5, true],
]);

it('rejects leading wildcards on whole-word fields in either configuration order', function (
    Closure $field,
): void {
    SearchParser::query('')->allowedFields([$field()]);
})->throws(
    InvalidArgumentException::class,
    "Whole-word field 'body' cannot allow leading wildcards.",
)->with(
    [
        fn (): AllowedField => AllowedField::string('body')->words()->allowLeadingWildcards(),
        fn (): AllowedField => AllowedField::string('body')->allowLeadingWildcards()->words(),
    ],
);

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

it('rejects other patterns on whole-word fields at the pattern', function (
    string $query,
    int $position,
): void {
    try {
        SearchParser::query($query)
            ->allowedFields([AllowedField::string('body')->words()])
            ->parse();

        test()->fail('Expected a whole-word wildcard error.');
    } catch (InvalidFieldOperatorException $invalidFieldOperatorException) {
        expect($invalidFieldOperatorException->getReason())->toBe(
            ErrorReason::WordWildcardNotAllowed,
        )
            ->and($invalidFieldOperatorException->getField())->toBe('body')
            ->and($invalidFieldOperatorException->getPosition())->toBe($position);
    }
})->with([
    ['body:co*ee', 5],
    ['body:*offee', 5],
    ['body:co**ee*', 5],
    ['body:tea,co*ee', 9],
    ['body:!=co*ee', 7],
]);

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

it('rejects scaling non-numeric field types and invalid factors when configured', function (): void {
    expect(fn (): AllowedField => AllowedField::string('value')->scale(100))->toThrow(
        InvalidArgumentException::class,
    )
        ->and(fn (): AllowedField => AllowedField::integer('value')->scale('invalid'))->toThrow(
            InvalidArgumentException::class,
        );
});

it('rejects storage map keys that collide ignoring case', function (): void {
    expect(fn (): AllowedField => AllowedField::string('priority')->map(['high' => 3, 'HIGH' => 4]))
        ->toThrow(
            InvalidArgumentException::class,
            'Storage map keys must be unique ignoring case.',
        );
});

it('rejects text modes on fields without a string cast type', function (
    AllowedField $field,
    string $mode,
): void {
    $field->$mode();
})->throws(InvalidArgumentException::class)->with([
    new AllowedField('x'), AllowedField::integer('x'), AllowedField::numeric('x'),
    AllowedField::decimal('x', 2), AllowedField::boolean('x'), AllowedField::date('x'),
    AllowedField::in('x', ['text']), AllowedField::uuid('x'), AllowedField::ulid('x'),
    AllowedField::array('x'),
    AllowedField::array('x')->at(0, fn ($rules) => $rules->string()),
    AllowedField::array('x')->each(fn ($rules) => $rules->integer()),
    AllowedField::array('x')
        ->each(fn ($rules) => $rules->string())
        ->at(0, fn ($rules) => $rules->integer()),
])->with(['prefix', 'contains', 'words']);

it('rejects unknown configured operators', function (string $operator): void {
    AllowedField::string('status')->operators('=', $operator);
})->throws(InvalidArgumentException::class)->with(['LIKE', '==', ':', '', 'AND']);

it('rejects unsupported sizes without a TypeError', function (string $rule): void {
    $field = match ($rule) {
        'min' => AllowedField::boolean('flag')->min(0),
        'max' => AllowedField::boolean('flag')->max(1),
        'between' => AllowedField::boolean('flag')->between(0, 1),
        'size' => AllowedField::boolean('flag')->size(1),
    };

    SearchParser::query('flag:true')->allowedFields([$field])->build();
})->throws(InvalidFieldValueException::class)->with(['min', 'max', 'between', 'size']);

it('reports the typed name for pattern operator failures', function (): void {
    try {
        SearchParser::query('meta.color:*blue')->allowedFields([
            AllowedField::string('meta.*')->keys('/^[a-z]+$/D'),
        ])->parse();

        test()->fail('Expected a leading wildcard error.');
    } catch (InvalidFieldOperatorException $invalidFieldOperatorException) {
        expect($invalidFieldOperatorException->getField())->toBe('meta.color')
            ->and($invalidFieldOperatorException->getReason())->toBe(
                ErrorReason::LeadingWildcardNotAllowed,
            )
            ->and($invalidFieldOperatorException->getPosition())->toBe(11);
    }
});

it('retains withoutWildcards when leading patterns are enabled', function (): void {
    SearchParser::query('name:*son')->allowedFields([
        AllowedField::string('name')->allowLeadingWildcards()->withoutWildcards(),
    ])->parse();
})->throws(InvalidFieldOperatorException::class, 'Wildcards are not allowed');

it('sizes shared item rules by the effective item type', function (): void {
    $decimal = AllowedField::array('values')
        ->each(fn (AllowedFieldItemRules $rules): AllowedFieldItemRules => $rules->max(180))
        ->at(0, fn (AllowedFieldItemRules $rules): AllowedFieldItemRules => $rules->decimal(0, 6));
    $string = AllowedField::array('values')
        ->each(fn (AllowedFieldItemRules $rules): AllowedFieldItemRules => $rules->max(3))
        ->at(0, fn (AllowedFieldItemRules $rules): AllowedFieldItemRules => $rules->string());

    expect(
        fn (): mixed => SearchParser::query('values:999.5')->allowedFields([$decimal])->build(),
    )->toThrow(
        InvalidFieldValueException::class,
    )
        ->and(
            SearchParser::query('values:179.5')->allowedFields([$decimal])->parse()->getValue(),
        )->toBe(
            '179.5',
        )
        ->and(
            SearchParser::query('values:12')->allowedFields([$string])->parse()->getValue(),
        )->toBe(
            '12',
        );
});

it('supports date and enum item rules in each and at', function (): void {
    $field = AllowedField::array('values')
        ->each(
            fn (AllowedFieldItemRules $rules): AllowedFieldItemRules
                => $rules->callback(fn (): bool => true),
        )
        ->at(0, fn (AllowedFieldItemRules $rules): AllowedFieldItemRules => $rules->date('d/m/Y'))
        ->at(
            1,
            fn (AllowedFieldItemRules $rules): AllowedFieldItemRules
                => $rules->enum(PriorityLevel::class),
        );

    expect(
        SearchParser::query('values:"29/02/2024",2')->allowedFields([$field])->build()['values'],
    )->toBe(
        ['29/02/2024', 2],
    );

    $dates = AllowedField::array('values')->each(
        fn (AllowedFieldItemRules $rules): AllowedFieldItemRules => $rules->date(),
    );
    $enums = AllowedField::array('values')->each(
        fn (AllowedFieldItemRules $rules): AllowedFieldItemRules
            => $rules->enum(PublicationStatus::class),
    );

    expect(
        SearchParser::query('values:2024-02-29,2025-01-01')
            ->allowedFields([$dates])
            ->build()['values'],
    )
        ->toBe(['2024-02-29', '2025-01-01'])
        ->and(
            SearchParser::query('values:draft,published')
                ->allowedFields([$enums])
                ->build()['values'],
        )
        ->toBe(['draft', 'published'])
        ->and(
            fn (): mixed
                => SearchParser::query('values:2025-02-30')->allowedFields([$dates])->build(),
        )
        ->toThrow(InvalidFieldValueException::class)
        ->and(
            fn (): mixed
                => SearchParser::query('values:draft,other')->allowedFields([$enums])->build(),
        )
        ->toThrow(InvalidFieldValueException::class);
});

it('supports dates without time on shared and positional array item rules', function (): void {
    $shared = AllowedField::array('dates')->each(
        fn (AllowedFieldItemRules $rules): AllowedFieldItemRules => $rules->date()->withoutTime(),
    );
    $tuple = AllowedField::array('dates')
        ->at(
            0,
            fn (AllowedFieldItemRules $rules): AllowedFieldItemRules
                => $rules->date()->withoutTime(),
        )
        ->at(1, fn (AllowedFieldItemRules $rules): AllowedFieldItemRules => $rules->date());

    expect(
        SearchParser::query('dates:today,2024-03-15')
            ->allowedFields([$shared])
            ->parse()
            ->getValues(),
    )
        ->toBe(['today', '2024-03-15'])
        ->and(SearchParser::query('dates:today,now')->allowedFields([$tuple])->parse()->getValues())
        ->toBe(['today', 'now'])
        ->and(fn (): null|NodeInterface => SearchParser::query('dates:now,today')
            ->allowedFields([$tuple])
            ->parse())
        ->toThrow(InvalidFieldValueException::class);
});

it('treats a key closure result other than true as a rejection', function (): void {
    $field = AllowedField::string('meta.*')->keys(static fn (string $key): int => 1);

    expect(fn (): mixed => SearchParser::query('meta.color:red')->allowedFields([$field])->build())
        ->toThrow(InvalidFieldException::class);
});

it('validates index-specific rules and combined rules', function (): void {
    $tuple = AllowedField::array('tuple')
        ->size(2)
        ->each(fn ($rules): AllowedFieldItemRules => $rules->string())
        ->at(0, fn ($rules): AllowedFieldItemRules => $rules->size(3))
        ->at(1, fn ($rules): AllowedFieldItemRules => $rules->max(3));

    expect($tuple->validate(['abcd', 'x']))->toBeFalse()
        ->and($tuple->validate(['abc', 'abcd']))->toBeFalse()
        ->and($tuple->validate(['abc', 'xy']))->toBeTrue();

    $missingIndexField = AllowedField::array('pair')
        ->at(1, fn ($rules): AllowedFieldItemRules => $rules->string()->max(3));

    expect($missingIndexField->validate([123]))->toBeTrue();
});

it('validates per-item rules on arrays', function (): void {
    $arrayWithLimits = AllowedField::array('keywords')
        ->max(2)
        ->each(fn ($rules): AllowedFieldItemRules => $rules->string()->max(5));

    expect($arrayWithLimits->validate(['a', 'b', 'c']))->toBeFalse()
        ->and($arrayWithLimits->validate(['aaaaaa']))->toBeFalse()
        ->and($arrayWithLimits->validate('not-array'))->toBeFalse()
        ->and($arrayWithLimits->validate(['aa', 'bbb']))->toBeTrue();

    $booleanRulesField = AllowedField::array('values')
        ->each(fn ($rules): AllowedFieldItemRules => $rules->boolean());

    expect($booleanRulesField->validate(['x']))->toBeFalse()
        ->and($booleanRulesField->validate([1, '0', true]))->toBeTrue();

    $callbackRulesField = AllowedField::array('values')
        ->each(
            fn ($rules): AllowedFieldItemRules
                => $rules->callback(fn ($value): bool => is_int($value)),
        );

    expect($callbackRulesField->validate(['x']))->toBeFalse()
        ->and($callbackRulesField->validate([1, 2]))->toBeTrue();

    $decimalRulesField = AllowedField::array('values')
        ->each(fn ($rules): AllowedFieldItemRules => $rules->decimal(2));

    expect($decimalRulesField->validate(['1']))->toBeFalse()
        ->and($decimalRulesField->validate(['1.23']))->toBeTrue();

    $inRulesField = AllowedField::array('values')
        ->each(fn ($rules): AllowedFieldItemRules => $rules->in(['A', 'B', '1']));

    expect($inRulesField->validate(['C']))->toBeFalse()
        ->and($inRulesField->validate(['A', '1']))->toBeTrue();

    $integerRulesField = AllowedField::array('values')
        ->each(fn ($rules): AllowedFieldItemRules => $rules->integer()->min(0)->max(10));

    expect($integerRulesField->validate(['x']))->toBeFalse()
        ->and($integerRulesField->validate([5]))->toBeTrue();

    $numericBetweenField = AllowedField::array('values')
        ->each(fn ($rules): AllowedFieldItemRules => $rules->numeric()->between(1, 2));

    expect($numericBetweenField->validate([3]))->toBeFalse()
        ->and($numericBetweenField->validate([1.5]))->toBeTrue();
});

it('accepts only word prefixes as patterns on whole-word fields', function (): void {
    $body = AllowedField::string('body')->words();

    expect(SearchParser::query('body:coff*')->allowedFields([$body])->parse()->getSegments())
        ->toBe(['coff', ''])
        ->and(
            SearchParser::query('body:"coffee ma"*')
                ->allowedFields([$body])
                ->parse()
                ->getSegments(),
        )
        ->toBe(['coffee ma', ''])
        ->and(SearchParser::query('body:coff**')->allowedFields([$body])->parse()->getSegments())
        ->toBe(['coff', ''])
        ->and(
            SearchParser::query('body:co*ee')
                ->allowedFields([AllowedField::string('body')])
                ->parse()
                ->getSegments(),
        )
        ->toBe(['co', 'ee']);
});

it('allows all six comparison operators when configured', function (string $operator): void {
    $queryOperator = $operator === '=' ? '' : $operator;
    $node = SearchParser::query('status:'.$queryOperator.'5')
        ->allowedFields([AllowedField::integer('status')->operators($operator)])
        ->parse();

    expect($node->getOperator())->toBe($operator)->and($node->getValue())->toBe(5);
})->with(['=', '!=', '>', '>=', '<', '<=']);

it('allows text matching on arrays with shared string rules', function (): void {
    $field = AllowedField::array('tags')
        ->each(fn ($rules) => $rules->string())
        ->at(1, fn ($rules) => $rules->max(10))
        ->contains();

    expect(SearchParser::query('tags:mug,"cup"')->allowedFields([$field])->build()['match'])
        ->toBe(['contains', 'exact']);
});

it('calls aliases without arguments at parse time on every parse', function (): void {
    $userId = 7;
    $calls = [];
    $field = AllowedField::integer('owner')->alias(
        '@me',
        static function (...$arguments) use (&$userId, &$calls): int {
            $calls[] = $arguments;

            return $userId;
        },
    );
    $parser = SearchParser::query('owner:@me')->allowedFields([$field]);

    expect($calls)->toBeEmpty()->and($parser->parse()->getValue())->toBe(7);

    $userId = 9;

    expect($parser->parse()->getValue())->toBe(9)->and($calls)->toBe([[], []]);
});

it('casts item strings, booleans and membership values', function (
    Closure $configure,
    string $query,
    array $expected,
): void {
    $field = AllowedField::array('values')->each($configure);

    expect(
        SearchParser::query('values:'.$query)->allowedFields([$field])->parse()->getValues(),
    )->toBe(
        $expected,
    );
})->with([
    [
        fn (AllowedFieldItemRules $rules): AllowedFieldItemRules => $rules->string()->max(4),
        '123,true',
        ['123', 'true'],
    ],
    [
        fn (AllowedFieldItemRules $rules): AllowedFieldItemRules => $rules->boolean(),
        'true,0',
        [true, false],
    ],
    [
        fn (AllowedFieldItemRules $rules): AllowedFieldItemRules => $rules->in(['01', 2]),
        '01,2',
        ['01', 2],
    ],
]);

it('casts negative tuple items with their index rules', function (): void {
    $field = AllowedField::array('location')
        ->size(2)
        ->at(
            0,
            fn (AllowedFieldItemRules $rules): AllowedFieldItemRules
                => $rules->numeric()->between(-90, 90),
        )
        ->at(
            1,
            fn (AllowedFieldItemRules $rules): AllowedFieldItemRules
                => $rules->numeric()->between(-180, 180),
        );

    expect(
        SearchParser::query('location:37.7749,-122.4194')
            ->allowedFields([$field])
            ->parse()
            ->getValues(),
    )->toBe(
        [37.774_9, -122.419_4],
    );
});

it('configures nullability explicitly', function (): void {
    $field = new AllowedField('status');

    expect($field)->isNullable()->toBeFalse()
        ->and($field->nullable())->isNullable()->toBeTrue()
        ->and($field->nullable(false))->isNullable()->toBeFalse();
});

it('converts array items with the rules of their position', function (): void {
    $mapped = AllowedField::array('amount')
        ->each(fn (AllowedFieldItemRules $rules): AllowedFieldItemRules => $rules->decimal(0, 2))
        ->at(0, fn (AllowedFieldItemRules $rules): AllowedFieldItemRules => $rules->string())
        ->map([7 => 'seven']);
    $scaled = AllowedField::array('price')
        ->each(fn (AllowedFieldItemRules $rules): AllowedFieldItemRules => $rules->numeric())
        ->scale(100)
        ->at(0, fn (AllowedFieldItemRules $rules): AllowedFieldItemRules => $rules->string());

    expect(SearchParser::query('amount:7.00,7.00')->allowedFields([$mapped])->build()['values'])
        ->toBe(['7.00', 'seven'])
        ->and($mapped->toStorage('7.00'))->toBe('7.00')
        ->and($mapped->toStorage('7.00', 1))->toBe('seven')
        ->and(SearchParser::query('price:2.5,1.5')->allowedFields([$scaled])->build()['values'])
        ->toBe(['2.5', 150]);
});

it('ignores strings case-insensitively before validation in every value context', function (
    string $query,
    null|string $expected,
): void {
    $field = AllowedField::in('status', ['open', 'closed'])->ignore('ÉTÉ');

    expect(SearchParser::query($query)->allowedFields([$field])->build('string'))->toBe($expected);
})->with([
    ['status:été', null],
    ['status:éTÉ,OPEN,"été",CLOSED', 'status:open,closed'],
    ['status:[été TO OPEN]', 'status:[* TO open]'],
    ['status:[OPEN TO été]', 'status:[open TO *]'],
]);

it('keeps field subclasses through factories and fluent configuration', function (
    string $factory,
    array $arguments,
): void {
    $prototype = new class ('field') extends AllowedField {
        public function packageMethod(): string
        {
            return $this->getInternalName();
        }
    };
    $field = $prototype::$factory('field', ...$arguments);

    expect(
        $field
            ->min(1)
            ->max(10)
            ->between(1, 10)
            ->size(2)
            ->nullable()
            ->default(null)
            ->ignore('any')
            ->alias('yes', true)
            ->operators('=')
            ->withoutLists()
            ->withoutRanges()
            ->withoutWildcards()
            ->withoutExists()
            ->each(fn ($rules) => $rules->string())
            ->at(0, fn ($rules) => $rules->string())
            ->packageMethod(),
    )->toBe(
        'field',
    );
})->with([
    ['array', []], ['boolean', []], ['callback', [static fn (): bool => true]],
    ['date', []], ['decimal', [2]],
    ['enum', [PublicationStatus::class]],
    ['in', [['open', 'closed']]], ['integer', []], ['numeric', []], ['string', []],
    ['ulid', []], ['uuid', []],
]);

it('keeps item size rules independent of type rule order', function (
    string $type,
    string $scope,
    string $sizeRule,
): void {
    foreach ([true, false] as $sizeFirst) {
        $configure = function (AllowedFieldItemRules $rules) use (
            $type,
            $sizeRule,
            $sizeFirst,
        ): void {
            $setType = function () use ($type, $rules): void {
                match ($type) {
                    'integer' => $rules->integer(),
                    'numeric' => $rules->numeric(),
                    'decimal' => $rules->decimal(2),
                };
            };
            $setSize = function () use ($sizeRule, $rules): void {
                match ($sizeRule) {
                    'min' => $rules->min(100),
                    'max' => $rules->max(200),
                    'between' => $rules->between(100, 200),
                    'size' => $rules->size(150),
                };
            };

            if ($sizeFirst) {
                $setSize();

                $setType();
            } else {
                $setType();

                $setSize();
            }
        };
        $field = AllowedField::array('values');

        $scope === 'each' ? $field->each($configure) : $field->at(0, $configure);

        $value = $type === 'decimal' ? '150.00' : '150';

        expect(
            SearchParser::query('values:"'.$value.'"')
                ->allowedFields([$field])
                ->parse()
                ->getValue(),
        )->toBe(
            $type === 'decimal' ? $value : 150,
        );
    }
})
    ->with(['integer', 'numeric', 'decimal'])
    ->with(['each', 'at'])
    ->with(['min', 'max', 'between', 'size']);

it('keeps non-string ignore values strict and alias tokens case-sensitive', function (): void {
    $field = new AllowedField('value')->ignore(0, false, 'all')->alias('ALL', 42);

    expect($field->isIgnored('0'))->toBeFalse()
        ->and($field->isIgnored(0.0))->toBeFalse()
        ->and($field->isIgnored(null))->toBeFalse()
        ->and(SearchParser::query('value:ALL')->allowedFields([$field])->parse()->getValue())->toBe(
            42,
        )
        ->and(SearchParser::query('value:All')->allowedFields([$field])->parse())->toBeNull()
        ->and(SearchParser::query('value:"ALL"')->allowedFields([$field])->parse())->toBeNull();
});

it('keeps number storage for maps without strings and other conversions', function (): void {
    $quantity = AllowedField::integer('quantity')->map([99 => 10]);

    expect($quantity->storesText())->toBeFalse()
        ->and($quantity->toStorage(99))->toBe(10)
        ->and($quantity->toStorage(5))->toBe(5)
        ->and(AllowedField::numeric('amount')->scale(100)->storesText())->toBeFalse()
        ->and(AllowedField::string('name')->storesText())->toBeFalse();
});

it('maps canonical cast values and preserves ordinal comparisons', function (): void {
    $field = AllowedField::in('priority', ['low', 'normal', 'high'])->map([
        'low' => 1,
        'normal' => 2,
        'high' => 3,
    ]);
    $parser = SearchParser::query('priority:>NORMAL')->allowedFields([$field]);

    expect($parser->parse()->getValue())->toBe('normal')
        ->and($parser->build()['operator'])->toBe('>')->and($parser->build()['value'])->toBe(2)
        ->and($parser->build('string'))->toBe('priority:>normal')
        ->and(
            SearchParser::query('priority:LOW,HIGH')->allowedFields([$field])->build()['values'],
        )->toBe(
            [1, 3],
        )
        ->and(
            SearchParser::query('priority:[low TO high]')->allowedFields([$field])->build()['from'],
        )->toBe(
            1,
        );
});

it('matches aliases exactly and only in unquoted field values', function (
    string $query,
    string $value,
): void {
    $field = AllowedField::string('x')->alias('yes', 'resolved');

    expect(SearchParser::query($query)->allowedFields([$field])->parse()->getValue())->toBe($value);
})->with([
    ['x:yes', 'resolved'], ['x:YES', 'YES'], ['x:Yes', 'Yes'],
    ['x:"yes"', 'yes'], ["x:'yes'", 'yes'], ['x:yesmore', 'yesmore'],
]);

it('matches decimal keys written with trailing zeros', function (): void {
    $field = AllowedField::decimal('amount', 0, 2)->map(['1.50' => 'half', '-7' => 'minus']);

    expect($field->toStorage('1.50'))->toBe('half')
        ->and($field->toStorage('1.5'))->toBe('half')
        ->and($field->toStorage('-7.00'))->toBe('minus')
        ->and(SearchParser::query('amount:@half')->allowedFields([
            AllowedField::decimal('amount', 0, 2)->alias('@half', 1.5)->map(['1.50' => 'half']),
        ])->build()['value'])->toBe('half')
        ->and(SearchParser::query('code:1.50')->allowedFields([
            AllowedField::string('code')->map(['1.5' => 'a', '1.50' => 'b']),
        ])->build()['value'])->toBe('b');
});

it('matches decimal values against map keys by value without trailing zeros', function (): void {
    $text = AllowedField::decimal('amount', 0, 2)->map([7 => 'seven', '1.5' => 'half']);
    $number = AllowedField::decimal('amount', 0, 2)->map([7 => 70]);

    expect($text->toStorage('7.0'))->toBe('seven')
        ->and($text->toStorage('7.00'))->toBe('seven')
        ->and($text->toStorage('1.50'))->toBe('half')
        ->and($text->toStorage('10.50'))->toBe('10.5')
        ->and($text->toStorage('-0.0'))->toBe('0')
        ->and($number->toStorage('7.00'))->toBe(70)
        ->and($number->toStorage('10.50'))->toBe('10.5')
        ->and(SearchParser::query('amount:7.0,1.50')->allowedFields([$text])->build()['values'])
        ->toBe(['seven', 'half']);
});

it('matches fractional map keys from the exact float value', function (): void {
    $field = AllowedField::numeric('level')->map(['1.5' => 'half', 1 => 'one']);

    expect($field->toStorage(1.5))->toBe('half')
        ->and($field->toStorage(1.0))->toBe('one')
        ->and($field->toStorage(1.000_000_000_000_000_2))->toBe('1.0000000000000002');
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

it('matches storage map keys ignoring case for strings', function (): void {
    $field = AllowedField::string('priority')->map(['High' => 3, 1 => 'one']);

    expect($field->toStorage('HIGH'))->toBe('3')
        ->and($field->toStorage('high'))->toBe('3')
        ->and($field->toStorage(1))->toBe('one')
        ->and($field->toStorage('low'))->toBe('low');
});

it('measures numeric-looking strings by character length', function (string $rule): void {
    $field = AllowedField::string('code');
    $field = match ($rule) {
        'min' => $field->min(3),
        'max' => $field->max(3),
        'between' => $field->between(2, 3),
        'size' => $field->size(3),
    };

    expect(SearchParser::query('code:123')->allowedFields([$field])->parse()->getValue())->toBe(
        '123',
    );
})->with(['min', 'max', 'between', 'size']);

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

it('resolves aliases before applying shared and per-index array validation', function (): void {
    $field = AllowedField::array('x')
        ->alias('yes', true)
        ->alias('@me', 7)
        ->at(0, static fn ($rules) => $rules->boolean())
        ->at(1, static fn ($rules) => $rules->integer()->min(5));

    expect(SearchParser::query('x:yes,@me')->allowedFields([$field])->parse()->getValues())->toBe([
        true,
        7,
    ]);
});

it('resolves scalar aliases without casting their result', function (
    bool|float|int|string $value,
): void {
    $field = new AllowedField('x')->alias('token', $value);

    expect(SearchParser::query('x:token')->allowedFields([$field])->parse()->getValue())->toBe(
        $value,
    );
})->with([true, false, 0, 42, 1.5, '007', 'true', '*']);

it('resolves unquoted aliases and validates their results without recasting', function (): void {
    $id = 7;
    $field = AllowedField::integer('id')->min(5)->alias('@me', static function () use (&$id): int {
        return $id;
    });

    expect($field->tryCast('@me'))->toBe(7)
        ->and($field->tryCast('@me', quoted: true))->toBeNull()
        ->and(AllowedField::string('code')->alias('token', 7)->tryCast('token'))->toBeNull()
        ->and(new AllowedField('code')->tryCast('42', quoted: true))->toBe('42');

    $id = 3;

    expect($field->tryCast('@me'))->toBeNull();
});

it('returns the configured mode without a node', function (): void {
    expect(AllowedField::string('name')->getTextMatch())->toBe(TextMatch::Exact)
        ->and(AllowedField::string('name')->words()->getTextMatch())->toBe(TextMatch::Words);
});

it('scales array items with a numeric shared type', function (): void {
    $field = AllowedField::array('prices')->each(fn ($rules) => $rules->numeric())->scale(100);

    expect(SearchParser::query('prices:1.5,2')->allowedFields([$field])->build()['values'])->toBe([
        150,
        200,
    ]);
});

it('scales floats exactly whatever serialize_precision is', function (): void {
    $previous = ini_set('serialize_precision', '17');

    try {
        expect(AllowedField::numeric('amount')->scale(10)->toStorage(0.1))->toBe(1)
            ->and(ini_get('serialize_precision'))->toBe('17');
    } finally {
        ini_set('serialize_precision', $previous === false ? '-1' : $previous);
    }
});

it('scales floats without precision loss and keeps oversized integers as text', function (): void {
    $amount = AllowedField::numeric('amount')->scale(100);

    expect($amount->toStorage(1_234_567_890_123.45))->toBe(123_456_789_012_345)
        ->and($amount->toStorage(0.123_456_789_012_345))->toBe('12.3456789012345')
        ->and(AllowedField::decimal('price', 0, 2)->scale(100)->toStorage('99999999999999999999'))
        ->toBe('9999999999999999999900');
});

it('scales numeric text that validation accepts with surrounding whitespace', function (): void {
    expect(SearchParser::query('amount:@seven')->allowedFields([
        AllowedField::numeric('amount')->alias('@seven', ' 7 ')->scale(100),
    ])->build()['value'])->toBe(700);
});

it('scales numeric values exactly into integers or decimal strings', function (
    AllowedField $field,
    string $input,
    int|string $expected,
): void {
    $parser = SearchParser::query('value:'.$input)->allowedFields([$field]);

    expect($parser->build()['value'])->toBe($expected)
        ->and($field->toStorage($parser->parse()->getValue()))->toBe($expected);
})->with([
    [AllowedField::decimal('value', 2)->scale(100), '10.50', 1_050],
    [AllowedField::decimal('value', 2)->scale('0.1'), '10.50', '1.05'],
    [AllowedField::integer('value')->scale('0.5'), '3', '1.5'],
    [AllowedField::integer('value')->scale('0.5'), '4', 2],
    [AllowedField::numeric('value')->scale(100), '0.29', 29],
    [AllowedField::numeric('value')->scale('1e2'), '-10.5', -1_050],
    [AllowedField::integer('value')->scale(0), '100', 0],
    [AllowedField::decimal('value', 2)->scale('0.00001'), '0.01', '0.0000001'],
]);

it('stores default values and makes null defaults nullable', function (): void {
    $fieldWithNullDefault = new AllowedField('status')->default(null);
    $fieldWithDefault = new AllowedField('status')->default('DRAFT');

    expect($fieldWithNullDefault)
        ->hasDefault()->toBeTrue()
        ->getDefault()->toBeNull()
        ->isNullable()->toBeTrue()
        ->and($fieldWithDefault)
        ->hasDefault()->toBeTrue()
        ->getDefault()->toBe('DRAFT')
        ->isNullable()->toBeFalse();
});

it('stores every value as exact text when the map stores a string', function (): void {
    $level = AllowedField::numeric('level')->map([7 => 'seven']);
    $tier = AllowedField::integer('tier')->map([1 => 'pending', 2 => 20]);
    $flag = AllowedField::boolean('flag')->map([1 => 'on']);

    expect($level->storesText())->toBeTrue()
        ->and($level->toStorage(7))->toBe('seven')
        ->and($level->toStorage(2))->toBe('2')
        ->and($level->toStorage(1.234_567_890_123_45))->toBe('1.23456789012345')
        ->and($level->toStorage(1.0e20))->toBe('100000000000000000000')
        ->and($tier->toStorage(2))->toBe('20')
        ->and($tier->toStorage(0))->toBe('0')
        ->and($flag->toStorage(true))->toBe('on')
        ->and($flag->toStorage(false))->toBe('0')
        ->and(SearchParser::query('tier:0,1')->allowedFields([$tier])->build()['values'])
        ->toBe(['0', 'pending']);
});

it('supports UUID and ULID shared and positional item rules and defaults', function (
    string $type,
    string $value,
): void {
    $field = AllowedField::array('ids')
        ->each(fn ($rules) => $rules->$type())
        ->at(1, fn ($rules) => $rules->$type())
        ->default([$value, $value]);
    $expected = $type === 'uuid' ? mb_strtolower($value) : mb_strtoupper($value);

    expect(
        SearchParser::query('ids:'.$value.',"'.$value.'"')
            ->allowedFields([$field])
            ->parse()
            ->getValues(),
    )
        ->toBe([$expected, $expected])
        ->and(SearchParser::query('')->allowedFields([$field])->parse()->getValues())->toBe([
            $expected,
            $expected,
        ])
        ->and($field->hasCastType(1))->toBeFalse()
        ->and($field->hasCastType($value))->toBeTrue();
})->with([
    ['uuid', '550E8400-E29B-41D4-A716-446655440000'],
    ['ulid', '01arz3ndektsv4rrffq69g5fav'],
]);

it('uses index types before shared item types and inference', function (): void {
    $typed = AllowedField::array('values')
        ->each(fn (AllowedFieldItemRules $rules): AllowedFieldItemRules => $rules->integer())
        ->at(0, fn (AllowedFieldItemRules $rules): AllowedFieldItemRules => $rules->string());
    $untyped = AllowedField::array('values')
        ->at(1, fn (AllowedFieldItemRules $rules): AllowedFieldItemRules => $rules->string());

    expect(SearchParser::query('values:1,2')->allowedFields([$typed])->parse()->getValues())->toBe([
        '1',
        2,
    ])
        ->and(
            SearchParser::query('values:true,123,4')
                ->allowedFields([$untyped])
                ->parse()
                ->getValues(),
        )->toBe(
            [true, '123', 4],
        );
});

it('uses only the last configured text match mode', function (): void {
    $field = AllowedField::string('title')->prefix()->contains()->words()->prefix();

    expect(SearchParser::query('title:mug')->allowedFields([$field])->build()['match'])->toBe(
        'prefix',
    );
});
