<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\Adapter\ArrayAdapter;
use HosmelQ\SearchSyntaxParser\Adapter\QueryAdapterInterface;
use HosmelQ\SearchSyntaxParser\AST\Node\ComparisonNode;
use HosmelQ\SearchSyntaxParser\AST\Node\ExistsNode;
use HosmelQ\SearchSyntaxParser\AST\Node\InNode;
use HosmelQ\SearchSyntaxParser\AST\Node\NodeInterface;
use HosmelQ\SearchSyntaxParser\AST\Node\UnaryOperatorNode;
use HosmelQ\SearchSyntaxParser\AST\Span;
use HosmelQ\SearchSyntaxParser\Exception\ErrorReason;
use HosmelQ\SearchSyntaxParser\Exception\InvalidFieldException;
use HosmelQ\SearchSyntaxParser\Exception\InvalidFieldOperatorException;
use HosmelQ\SearchSyntaxParser\Exception\InvalidFieldValueException;
use HosmelQ\SearchSyntaxParser\Exception\ParseException;
use HosmelQ\SearchSyntaxParser\Query\Clause;
use HosmelQ\SearchSyntaxParser\Query\ClauseGroup;
use HosmelQ\SearchSyntaxParser\Query\Operator;
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Sorting\AllowedSort;
use HosmelQ\SearchSyntaxParser\Validation\Field;
use HosmelQ\SearchSyntaxParser\Validation\FieldItemRules;

it('preserves the rejection message when allowed fields are configured', function (): void {
    SearchParser::query('title:x')->allowedFields(['age', 'status'])->parse();
})->throws(
    InvalidFieldException::class,
    "Field 'title' is not allowed. Allowed fields are: age, status.",
);

it('rejects a sort whose name is another sort plus a direction', function (string $name): void {
    expect(fn (): SearchParser => SearchParser::query('')->allowedSorts([
        AllowedSort::field('created'),
        AllowedSort::field($name),
    ]))->toThrow(InvalidArgumentException::class, 'is ambiguous with sort');
})->with(['created-desc', 'CREATED-ASC']);

it('rejects an unknown adapter even when no constraints remain', function (): void {
    $parser = SearchParser::query('')->allowedFields([Field::string('status')]);

    expect(fn (): mixed => $parser->build('unknown'))
        ->toThrow(InvalidArgumentException::class, "Adapter 'unknown' not supported.");
});

it('rejects defaults invalidated by rules added after configuration', function (): void {
    $field = Field::string('status')->default('open');
    $parser = SearchParser::query('')->allowedFields([$field]);

    $field->min(5);

    expect(fn (): null|NodeInterface => $parser->parse())->toThrow(
        InvalidArgumentException::class,
        'status',
    );
});

it('rejects defaults that fail field validation when they apply', function (
    Field $field,
): void {
    expect(fn (): null|NodeInterface => SearchParser::query('')->allowedFields([$field])->parse())
        ->toThrow(InvalidArgumentException::class, "Invalid default for field 'status'.");
})->with([
    'membership' => [Field::in('status', ['open', 'closed'])->default('ACTVE')],
    'type' => [Field::integer('status')->default('abc')],
    'size' => [Field::string('status')->min(3)->default('ab')],
    'collection size' => [Field::array('status')->size(2)->default(['open'])],
    'item type' => [
        Field::array('status')->each(fn ($rules) => $rules->integer())->default(['abc']),
    ],
    'item position' => [
        Field::array('status')->at(1, fn ($rules) => $rules->min(5))->default([1, 2]),
    ],
    'scalar on sized array' => [Field::array('status')->size(2)->default('open')],
    'list item on scalar field' => [
        Field::string('status')->min(3)->default(['open', 'ab']),
    ],
]);

it('rejects denied defaults that fail validation', function (Field $field): void {
    SearchParser::query('')->allowedFields([
        $field->authorize(static fn (): bool => false),
    ])->compile();
})->throws(InvalidArgumentException::class, "Invalid default for field 'value'.")->with([
    Field::integer('value')->default('invalid'),
    Field::integer('value')->directive()->default('invalid'),
    Field::array('value')
        ->each(fn (FieldItemRules $rules): FieldItemRules => $rules->integer())
        ->default(['bad']),
]);

it('rejects denied names without reopening them through patterns or allowAnyField', function (
    string $name,
    bool $any,
): void {
    $parser = SearchParser::query($name.':x')->allowedFields([
        Field::string('meta.*')->keys('/^.+$/'),
        Field::string('meta.secret')->authorize(static fn (): bool => false),
        Field::string('meta.private.*')->keys('/^.+$/')->authorize(static fn (): bool => false),
        Field::string('mode')->directive()->authorize(static fn (): bool => false),
    ]);

    if ($any) {
        $parser->allowAnyField();
    }

    expect($parser->isFieldAllowed($name))->toBeFalse()
        ->and($parser->getAllowedField($name))->toBeNull()
        ->and($parser->getAllowedFieldNames())->toBe(['meta.*']);

    try {
        $parser->parse();

        test()->fail('Expected a denied field error.');
    } catch (InvalidFieldException $invalidFieldException) {
        expect($invalidFieldException->getReason())->toBe(ErrorReason::FieldNotAllowed)
            ->and($invalidFieldException->getParameters())->toBe([
                'field' => $name,
                'allowed' => ['meta.*'],
            ]);
    }
})->with(['meta.secret', 'meta.private.key', 'mode'])->with([false, true]);

it('rejects duplicate sort names ignoring case', function (): void {
    SearchParser::query('')->allowedSorts(['created', 'CREATED']);
})->throws(InvalidArgumentException::class);

it('rejects field queries by default with an empty allowlist and a character position', function (
    bool $configureEmptyList,
): void {
    $parser = SearchParser::query('  é title:x');

    if ($configureEmptyList) {
        $parser->allowedFields([]);
    }

    expect($parser->isFieldAllowed('title'))->toBeFalse();

    try {
        $parser->parse();

        test()->fail('Expected a field error.');
    } catch (InvalidFieldException $invalidFieldException) {
        expect($invalidFieldException)->getMessage()->toBe(
            "Field 'title' is not allowed. No fields are allowed.",
        )
            ->and($invalidFieldException->getField())->toBe('title')
            ->and($invalidFieldException->getReason())->toBe(ErrorReason::FieldNotAllowed)
            ->and($invalidFieldException->getParameters())->toBe([
                'field' => 'title',
                'allowed' => [],
            ])
            ->and($invalidFieldException->getPosition())->toBe(4);
    }
})->with([false, true]);

it('rejects invalid array values via validateField', function (): void {
    $parser = new SearchParser()->allowedFields([
        Field::integer('age'),
    ]);

    expect($parser->validateField('age', [1, 'x']))->toBeFalse();
});

it('rejects invalid default sort configuration', function (string $default): void {
    SearchParser::query('')->allowedSorts(['created'])->defaultSort($default)->parse();
})->throws(InvalidArgumentException::class, 'default sort')->with(['unknown', 'created-up']);

it('rejects invalid directive defaults as configuration errors', function (): void {
    SearchParser::query('')->allowedFields([
        Field::integer('limit')->directive()->min(1)->default('bad'),
    ])->parse();
})->throws(InvalidArgumentException::class, "Invalid default for field 'limit'.");

it('rejects invalid keys without falling through to a broader pattern or allowAnyField', function (
    bool $allowAny,
): void {
    $parser = SearchParser::query('é meta.stats.private:12')->allowedFields([
        Field::string('meta.*')->keys('/^.+$/D'),
        Field::integer('meta.stats.*')->keys(fn (string $key): bool => $key === 'views'),
    ]);

    if ($allowAny) {
        $parser->allowAnyField();
    }

    try {
        $parser->parse();

        test()->fail('Expected an invalid key error.');
    } catch (InvalidFieldException $invalidFieldException) {
        expect($invalidFieldException->getField())->toBe('meta.stats.private')
            ->and($invalidFieldException->getPosition())->toBe(2)
            ->and($invalidFieldException->getReason())->toBe(ErrorReason::FieldNotAllowed)
            ->and($invalidFieldException->getParameters())->toBe([
                'field' => 'meta.stats.private',
                'allowed' => ['meta.*', 'meta.stats.*'],
            ])
            ->and($invalidFieldException->getMessage())->toContain('meta.*, meta.stats.*');
    }
})->with([false, true]);

it('rejects invalid raw query types through every query entry point', function (
    mixed $input,
    string $method,
): void {
    $parser = SearchParser::query($input);

    try {
        $parser->$method();

        test()->fail('Expected an invalid type error.');
    } catch (ParseException $parseException) {
        expect($parseException->getReason())->toBe(ErrorReason::InvalidType)
            ->and($parseException->getPosition())->toBeNull()
            ->and($parseException->getParameters())->toBeEmpty();
    }
})->with([[false], [true], [0], [1.5], [[]], [new stdClass()]])
    ->with(['parse', 'build', 'compile']);

it('rejects non-string defaults whose type the field never produces from text', function (
    Field $field,
): void {
    expect(fn (): null|NodeInterface => SearchParser::query('')->allowedFields([$field])->parse())
        ->toThrow(InvalidArgumentException::class, "Invalid default for field 'value'.");
})->with([
    'bool on integer' => [Field::integer('value')->default(true)],
    'float on integer' => [Field::integer('value')->default(18.0)],
    'int on boolean' => [Field::boolean('value')->default(1)],
    'loose member' => [Field::in('value', ['1', '2'])->default(1)],
    'list item' => [
        Field::array('value')->each(fn ($rules) => $rules->integer())->default([1, '2', true]),
    ],
]);

it('rejects non-string identifier defaults as configuration errors', function (string $type): void {
    SearchParser::query('')->allowedFields([Field::$type('id')->default(1)])->parse();
})->throws(InvalidArgumentException::class)->with(['uuid', 'ulid']);

it('rejects nonpositive limits', function (int $limit): void {
    expect(fn (): SearchParser => SearchParser::query('a')->maxDepth($limit))->toThrow(
        InvalidArgumentException::class,
    )
        ->and(fn (): SearchParser => SearchParser::query('a')->maxClauses($limit))->toThrow(
            InvalidArgumentException::class,
        )
        ->and(fn (): SearchParser => SearchParser::query('a')->maxLength($limit))->toThrow(
            InvalidArgumentException::class,
        );
})->with([0, -1]);

it('rejects queries exceeding configured limits', function (): void {
    $parser = SearchParser::query('NOT (a b)')->maxDepth(2)->maxClauses(2)->maxLength(9);

    expect(fn (): mixed => $parser->maxDepth(1)->build())->toThrow(
        ParseException::class,
        'maximum depth of 1',
    )
        ->and(fn (): mixed => $parser->maxLength(8)->build())->toThrow(
            ParseException::class,
            'maximum length of 8 characters',
        );
});

it('rejects queries longer than the default length before tokenizing', function (): void {
    expect(SearchParser::query(str_repeat('é', 10_000))->parse()->getValue())->toHaveLength(10_000)
        ->and(fn (): mixed => SearchParser::query(str_repeat('(', 10_001))->parse())
        ->toThrow(
            ParseException::class,
            'Search query exceeds the maximum length of 10000 characters.',
        );
});

it('rejects reserved sort fields even when denied', function (bool $sortsFirst): void {
    $parser = SearchParser::query('');
    $field = Field::string('sort')->authorize(static fn (): bool => false);

    if ($sortsFirst) {
        $parser->allowedSorts(['name'])->allowedFields([$field]);
    } else {
        $parser->allowedFields([$field])->allowedSorts(['name']);
    }
})->throws(InvalidArgumentException::class, 'reserved')->with([false, true]);

it('rejects resource query input without a source position', function (): void {
    $stream = fopen('php://memory', 'r');

    try {
        SearchParser::query($stream)->compile();

        test()->fail('Expected an invalid type error.');
    } catch (ParseException $parseException) {
        expect($parseException->getReason())->toBe(ErrorReason::InvalidType)
            ->and($parseException->getPosition())->toBeNull();
    } finally {
        fclose($stream);
    }
});

it('rejects term configuration even when unused or denied', function (
    array $fields,
    string $name,
    bool $denied,
    string $query,
): void {
    foreach ($fields as $field) {
        $field->authorize(static fn (): bool => ! $denied);
    }

    SearchParser::query($query)->allowedFields($fields)->allowAnyField()->terms([$name])->compile();
})->throws(InvalidArgumentException::class, 'must be an exact configured field')->with([
    'unknown' => [[], 'missing'],
    'case' => [[Field::string('title')], 'TITLE'],
    'pattern' => [[Field::string('meta.*')->keys('/^.+$/')], 'meta.*'],
    'dynamic key' => [[Field::string('meta.*')->keys('/^.+$/')], 'meta.key'],
    'directive' => [[Field::string('mode')->directive()], 'mode'],
    'date' => [[Field::date('created')], 'created'],
    'shared date items' => [
        [Field::array('dates')->each(fn (FieldItemRules $rules): FieldItemRules => $rules->date())],
        'dates',
    ],
])->with([false, true])->with(['', 'unused:value']);

it('rejects time defaults on fields without time regardless of configuration order', function (
    Closure $field,
): void {
    SearchParser::query('')->allowedFields([$field()])->parse();
})->throws(InvalidArgumentException::class)->with([
    fn (): Field => Field::date('due')->withoutTime()->default('now'),
    fn (): Field => Field::date('due')->default('now')->withoutTime(),
]);

it('rejects unknown fields and invalid values through helpers', function (): void {
    $parser = SearchParser::query('age:10')->allowedFields([
        Field::integer('age', 'user_age'),
    ]);

    expect($parser->isFieldAllowed('name'))->toBeFalse()
        ->and($parser->validateField('age', 'ten'))->toBeFalse();
});

it('reports the token that exceeds query limits', function (
    string $query,
    string $limit,
    int $position,
): void {
    $parser = SearchParser::query($query)->allowAnyField();

    match ($limit) {
        'length' => $parser->maxLength(3),
        'depth' => $parser->maxDepth(1),
        'clauses' => $parser->maxClauses(2),
    };

    try {
        $parser->build();

        test()->fail('Expected a query limit error.');
    } catch (ParseException $parseException) {
        expect($parseException->getPosition())->toBe($position);
    }
})->with([
    ['  éé', 'length', 3],
    ['é (NOT a)', 'depth', 3],
    ['é (-a)', 'depth', 3],
    ['é ((a))', 'depth', 3],
    ['é a name:b', 'clauses', 4],
    ['name:a,b*,c', 'clauses', 10],
    ['é name:a,b*', 'clauses', 9],
]);

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

it('requires a nonempty key and a matching prefix', function (string $name): void {
    SearchParser::query($name.':red')->allowedFields([
        Field::string('meta.*')->keys('/^[a-z]+$/D'),
    ])->parse();
})->throws(InvalidFieldException::class)->with([
    'meta.', 'meta', 'metadata.color', 'other.color', 'meta.123', 'meta.Color',
]);

it('requires key rules when configuring a pattern into a parser', function (bool $shorthand): void {
    SearchParser::query('')->allowedFields([
        $shorthand ? 'meta.*' : Field::string('meta.*'),
    ]);
})->throws(InvalidArgumentException::class, 'requires a keys() rule')->with([false, true]);

it('requires non-string defaults to already have the field type', function (): void {
    expect(fn (): null|NodeInterface => SearchParser::query('')->allowedFields([
        Field::string('code')->default(1),
    ])->parse())->toThrow(InvalidArgumentException::class, "Invalid default for field 'code'.");
});

it('reserves sort only when configured regardless of configuration order or allowAnyField', function (
    bool $sortsFirst,
): void {
    $parser = SearchParser::query('sort:created')->allowAnyField();

    if ($sortsFirst) {
        $parser->allowedSorts(['created'])->allowedFields([Field::string('sort')]);
    } else {
        $parser->allowedFields(['sort'])->allowedSorts(['created']);
    }
})->throws(InvalidArgumentException::class, 'reserved')->with([true, false]);

it('reserves the sort field when sorts are configured', function (): void {
    expect(
        SearchParser::query('')->allowedSorts(['created'])->isFieldAllowed('sort'),
    )->toBeFalse();
});

it('still checks the raw query length when defaults exist', function (): void {
    SearchParser::query('    ')
        ->maxLength(3)
        ->allowedFields([Field::string('status')->default('active')])
        ->parse();
})->throws(ParseException::class, 'maximum length');

it('still enforces configured operator rules when any field is allowed', function (): void {
    SearchParser::query('age:>10')->allowAnyField()->allowedFields([
        Field::integer('age')->operators('='),
    ])->parse();
})->throws(InvalidFieldOperatorException::class);

it('still enforces configured value validation when any field is allowed', function (): void {
    SearchParser::query('age:old')->allowAnyField()->allowedFields([
        Field::integer('age'),
    ])->parse();
})->throws(InvalidFieldValueException::class);

it('throws on unknown adapter', function (): void {
    $parser = SearchParser::query('title:Coffee')->allowAnyField();

    $parser->adapter('unknown');
})->throws(InvalidArgumentException::class, "Adapter 'unknown' not supported.");

it('accepts bare terms without enabling fields', function (string $query, string $expected): void {
    expect(SearchParser::query($query)->build('string'))->toBe($expected);
})->with([
    ['coffee', 'coffee'],
    ['title', 'title'],
    ['NOT (coffee OR tea)', 'NOT (coffee OR tea)'],
    ['co*fee', 'co*fee'],
]);

it('accepts non-string defaults that already have the cast type', function (
    Field $field,
    mixed $expected,
): void {
    $node = SearchParser::query('')->allowedFields([$field])->parse();

    expect($node instanceof InNode ? $node->getValues() : $node->getValue())->toBe($expected);
})->with([
    'int on numeric' => [Field::numeric('value')->default(3), 3],
    'float on numeric' => [Field::numeric('value')->default(2.5), 2.5],
    'bool on boolean' => [Field::boolean('value')->default(false), false],
    'strict member' => [Field::in('value', [1, 2])->default(2), 2],
    'positional list' => [
        Field::array('value')
            ->at(0, fn ($rules) => $rules->integer())
            ->at(1, fn ($rules) => $rules->string())
            ->default([1, 'b']),
        [1, 'b'],
    ],
    'untyped' => [new Field('value')->default(true), true],
]);

it('accepts null and stringable request input', function (): void {
    $query = new class () implements Stringable {
        public function __toString(): string
        {
            return 'title:coffee';
        }
    };

    expect(new SearchParser(null)->compile())->toBeNull()
        ->and(SearchParser::query($query)->allowedFields(['title'])->compile()->children[0]->value)
        ->toBe('coffee');
});

it('accepts null defaults for typed and array fields without running their value rules', function (
    Field $field,
): void {
    $node = SearchParser::query('')->allowedFields([$field->default(null)])->parse();

    expect($field->isNullable())->toBeTrue()
        ->and($field->validate(null))->toBeTrue()
        ->and($node->isDefault())->toBeTrue()
        ->and($node->getOperand()->getField())->toBe('status');
})->with([Field::integer('status')->min(1), Field::array('status')->size(2)]);

it('accepts single value for array field', function (): void {
    $parser = SearchParser::query('labels:abc')->allowedFields([
        Field::array('labels')
            ->max(20)
            ->each(fn ($keyword): FieldItemRules => $keyword->string()->min(1)),
    ]);
    $ast = $parser->parse();

    expect($ast)->toBeInstanceOf(ComparisonNode::class)
        ->getField()->toBe('labels')
        ->getOperator()->toBe('=')
        ->getValue()->toBe('abc');
});

it('accepts valid array values via validateField', function (): void {
    $parser = new SearchParser()->allowedFields([
        Field::integer('age'),
    ]);

    expect($parser->validateField('age', [1, 2, 3]))->toBeTrue();
});

it('accepts whitespace surrounding the query', function (): void {
    $result = SearchParser::query('  title:Coffee  ')->allowAnyField()->build();

    expect($result)->toEqual([
        'default' => false,
        'field' => 'title',
        'match' => 'exact',
        'operator' => '=',
        'quoted' => false,
        'type' => 'comparison',
        'value' => 'Coffee',
    ]);
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

it('applies defaults at the AST level in configured order', function (): void {
    $parser = SearchParser::query('coffee')->allowedFields([
        Field::integer('age', 'user_age')->default(18),
        Field::array('status')->default(['active', 'draft']),
        Field::string('deleted')->default(null),
    ]);

    expect($parser->build('string'))->toBe(
        'coffee',
    )
        ->and($parser->build())->toBe(new ArrayAdapter($parser)->build($parser->parse()))
        ->and($parser->build()['left']['left']['right']['field'])->toBe('user_age')
        ->and($parser->build()['left']['left']['right']['default'])->toBeTrue();
});

it('applies directive defaults without adding filter nodes', function (): void {
    $parser = SearchParser::query('')->allowedFields([
        Field::in('mode', ['only', 'without'])
            ->directive()
            ->alias('normal', 'without')
            ->default('normal'),
    ]);

    expect($parser->parse())->toBeNull()
        ->and($parser->getDirectives())->toBe(['mode' => 'without'])
        ->and($parser->getTypedDirectives())->toBeEmpty()
        ->and($parser->build('string'))->toBeNull();
});

it('applies valid defaults without clause and depth limits', function (): void {
    $parser = SearchParser::query('coffee')->maxDepth(1)->maxClauses(1)->allowedFields([
        Field::integer('age')->min(99)->default(100),
        Field::array('tags')->size(2)->default(['a', 'b']),
        Field::string('missing')->default(null),
    ]);

    expect($parser->parse()->getRight()->getOperand()->getField())->toBe('missing')
        ->and($parser->build()['left']['left']['right']['value'])->toBe(100)
        ->and($parser->build('string'))->toBe('coffee');
});

it('casts directives, resolves aliases once, and exposes defaults separately', function (): void {
    $calls = 0;
    $parser = SearchParser::query('limit:@me mode:ONLY')->allowedFields([
        Field::in('mode', ['only', 'without'])->directive(),
        Field::integer('limit')->directive()->min(1)->alias(
            '@me',
            function () use (&$calls): int {
                $calls++;

                return 25;
            },
        ),
        Field::boolean('archived')->directive()->default('false'),
    ]);

    expect($parser->build())->toBeNull()
        ->and($parser->getDirectives())->toBe([
            'mode' => 'only',
            'limit' => 25,
            'archived' => false,
        ])
        ->and($parser->getTypedDirectives())->toBe(['mode' => 'only', 'limit' => 25])
        ->and($calls)->toBe(1);
});

it('casts list default items by position on array fields', function (): void {
    $field = Field::array('point')
        ->at(0, fn ($rules) => $rules->numeric())
        ->at(1, fn ($rules) => $rules->integer())
        ->default(['1.5', '2']);

    expect(SearchParser::query('')->allowedFields([$field])->parse()->getValues())->toBe([1.5, 2]);
});

it('compiles boolean chains without flattening field leaf groups', function (bool $negated): void {
    $query = 'title:coffee,tea OR title:cocoa OR (title:juice AND count:2)';
    $group = SearchParser::query($negated ? 'NOT ('.$query.')' : $query)->allowedFields([
        Field::string('title')->contains(),
        Field::integer('count'),
    ])->compile();

    expect($group)->toBeInstanceOf(ClauseGroup::class)
        ->and($group->and)->toBe($negated)
        ->and($group->field)->toBeNull()
        ->and($group->children)->toHaveCount(3);

    $leaf = $group->children[0];

    expect($leaf)->toBeInstanceOf(ClauseGroup::class)
        ->and($leaf->field)->toBe('title')
        ->and($leaf->and)->toBe($negated)
        ->and(array_column($leaf->children, 'value'))->toBe(['%coffee%', '%tea%'])
        ->and(array_column($leaf->children, 'negated'))->toBe([$negated, $negated])
        ->and($group->children[1])->toBeInstanceOf(Clause::class)
        ->and($group->children[2]->and)->toBe(! $negated);
})->with([false, true]);

it('compiles parsed wildcard lists as boolean groups', function (): void {
    $group = SearchParser::query('title:coffee,te*')
        ->allowedFields([Field::string('title')])
        ->compile();

    expect($group->and)->toBeFalse()
        ->and($group->field)->toBeNull()
        ->and(array_column($group->children, 'operator'))->toBe([Operator::Equal, Operator::Like]);
});

it('compiles terms in their configured order and keeps their groups separate', function (
    bool $negated,
): void {
    $group = SearchParser::query($negated ? 'NOT (coffee OR tea)' : 'coffee OR tea')
        ->allowedFields([Field::string('title'), Field::integer('count'), Field::string('body')])
        ->terms(['body', 'count', 'title'])
        ->compile();

    expect($group->and)->toBe($negated)->and($group->children)->toHaveCount(2);

    foreach ($group->children as $term) {
        expect($term->and)->toBe($negated)
            ->and($term->field)->toBeNull()
            ->and(array_column($term->children, 'field'))->toBe(['body', 'title']);
    }
})->with([false, true]);

it('compiles unmatched terms into empty groups', function (string $query, bool $and): void {
    $group = SearchParser::query($query)
        ->allowedFields([Field::integer('count')])
        ->terms(['count'])
        ->compile();

    expect($group->and)->toBe($and)
        ->and($group->children)->toBeEmpty()
        ->and($group->field)->toBeNull();
})->with([['coffee', false], ['NOT coffee', true], ['coff*', false], ['NOT coff*', true]]);

it('decides authorization once per parser and resets it with allowedFields', function (): void {
    $calls = 0;
    $allowed = true;
    $field = Field::string('title')->default('coffee')->authorize(
        function () use (&$calls, &$allowed): bool {
            $calls++;

            return $allowed;
        },
    );
    $parser = SearchParser::query('')->allowedFields([$field])->terms(['title']);

    $parser->compile();

    $allowed = false;

    expect($parser->getAllowedFields())->toBe(['title' => $field])
        ->and($parser->getAllowedField('title'))->toBe($field)
        ->and($parser->isFieldAllowed('title'))->toBeTrue()
        ->and($parser->getTermFields())->toBe([$field])
        ->and($parser->jsonSerialize()['fields'])->toHaveCount(1)
        ->and($parser->compile())->not->toBeNull()
        ->and($calls)->toBe(1);

    $second = SearchParser::query('')->allowedFields([$field]);

    expect($second->compile())->toBeNull()->and($calls)->toBe(2);

    $parser->allowedFields([$field]);

    expect($parser->compile())->toBeNull()->and($calls)->toBe(3);
});

it('describes allowed fields and sorts as ordered lists without parsing', function (): void {
    $parser = SearchParser::query('unknown:invalid')
        ->allowedFields(['status', Field::in('mode', ['active', 'all'])->directive()])
        ->allowedSorts([
            'status',
            AllowedSort::field('created', 'created_at')->defaultDirection('desc'),
        ]);
    $description = $parser->jsonSerialize();

    expect(array_column($description['fields'], 'name'))->toBe(['status', 'mode'])
        ->and($description['fields'][1])->toBe($parser->getAllowedFields()['mode']->jsonSerialize())
        ->and($description['sorts'])->toBe([
            ['direction' => 'asc', 'name' => 'status'],
            ['direction' => 'desc', 'name' => 'created'],
        ])
        ->and(new SearchParser()->jsonSerialize())->toBe(['fields' => [], 'sorts' => []]);
});

it('distinguishes default clauses from parsed and spanless programmatic clauses', function (): void {
    foreach ([
        [42, 'status:42', new ComparisonNode('status', '=', 42)],
        [[1, 2], 'status:1,2', new InNode('status', '=', [1, 2])],
        [null, 'NOT status:*', new UnaryOperatorNode('NOT', new ExistsNode('status'))],
    ] as [$value, $query, $manual]) {
        $default = SearchParser::query('')
            ->allowedFields([new Field('status')->default($value)])
            ->parse();
        $parsed = SearchParser::query($query)->allowAnyField()->parse();

        expect($default->isDefault())->toBeTrue()
            ->and($parsed->isDefault())->toBeFalse()
            ->and($manual->isDefault())->toBeFalse()
            ->and($manual->getSpan())->toBeNull()
            ->and(new ArrayAdapter()->build($default)['default'])->toBeTrue()
            ->and(new ArrayAdapter()->build($parsed)['default'])->toBeFalse();
    }
});

it('does not ignore trusted defaults or treat bare terms as field mentions', function (): void {
    $field = Field::string('status')->ignore('ALL')->default('ALL');

    expect(SearchParser::query('')->allowedFields([$field])->build()['value'])->toBe('ALL')
        ->and(SearchParser::query('status')->allowedFields([$field])->build('string'))->toBe(
            'status',
        );
});

it('does not invoke custom adapters for a directive-only query', function (): void {
    $parser = SearchParser::query('mode:only')->allowedFields([
        Field::string('mode')->directive(),
    ]);

    $parser->extend(
        'unused',
        static fn (): QueryAdapterInterface => new class () implements QueryAdapterInterface {
            #[Override]
            public function build(NodeInterface $ast): never
            {
                throw new LogicException('A null filter tree must not call custom adapters.');
            }
        },
    );

    expect($parser->build('unused'))->toBeNull()
        ->and($parser->getDirectives())->toBe(['mode' => 'only'])
        ->and($parser->build('string'))->toBe('mode:only');
});

it('explicitly accepts any field using normal value inference', function (): void {
    $parser = SearchParser::query('age:25 enabled:true')->allowAnyField();

    expect($parser->isFieldAllowed('anything'))->toBeTrue()
        ->and($parser->getAllowedFieldNames())->toBeEmpty()
        ->and($parser->getAllowedField('anything'))->toBeNull()
        ->and($parser->build()['left']['value'])->toBe(25)
        ->and($parser->build()['right']['value'])->toBeTrue();
});

it('exposes normalized field definitions by their public names without parsing', function (): void {
    $title = Field::string('title', 'private_title')->contains();
    $parser = SearchParser::query('unknown:invalid')->allowedFields(['status', $title]);
    $fields = $parser->getAllowedFields();

    expect(array_keys($fields))->toBe(['status', 'title'])
        ->and($fields['status'])->toBeInstanceOf(Field::class)
        ->and($fields['title'])->toBe($title)
        ->and(
            json_decode(
                json_encode($fields, JSON_THROW_ON_ERROR),
                true,
                flags: JSON_THROW_ON_ERROR,
            )['status']['name'],
        )->toBe(
            'status',
        )
        ->and(new SearchParser()->getAllowedFields())->toBeEmpty();
});

it('flags both nodes of a null default', function (): void {
    $node = SearchParser::query('')
        ->allowedFields([Field::string('deleted')->default(null)])
        ->parse();

    expect($node)->toBeInstanceOf(UnaryOperatorNode::class)
        ->and($node->isDefault())->toBeTrue()
        ->and($node->getOperand()->isDefault())->toBeTrue();
});

it('keeps a denied authorization decision for every parser read', function (): void {
    $allowed = false;
    $calls = 0;
    $field = Field::string('title')->authorize(function () use (&$allowed, &$calls): bool {
        $calls++;

        return $allowed;
    });
    $parser = SearchParser::query('coffee')->allowedFields([$field])->terms(['title']);

    expect($parser->getAllowedFields())->toBeEmpty();

    $allowed = true;

    expect($parser->compile()->children)->toBeEmpty()
        ->and($parser->getTermFields())->toBeEmpty()
        ->and($parser->getAllowedField('title'))->toBeNull()
        ->and($parser->getAllowedFieldNames())->toBeEmpty()
        ->and($parser->isFieldAllowed('title'))->toBeFalse()
        ->and($parser->jsonSerialize()['fields'])->toBeEmpty()
        ->and($calls)->toBe(1);
});

it('keeps a sort directive name free when no sorts are configured', function (): void {
    $parser = SearchParser::query('sort:newest')->allowedFields([
        Field::string('sort')->directive(),
    ]);

    expect($parser->parse())->toBeNull()
        ->and($parser->getDirectives())->toBe(['sort' => 'newest'])
        ->and($parser->getSort())->toBeNull()
        ->and($parser->isFieldAllowed('sort'))->toBeTrue();
});

it('keeps configured casting aliases mappings and defaults while allowing other fields', function (
    bool $enableFirst,
): void {
    $parser = SearchParser::query('age:@me rating:4.5');

    if ($enableFirst) {
        $parser->allowAnyField();
    }

    $parser->allowedFields([
        Field::integer('age', 'stored_age')->alias('@me', 25)->min(18),
        Field::string('status')->default('active'),
    ]);

    if (! $enableFirst) {
        $parser->allowAnyField();
    }

    $result = $parser->build();

    expect($parser->isFieldAllowed('age'))->toBeTrue()
        ->and($parser->isFieldAllowed('rating'))->toBeTrue()
        ->and($parser->getAllowedFieldNames())->toBe(['age', 'status'])
        ->and($result['left']['left']['field'])->toBe('stored_age')
        ->and($result['left']['left']['value'])->toBe(25)
        ->and($result['left']['right']['value'])->toBe(4.5)
        ->and($result['right']['value'])->toBe('active');
})->with([false, true]);

it('keeps day-anchor defaults on fields without time as text', function (): void {
    expect(SearchParser::query('')->allowedFields([
        Field::date('due')->withoutTime()->default('today'),
    ])->parse()->getValue())->toBe('today');
});

it('keeps defaults unquoted even when their text requires quotes in query syntax', function (): void {
    $scalar = SearchParser::query('')->allowedFields([
        Field::string('title')->default('a mug'),
    ])->parse();
    $list = SearchParser::query('')->allowedFields([
        Field::string('title')->default(['a mug', 'cup']),
    ])->parse();

    expect($scalar->isQuoted())->toBeFalse()
        ->and($list->isQuoted(0))->toBeFalse()->and($list->isQuoted(1))->toBeFalse();
});

it('keeps relative defaults and date array items as text', function (): void {
    $default = SearchParser::query('')
        ->allowedFields([Field::date('due')->default('today')])
        ->parse();
    $items = Field::array('dates')->each(
        fn (FieldItemRules $rules): FieldItemRules => $rules->date(),
    );

    expect($default->getValue())->toBe('today')->and($default->isDefault())->toBeTrue()
        ->and(
            SearchParser::query('dates:-7d,tomorrow+1w')
                ->allowedFields([$items])
                ->parse()
                ->getValues(),
        )
        ->toBe(['-7d', 'tomorrow+1w']);
});

it('leaves unconfigured sort fields under ordinary field permissions', function (): void {
    $parser = SearchParser::query('sort:created')->allowedFields(['sort'])->allowedSorts([]);

    expect($parser->build()['field'])->toBe('sort')->and($parser->getSort())->toBeNull();
});

it('marks all default-generated nodes and joins with null spans', function (): void {
    $node = SearchParser::query('coffee')->allowedFields([
        Field::string('status')->default('active'),
        Field::array('tag')->default(['one', 'two']),
        Field::string('deleted')->default(null),
    ])->parse();

    expect($node->getSpan())->toBeNull()
        ->and($node->getRight()->getSpan())->toBeNull()
        ->and($node->getRight()->getOperand()->getSpan())->toBeNull()
        ->and($node->getLeft()->getSpan())->toBeNull()
        ->and($node->getLeft()->getRight()->getSpan())->toBeNull()
        ->and($node->getLeft()->getLeft()->getSpan())->toBeNull()
        ->and($node->getLeft()->getLeft()->getRight()->getSpan())->toBeNull()
        ->and($node->getLeft()->getLeft()->getLeft()->getSpan())->toEqual(new Span(0, 6));
});

it('observes defaults configured after allowedFields', function (): void {
    $field = Field::string('status');
    $parser = SearchParser::query('')->allowedFields([$field]);

    $field->default('active');

    expect($parser->parse()->getValue())->toBe('active')->and($parser->build('string'))->toBe('');
});

it('omits defaults for fields referenced anywhere in the AST', function (string $query): void {
    $parser = SearchParser::query($query)->allowedFields([
        new Field('status')->default('fallback'),
    ]);

    expect($parser->build())->toBe(SearchParser::query($query)->allowAnyField()->build());
})->with([
    'status:active', 'NOT status:active', 'status:active,draft', 'status:[a TO z]',
    'status:*', 'NOT status:*', 'status:act*', 'status:act*,draft',
    'coffee OR (tea AND NOT status:active)',
]);

it('omits denied defaults terms and exported fields without restricting sorts', function (): void {
    $parser = SearchParser::query('')->allowedFields([
        Field::integer('secret')->default(42)->authorize(static fn (): bool => false),
        Field::string('mode')
            ->directive()
            ->default('hidden')
            ->authorize(static fn (): bool => false),
        Field::string('title'),
    ])->terms(['secret', 'title'])->allowedSorts(['secret'])->defaultSort('secret');

    expect($parser->compile())->toBeNull()
        ->and($parser->getDirectives())->toBeEmpty()
        ->and(array_map(fn (Field $field): string => $field->getName(), $parser->getTermFields()))
        ->toBe(['title'])
        ->and(array_column($parser->jsonSerialize()['fields'], 'name'))->toBe(['title'])
        ->and($parser->getSort()->getName())->toBe('secret');
});

it('prefers exact fields then the longest prefix regardless of configuration order', function (
    bool $reverse,
): void {
    $fields = [
        Field::string('meta.*', 'attributes.*')->keys('/^.+$/D'),
        Field::integer('meta.stats.*', 'statistics.*')->keys('/^[a-z]+$/D'),
        Field::boolean('meta.stats.active', 'enabled'),
    ];
    $parser = SearchParser::query('meta.stats.active:true meta.stats.count:12 meta.color:123')
        ->allowedFields($reverse ? array_reverse($fields) : $fields);
    $result = $parser->build();

    expect($result['left']['left']['field'])->toBe('enabled')
        ->and($result['left']['left']['value'])->toBeTrue()
        ->and($result['left']['right']['field'])->toBe('statistics.count')
        ->and($result['left']['right']['value'])->toBe(12)
        ->and($result['right']['field'])->toBe('attributes.color')
        ->and($result['right']['value'])->toBe('123');
})->with([false, true]);

it('reads only the final hyphen as a direction on a hyphenated key', function (): void {
    $parser = SearchParser::query('sort:created-at-desc')->allowedSorts([
        AllowedSort::field('created-at'),
    ]);

    $parser->parse();

    expect($parser->getSort()->getName())->toBe('created-at')
        ->and($parser->getSort()->getDirection())->toBe('desc')
        ->and($parser->getSort()->getInternalName())->toBe('created-at');
});

it('reads string defaults like typed query text', function (
    Field $field,
    bool|float|int|string $expected,
): void {
    expect(SearchParser::query('')->allowedFields([$field])->parse()->getValue())->toBe($expected);
})->with([
    'integer' => [Field::integer('age')->default('18'), 18],
    'boolean' => [Field::boolean('active')->default('true'), true],
    'membership' => [Field::in('level', [1, 2])->default('1'), 1],
    'alias' => [
        Field::integer('assignee')->alias('@me', static fn (): int => 7)->default('@me'),
        7,
    ],
    'typed scalar' => [Field::integer('age')->default(21), 21],
]);

it('reports allowed fields and validation helpers', function (): void {
    $parser = SearchParser::query('age:10')->allowedFields([
        Field::integer('age', 'user_age'),
    ]);

    expect($parser->isFieldAllowed('age'))->toBeTrue()
        ->and($parser->getAllowedField('age'))->not->toBeNull()
        ->and($parser->getAllowedFieldNames())->toBe(['age'])
        ->and($parser->validateField('age', 10))->toBeTrue()
        ->and($parser->validateField('other', 'any'))->toBeTrue();
});

it('reports no constraints exactly like an empty query when everything is ignored', function (
    string $query,
    string $operation,
    bool $withDefault,
): void {
    $field = Field::string('status')->ignore('ALL');

    if ($withDefault) {
        $field->default('active');
    }

    $parser = SearchParser::query($query)->allowedFields([$field]);

    expect($operation === 'parse' ? $parser->parse() : $parser->build())->toBeNull();
})->with([
    'status:ALL',
    'status:ALL,ALL',
    'status:[ALL TO ALL]',
    'NOT status:ALL',
    '(status:ALL)',
    'status:ALL AND status:ALL',
    'status:ALL OR NOT (status:ALL)',
])->with(['parse', 'build'])->with([false, true]);

it('resolves a repeated pattern name to the last configured field', function (): void {
    $parser = SearchParser::query('meta.b:x')->allowedFields([
        Field::string('meta.*')->keys('/^a$/'),
        Field::string('meta.*')->keys('/^b$/'),
    ]);

    expect($parser->build()['field'])->toBe('meta.b');
});

it('resolves aliases for string defaults but not for quoted range bounds', function (): void {
    $field = Field::string('x')->alias('token', 'resolved')->default('token');

    expect(SearchParser::query('')->allowedFields([$field])->parse()->getValue())->toBe('resolved')
        ->and(
            SearchParser::query('x:["token" TO token]')
                ->allowedFields([$field])
                ->parse()
                ->getFrom(),
        )->toBe(
            'token',
        );
});

it('resolves custom adapter via extend', function (): void {
    $parser = SearchParser::query('title:Coffee')->allowAnyField();

    $parser->extend('custom', function (): QueryAdapterInterface {
        return new class () implements QueryAdapterInterface {
            public function build(NodeInterface $ast): mixed
            {
                return ['ok' => true];
            }
        };
    });

    $adapter = $parser->adapter('custom');

    expect($adapter->build($parser->parse()))->toEqual(['ok' => true]);
});

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

it('returns default adapter and memoizes instance', function (): void {
    $parser = SearchParser::query('title:Coffee')->allowAnyField();
    $adapterA = $parser->adapter();
    $adapterB = $parser->adapter();

    expect($adapterA)->toBeInstanceOf(ArrayAdapter::class)->and($adapterA)->toBe($adapterB);
});

it('returns null when no filter survives compilation', function (string $query): void {
    expect(SearchParser::query($query)->allowedFields([
        Field::string('title')->ignore('all'),
        Field::integer('limit')->directive(),
    ])->compile())->toBeNull();
})->with(['', 'title:all', 'limit:2']);

it('returns null without calling the adapter when no constraints remain', function (
    string $query,
): void {
    $parser = SearchParser::query($query)->allowedFields([
        Field::string('status')->ignore('any'),
    ]);

    $parser->extend(
        'unused',
        static fn (): QueryAdapterInterface => new class () implements QueryAdapterInterface {
            #[Override]
            public function build(NodeInterface $ast): never
            {
                throw new LogicException('An empty query must not call an adapter.');
            }
        },
    );

    expect($parser->parse())->toBeNull()
        ->and($parser->build())->toBeNull()
        ->and($parser->build('string'))->toBeNull()
        ->and($parser->build('unused'))->toBeNull();
})->with(['', '   ', "\n\t", "\u{00A0}", 'status:any']);

it('supports configurable limits', function (): void {
    $parser = SearchParser::query('NOT (a b)')->maxDepth(2)->maxClauses(2)->maxLength(9);

    expect($parser->parse()->getOperator())->toBe('NOT');
});

it('supports defaults on numeric external field names', function (): void {
    $parser = SearchParser::query('')->allowedFields([Field::integer('123')->default(7)]);

    expect($parser->parse()->getField())->toBe('123')
        ->and($parser->parse()->getValue())->toBe(7)
        ->and($parser->build()['field'])->toBe('123');
});

it('supports every scalar default and singleton lists', function (
    mixed $default,
    array $expected,
): void {
    expect(
        SearchParser::query('')->allowedFields([new Field('value')->default($default)])->build(),
    )->toBe(
        $expected,
    );
})->with([
    [
        null,
        [
            'default' => true,
            'operand' => ['default' => true, 'field' => 'value', 'type' => 'exists'],
            'operator' => 'NOT',
            'type' => 'unary',
        ],
    ],
    [
        true,
        [
            'default' => true,
            'field' => 'value',
            'match' => 'exact',
            'operator' => '=',
            'quoted' => false,
            'type' => 'comparison',
            'value' => true,
        ],
    ],
    [
        false,
        [
            'default' => true,
            'field' => 'value',
            'match' => 'exact',
            'operator' => '=',
            'quoted' => false,
            'type' => 'comparison',
            'value' => false,
        ],
    ],
    [
        0,
        [
            'default' => true,
            'field' => 'value',
            'match' => 'exact',
            'operator' => '=',
            'quoted' => false,
            'type' => 'comparison',
            'value' => 0,
        ],
    ],
    [
        1.5,
        [
            'default' => true,
            'field' => 'value',
            'match' => 'exact',
            'operator' => '=',
            'quoted' => false,
            'type' => 'comparison',
            'value' => 1.5,
        ],
    ],
    [
        '',
        [
            'default' => true,
            'field' => 'value',
            'match' => 'exact',
            'operator' => '=',
            'quoted' => false,
            'type' => 'comparison',
            'value' => '',
        ],
    ],
    [
        [true, 2, 3.5, 'four'],
        [
            'default' => true,
            'field' => 'value',
            'match' => ['exact', 'exact', 'exact', 'exact'],
            'operator' => '=',
            'quoted' => [false, false, false, false],
            'type' => 'in',
            'values' => [true, 2, 3.5, 'four'],
        ],
    ],
    [
        ['one'],
        [
            'default' => true,
            'field' => 'value',
            'match' => ['exact'],
            'operator' => '=',
            'quoted' => [false],
            'type' => 'in',
            'values' => ['one'],
        ],
    ],
]);

it('suppresses defaults for removed clauses in every expression context', function (
    string $query,
): void {
    $parser = SearchParser::query($query)->allowedFields([
        Field::string('status', 'stored_status')->ignore('ALL')->default('active'),
        Field::string('region')->default('north'),
    ]);

    expect($parser->build()['field'])->toBe('region')
        ->and($parser->build()['value'])->toBe('north')
        ->and($parser->build('string'))->toBe('');
})->with([
    'status:ALL',
    'status:ALL,ALL',
    'status:[ALL TO ALL]',
    'NOT status:ALL',
    '(status:ALL OR NOT status:ALL)',
]);

it('trusts defaults despite every configured usage restriction', function (): void {
    $fields = [
        Field::string('scalar')->operators('!=')->default('active'),
        Field::array('list')->withoutLists()->default(['one', 'two']),
        Field::string('missing')->withoutExists()->default(null),
        Field::string('literal')->withoutWildcards()->default('literal*'),
    ];
    $result = SearchParser::query('')->allowedFields($fields)->build();

    expect($result['left']['left']['left']['value'])->toBe('active')
        ->and($result['left']['left']['right']['values'])->toBe(['one', 'two'])
        ->and($result['left']['right']['operand']['type'])->toBe('exists')
        ->and($result['right']['value'])->toBe('literal*');
});

it('trusts directive defaults despite ignored values and usage restrictions', function (): void {
    $parser = SearchParser::query('')->allowedFields([
        Field::string('mode')->directive()->operators('!=')->ignore('without')->default('without'),
    ]);

    expect($parser->parse())
        ->toBeNull()
        ->and($parser->getDirectives())
        ->toBe(['mode' => 'without']);
});

it('uses defaults for empty and whitespace-only queries', function (string $query): void {
    $parser = SearchParser::query($query)
        ->allowedFields([Field::string('status')->default('active')]);

    expect($parser->parse()->getValue())->toBe('active')
        ->and($parser->build()['default'])->toBeTrue()
        ->and($parser->build('string'))->toBe('');
})->with(['', '   ', "\n\t", "\u{00A0}"]);

it('uses external names when deciding whether a default applies', function (): void {
    $parser = SearchParser::query('status:active')->allowedFields([
        Field::string('status', 'stored_status')->default('fallback'),
        Field::string('stored_status', 'other_status')->default('draft'),
    ]);

    expect($parser->build()['left']['field'])->toBe('stored_status')
        ->and($parser->build()['right']['field'])->toBe('other_status')
        ->and($parser->build()['right']['value'])->toBe('draft')
        ->and($parser->build('string'))->toBe('status:active');
});

it('validates defaults like the equivalent query clause', function (
    Field $field,
    string $query,
): void {
    $default = SearchParser::query('')->allowedFields([$field])->build();
    $typed = SearchParser::query($query)->allowedFields([$field])->build();

    expect(array_diff_key($default, ['default' => true]))
        ->toBe(array_diff_key($typed, ['default' => true]));
})->with([
    'list on in field' => [
        Field::in('status', ['open', 'pending', 'closed'])->default(['open', 'pending']),
        'status:open,pending',
    ],
    'list on sized string field' => [
        Field::string('region')->min(3)->default(['north', 'south']),
        'region:north,south',
    ],
    'scalar on array field' => [Field::array('tags')->default('php'), 'tags:php'],
]);

it('validates defaults only when applied', function (): void {
    $field = Field::string('status')->default('open');

    $field->min(5);

    expect(
        SearchParser::query('status:closed')->allowedFields([$field])->parse()->getValue(),
    )->toBe(
        'closed',
    );
});

it('validates defaults with the complete collection and internal field name', function (): void {
    $values = ['open', 'closed'];
    $field = Field::array('status', 'stored_status')
        ->size(2)
        ->each(fn ($rules) => $rules->in($values))
        ->default($values);
    $callback = Field::callback(
        'region',
        fn ($value, $name): bool => $value === 'north' && $name === 'stored_region',
        'stored_region',
    )->default('north');

    expect(SearchParser::query('')->allowedFields([$field, $callback])->build()['left']['values'])
        ->toBe($values);
});
it('wraps single field and term clauses in an and root', function (string $query): void {
    $group = SearchParser::query($query)
        ->allowedFields([Field::string('title')])
        ->terms(['title'])
        ->allowAnyField()
        ->compile();

    expect($group->and)->toBeTrue()
        ->and($group->field)->toBeNull()
        ->and($group->children)->toHaveCount(1)
        ->and($group->children[0])->toBeInstanceOf(Clause::class);
})->with(['title:coffee', 'other:coffee', 'coffee', 'coff*']);
