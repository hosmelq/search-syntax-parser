<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\Exception\InvalidFieldException;
use HosmelQ\SearchSyntaxParser\Exception\InvalidFieldOperatorException;
use HosmelQ\SearchSyntaxParser\Exception\InvalidFieldValueException;
use HosmelQ\SearchSyntaxParser\Exception\ParseException;
use HosmelQ\SearchSyntaxParser\Query\Matcher;
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Tests\Fixtures\PriorityLevel;
use HosmelQ\SearchSyntaxParser\Tests\Fixtures\PublicationStatus;
use HosmelQ\SearchSyntaxParser\Validation\Field;
use HosmelQ\SearchSyntaxParser\Validation\FieldItemRules;

it('rejects custom date formats even when the query does not use them', function (
    Field $field,
): void {
    new Matcher(SearchParser::query('')->allowedFields([$field]));
})->throws(InvalidArgumentException::class, 'custom date formats')->with([
    'scalar' => [Field::date('value', 'd/m/Y')],
    'pattern' => [Field::date('meta.*', 'd/m/Y')->keys('/^[a-z]+$/')],
    'shared items' => [
        Field::array('value')->each(
            fn (FieldItemRules $rules): FieldItemRules => $rules->date('d/m/Y'),
        ),
    ],
    'positional items' => [
        Field::array('value')->at(
            1,
            fn (FieldItemRules $rules): FieldItemRules => $rules->date('d/m/Y'),
        ),
    ],
]);

it('rejects term names without an eligible exact field definition', function (
    array $fields,
    string $term,
): void {
    new Matcher(SearchParser::query('')->allowedFields($fields)->allowAnyField(), terms: [$term]);
})->throws(InvalidArgumentException::class, 'must be an exact configured field')->with([
    'unconfigured' => [[], 'value'],
    'different case' => [[Field::string('value')], 'VALUE'],
    'pattern definition' => [[Field::string('meta.*')->keys('/^[a-z]+$/')], 'meta.*'],
    'pattern key' => [[Field::string('meta.*')->keys('/^[a-z]+$/')], 'meta.color'],
    'directive' => [[Field::string('value')->directive()], 'value'],
    'date' => [[Field::date('value')], 'value'],
    'date items' => [
        [Field::array('value')->each(fn (FieldItemRules $rules): FieldItemRules => $rules->date())],
        'value',
    ],
]);

it('reports positioned value errors when the supplied clock or zone exceeds supported years', function (
    string $query,
    string $now,
    string $zone,
): void {
    try {
        new Matcher(
            SearchParser::query('  '.$query)->allowedFields([Field::date('created')]),
            timezone: new DateTimeZone($zone),
            now: new DateTimeImmutable($now),
        );
    } catch (InvalidFieldValueException $invalidFieldValueException) {
        expect($invalidFieldValueException->getPosition())->toBe(2);

        return;
    }

    test()->fail('Expected a positioned date error.');
})->with([
    ['created:tomorrow', '9999-12-31T12:00:00Z', 'UTC'],
    ['created:yesterday', '0001-01-01T12:00:00Z', 'UTC'],
    ['created:now', '9999-12-31T23:00:00Z', '+02:00'],
    ['created:now-1h', '0001-01-01T00:00:00Z', 'UTC'],
]);

it('reports query errors when constructed', function (string $query, string $exception): void {
    expect(fn (): Matcher => new Matcher(SearchParser::query($query)->allowedFields([
        Field::integer('quantity')->operators('='),
    ])))->toThrow($exception);
})->with([
    ['unknown:1', InvalidFieldException::class],
    ['quantity:no', InvalidFieldValueException::class],
    ['quantity:>1', InvalidFieldOperatorException::class],
    ['(', ParseException::class],
]);

it('applies defaults and allows unconfigured fields without reparsing records', function (): void {
    $calls = 0;
    $parser = SearchParser::query('title:coffee')->allowedFields([
        Field::integer('owner')->alias('mine', function () use (&$calls): int {
            $calls++;

            return 42;
        })->default('mine'),
        Field::string('deleted')->default(null),
    ])->allowAnyField();
    $matcher = new Matcher($parser);

    expect($matcher->matches(['title' => 'coffee', 'owner' => 42]))->toBeTrue()
        ->and($matcher->matches(['title' => 'COFFEE', 'owner' => 42]))->toBeFalse()
        ->and($matcher->matches(['title' => 'coffee', 'owner' => 43]))->toBeFalse()
        ->and(
            $matcher->matches(['title' => 'coffee', 'owner' => 42, 'deleted' => true]),
        )->toBeFalse()
        ->and($calls)->toBe(1);
});

it('applies term storage conversions and whole-word matching', function (): void {
    $fields = [Field::decimal('price', 2)->scale(100), Field::string('title')->words()];

    expect(
        new Matcher(SearchParser::query('1.25')->allowedFields($fields), ['price'])->matches([
            'price' => 125,
        ]),
    )->toBeTrue()
        ->and(
            new Matcher(
                SearchParser::query('"fresh coffee"')->allowedFields($fields),
                ['title'],
            )->matches(
                ['title' => 'fresh ground coffee'],
            ),
        )->toBeFalse()
        ->and(
            new Matcher(SearchParser::query('coff*')->allowedFields($fields), ['title'])->matches([
                'title' => 'fresh coffee',
            ]),
        )->toBeTrue();
});

it('captures the supplied clock once', function (string $clock): void {
    $now = new $clock('2024-03-15T12:00:00Z');
    $matcher = new Matcher(
        SearchParser::query('created:now')->allowedFields([Field::date('created')]),
        now: $now,
    );
    $later = $now->modify('+1 day');

    expect($matcher->matches(['created' => '2024-03-15T12:00:00Z']))->toBeTrue()
        ->and($matcher->matches(['created' => $later]))->toBeFalse();
})->with(['DateTime', DateTimeImmutable::class]);

it('checks presence independently of element types', function (string $query): void {
    $matcher = new Matcher(SearchParser::query($query)->allowedFields([Field::integer('value')]));
    $records = array_map(fn (mixed $value): array => ['value' => $value], [
        null, [], new ArrayIterator(), false, 0, '', [null], new ArrayIterator([
            null,
        ]), new stdClass(),
    ]);

    expect(array_keys(array_filter($records, $matcher->matches(...))))->toBe([3, 4, 5, 6, 7, 8])
        ->and($matcher->matches([]))->toBeFalse();
})->with(['value:*', 'value:*..*']);

it('checks presence through lists only on elements that have the value', function (
    array $items,
    bool $expected,
): void {
    $matcher = new Matcher(
        SearchParser::query('sku:*')->allowedFields([Field::string('sku', 'items.sku')]),
    );

    expect($matcher->matches(['items' => $items]))->toBe($expected);
})->with([
    'no element has the value' => [[['name' => 'mug'], ['sku' => null]], false],
    'one element has the value' => [[['sku' => null], ['sku' => 'A']], true],
]);

it('combines expanded text clauses before taking their complement', function (string $query): void {
    $matcher = new Matcher(
        SearchParser::query($query)->allowedFields([Field::string('value')->contains()]),
    );
    $records = array_map(fn (mixed $value): array => ['value' => $value], [
        ['coffee', null], ['tea', 'water'], ['teapot'], [], null, ['water'],
    ]);

    expect(array_keys(array_filter($records, $matcher->matches(...))))->toBe([2, 3, 4, 5]);
})->with(['value:!=cof,"tea"', 'NOT value:cof,"tea"']);

it('combines terms across eligible fields with boolean groups', function (
    string $query,
    array $expected,
): void {
    $fields = [Field::string('title'), Field::string('sku'), Field::integer('quantity')];
    $records = [
        ['title' => 'Iced coffee', 'sku' => 'A1', 'quantity' => 10],
        ['title' => 'Tea', 'sku' => 'COFFEE-1', 'quantity' => 2],
        ['title' => 'Coffee beans', 'quantity' => 3],
        ['quantity' => 10],
        [],
    ];
    $matcher = new Matcher(
        SearchParser::query($query)->allowedFields($fields),
        ['title', 'sku', 'quantity'],
    );

    expect(array_keys(array_filter($records, $matcher->matches(...))))->toBe($expected);
})->with([
    ['coffee', [0, 1, 2]],
    ['coff*', [1, 2]],
    ['NOT coffee', [3, 4]],
    ['NOT coff*', [0, 3, 4]],
    ['coffee quantity:>2', [0, 2]],
    ['(coffee OR 10) NOT quantity:3', [0, 1, 3]],
    ['NOT (coffee OR 10)', [4]],
    ['"iced coffee"', [0]],
]);

it('compares calendar fields by their written dates without zone conversion', function (
    Field $field,
    bool $items,
): void {
    $matcher = new Matcher(
        SearchParser::query('due:2024-03-14')->allowedFields([$field]),
        timezone: new DateTimeZone('America/New_York'),
    );
    $values = [
        new DateTimeImmutable('2024-03-14T00:00:00Z'),
        new DateTimeImmutable('2024-03-14T23:30:00-10:00'),
        '2024-03-14',
        '2024-03-14 00:00:00',
        '2024-03-14T00:00:00.000000Z',
        '2024-03-14T23:30:00-10:00',
    ];

    foreach ($values as $value) {
        expect($matcher->matches(['due' => $items ? [$value] : $value]))->toBeTrue();
    }

    foreach (['2024-03-15T00:00:00Z', '2024-02-30', '2024-03-14 +1 year'] as $value) {
        expect($matcher->matches(['due' => $items ? [$value] : $value]))->toBeFalse();
    }
})->with([
    'scalar' => [Field::date('due')->withoutTime(), false],
    'shared items' => [
        Field::array('due')->each(
            fn (FieldItemRules $rules): FieldItemRules => $rules->date()->withoutTime(),
        ),
        true,
    ],
]);

it('compares stored scalars and their complements', function (
    Field $field,
    string $query,
    array $values,
    array $expected,
): void {
    $positive = new Matcher(SearchParser::query($query)->allowedFields([$field]));
    $negative = new Matcher(SearchParser::query('NOT ('.$query.')')->allowedFields([$field]));
    $matches = [];

    foreach ($values as $index => $value) {
        $record = ['value' => $value];
        $matched = $positive->matches($record);

        if ($matched) {
            $matches[] = $index;
        }

        expect($negative->matches($record))->toBe(! $matched);
    }

    expect($matches)->toBe($expected)
        ->and($positive->matches([]))->toBeFalse()
        ->and($negative->matches([]))->toBeTrue();
})->with([
    'unicode equality' => [
        Field::string('value'),
        'value:CAFÉ',
        ['café', 'CAFÉ', 'cafe', false, null],
        [0, 1],
    ],
    'text numbers' => [Field::string('value'), 'value:1.25', [1.25, '1.25', 1, true], [0, 1]],
    'text integers' => [Field::string('value'), 'value:10', [10, '10', 10.0, true], [0, 1, 2]],
    'text lists' => [Field::string('value'), 'value:a,C', ['A', 'b', 'c'], [0, 2]],
    'text ordering' => [Field::string('value'), 'value:>2', ['10', '3', 9, '2', null], [1, 2]],
    'text ranges' => [Field::string('value'), 'value:A..C', ['a', 'B', 'c', 'd'], [0, 1, 2]],
    'prefix' => [
        Field::string('value')->prefix(),
        'value:cof',
        ['COFFEE', 'iced coffee', 'cof'],
        [0, 2],
    ],
    'contains' => [
        Field::string('value')->contains(),
        'value:cof',
        ['COFFEE', 'iced coffee', 'tea'],
        [0, 1],
    ],
    'quoted exact' => [Field::string('value')->contains(), 'value:"cof"', ['coffee', 'COF'], [1]],
    'like escapes' => [
        Field::string('value'),
        'value:a%_!*z',
        ['A%_!\nz', "a%_!\nz", 'a12!z', 'a%_!z!'],
        [0, 1],
    ],
    'literal wildcard' => [
        Field::string('value'),
        'value:"a*%"*',
        ['a*%rest', 'abc%rest', 'a*rest'],
        [0],
    ],
    'contains escapes' => [
        Field::string('value')->contains(),
        'value:a%_!',
        ['xA%_!y', 'xaZZ!y'],
        [0],
    ],
    'leading wildcard' => [
        Field::string('value')->allowLeadingWildcards(),
        'value:*FÉ',
        ['café', 'CAFE', 'fé!'],
        [0],
    ],
    'non-overlapping wildcard segments' => [
        Field::string('value'),
        'value:aba*aba',
        ['aba', 'ababa', 'abaaba', 'abaxaba'],
        [2, 3],
    ],
    'ordered wildcard segments' => [
        Field::string('value'),
        'value:a*aa*a',
        ['aaa', 'aaaa', 'aaaaa'],
        [1, 2],
    ],
    'integer equality' => [
        Field::integer('value'),
        'value:10',
        [10, 10.0, '10.00', '1e1', true, 'ten', INF, NAN],
        [0, 1, 2, 3],
    ],
    'exponent text' => [
        Field::numeric('value'),
        'value:>1',
        ['1e100000000', '1E+1', '-1e100000000', '1e-1'],
        [1],
    ],
    'greater' => [Field::numeric('value'), 'value:>10', [10, 11, '10.01', '2', false], [1, 2]],
    'greater or equal' => [Field::numeric('value'), 'value:>=10', [10, 11, 9], [0, 1]],
    'less' => [Field::numeric('value'), 'value:<10', [10, 11, 9], [2]],
    'less or equal' => [Field::numeric('value'), 'value:<=10', [10, 11, 9], [0, 2]],
    'number range' => [Field::numeric('value'), 'value:1..3', [0, 1, 2, 3, 4, true], [1, 2, 3]],
    'open lower bound' => [Field::numeric('value'), 'value:*..3', [-1, 3, 4, null, 'no'], [0, 1]],
    'open upper bound' => [Field::numeric('value'), 'value:3..*', [2, 3, 4, null, 'no'], [1, 2]],
    'exact decimals' => [
        Field::decimal('value', 2),
        'value:9007199254740993.01',
        ['9007199254740993.00', '9007199254740993.01', '9007199254740993.02'],
        [1],
    ],
    'scaled decimals' => [
        Field::decimal('value', 2)->scale(100),
        'value:10.50',
        [1_050, '1050.0', 10.5, '1050.01'],
        [0, 1],
    ],
    'scaled range' => [
        Field::decimal('value', 2)->scale(100),
        'value:1.25..1.50',
        [124, 125, 150, 151],
        [1, 2],
    ],
    'scaled list' => [
        Field::decimal('value', 2)->scale('0.1'),
        'value:1.25,1.50',
        ['0.125', '0.15', '1.25'],
        [0, 1],
    ],
    'float decimal' => [
        Field::decimal('value', 1),
        'value:0.1',
        [0.1, '0.10', 0.1 + 0.2, '0.10000000000000001'],
        [0, 1],
    ],
    'true' => [
        Field::boolean('value'),
        'value:true',
        [true, 1, '1', false, 0, '0', 'true', 1.0, 2],
        [0, 1, 2],
    ],
    'false' => [
        Field::boolean('value'),
        'value:false',
        [false, 0, '0', true, 1, 'false', null, 0.0],
        [0, 1, 2],
    ],
    'untyped identifiers' => [new Field('value'), 'value:007', ['007', '7', 7], [0]],
    'untyped numbers' => [new Field('value'), 'value:7', ['007', '7', 7, '7.1'], [0, 1, 2]],
    'members' => [
        Field::in('value', ['Open', 'Closed']),
        'value:OPEN',
        ['Open', 'OPEN', 'open', 'Closed'],
        [0],
    ],
    'string enum' => [
        Field::enum('value', PublicationStatus::class),
        'value:published',
        [PublicationStatus::Published, 'published', 'PUBLISHED', PublicationStatus::Draft],
        [0, 1],
    ],
    'integer enum' => [
        Field::enum('value', PriorityLevel::class),
        'value:High',
        [PriorityLevel::High, 2, '2', PriorityLevel::Low],
        [0, 1, 2],
    ],
    'uuid' => [
        Field::uuid('value'),
        'value:ABCDEF01-2345-6789-ABCD-EF0123456789',
        ['abcdef01-2345-6789-abcd-ef0123456789', 'ABCDEF01-2345-6789-ABCD-EF0123456789', 'other'],
        [0],
    ],
    'ulid' => [
        Field::ulid('value'),
        'value:01ARZ3NDEKTSV4RRFFQ69G5FAV',
        ['01ARZ3NDEKTSV4RRFFQ69G5FAV', '01arz3ndektsv4rrffq69g5fav', 'other'],
        [0],
    ],
    'mapped number' => [
        Field::string('value')->map(['open' => 1]),
        'value:open',
        [1, '1', 'open'],
        [0, 1],
    ],
    'mapped text' => [
        Field::integer('value')->map([1 => 'open']),
        'value:1',
        ['open', 'OPEN', 1, '1'],
        [0],
    ],
    'mapped decimal text' => [
        Field::decimal('value', 2)->map(['1.00' => 'open']),
        'value:1.00',
        ['open', 'OPEN', 1, '1.00'],
        [0],
    ],
]);

it('complements the entire matching set regardless of nullable declarations', function (
    bool $nullable,
): void {
    $records = [
        ['value' => [2, 10]],
        ['value' => [1, 2]],
        ['value' => []],
        ['value' => null],
        [],
        ['value' => 'wrong type'],
        ['value' => new stdClass()],
        ['value' => new ArrayIterator([10, 11])],
        ['value' => [[10], new stdClass()]],
    ];
    $fields = [Field::integer('value')->nullable($nullable)];

    foreach (['value:!=10', 'NOT value:10', '-value:10', 'NOT NOT value:!=10'] as $query) {
        $matcher = new Matcher(SearchParser::query($query)->allowedFields($fields));

        expect(array_keys(array_filter($records, $matcher->matches(...))))->toBe([
            1,
            2,
            3,
            4,
            5,
            6,
            8,
        ]);
    }
})->with([false, true]);

it('does not compare associative arrays as several scalar values', function (): void {
    $record = ['value' => ['label' => 'coffee']];
    $fields = [Field::string('value')];

    expect(
        new Matcher(SearchParser::query('value:coffee')->allowedFields($fields))->matches($record),
    )
        ->toBeFalse()
        ->and(
            new Matcher(SearchParser::query('value:!=coffee')->allowedFields($fields))->matches(
                $record,
            ),
        )
        ->toBeTrue()
        ->and(new Matcher(SearchParser::query('value:*')->allowedFields($fields))->matches($record))
        ->toBeTrue();
});

it('does not match bare terms without an eligible field', function (
    array $fields,
    string $query,
): void {
    $terms = array_map(fn (Field $field): string => $field->getName(), $fields);
    $parser = SearchParser::query($query)->allowedFields($fields)->allowLeadingWildcards();
    $negative = SearchParser::query('NOT ('.$query.')')
        ->allowedFields($fields)
        ->allowLeadingWildcards();
    $record = ['value' => 'coffee'];

    expect(new Matcher($parser, $terms)->matches($record))->toBeFalse()
        ->and(new Matcher($negative, $terms)->matches($record))->toBeTrue();
})->with([
    [[], 'coffee'],
    [[], 'coff*'],
    [[Field::integer('value')], 'coffee'],
    [[Field::string('value')->min(7)], 'coffee'],
    [[Field::string('value')->operators('!=')], 'coffee'],
    [[Field::string('value')->withoutWildcards()], 'coff*'],
    [[Field::string('value')->minWildcardLength(7)], 'coff*'],
    [[Field::string('value')], '*ffee'],
    [[Field::string('value')->words()], 'co*ee'],
]);

it('ignores custom date formats on directives', function (): void {
    $parser = SearchParser::query('as-of:15/03/2024')->allowedFields([
        Field::date('as-of', 'd/m/Y')->directive(),
    ]);

    expect(new Matcher($parser)->matches([]))->toBeTrue()
        ->and($parser->getDirectives())->toBe(['as-of' => '15/03/2024']);
});

it('interprets stored datetimes in a separate storage zone', function (): void {
    $matcher = new Matcher(
        SearchParser::query('created:2024-03-14')->allowedFields([Field::date('created')]),
        timezone: new DateTimeZone('America/New_York'),
        storageTimezone: new DateTimeZone('UTC'),
    );

    expect($matcher->matches(['created' => '2024-03-14 02:30:00']))->toBeFalse()
        ->and($matcher->matches(['created' => '2024-03-15 02:30:00']))->toBeTrue()
        ->and($matcher->matches(['created' => '2024-03-15T02:30:00Z']))->toBeTrue()
        ->and($matcher->matches(['created' => '2024-03-15T02:30:00-04:00']))->toBeFalse()
        ->and($matcher->matches(['created' => new DateTimeImmutable('2024-03-15T02:30:00Z')]))
        ->toBeTrue();
});

it('keeps temporary iterables of different fields apart', function (): void {
    $record = new class () implements ArrayAccess {
        public function offsetExists(mixed $offset): bool
        {
            return true;
        }

        public function offsetGet(mixed $offset): mixed
        {
            return new ArrayIterator([$offset]);
        }

        public function offsetSet(mixed $offset, mixed $value): void {}

        public function offsetUnset(mixed $offset): void {}
    };
    $matcher = new Matcher(SearchParser::query('a:a b:b c:c')->allowedFields([
        Field::string('a'),
        Field::string('b'),
        Field::string('c'),
    ]));

    expect($matcher->matches($record))->toBeTrue();
});

it('maps dynamic names with exact and longest-pattern precedence', function (): void {
    $fields = [
        Field::string('meta.*', 'attributes.*')->keys('/^[a-z.]+$/'),
        Field::integer('meta.count', 'count'),
        Field::string('meta.extra.*', 'extras.*')->keys('/^[a-z]+$/'),
    ];
    $matcher = new Matcher(
        SearchParser::query('meta.color:RED meta.count:2 meta.extra.tag:new')
            ->allowedFields($fields),
    );

    expect($matcher->matches([
        'attributes' => ['color' => 'red', 'count' => 100],
        'count' => 2,
        'extras.tag' => 'NEW',
    ]))->toBeTrue();
});

it('matches every record when filters disappear and leaves settings on the parser', function (): void {
    $parser = SearchParser::query('status:all limit:10 sort:title-desc')->allowedFields([
        Field::string('status')->ignore('all')->default('open'),
        Field::integer('limit')->directive(),
    ])->allowedSorts(['title']);
    $matcher = new Matcher($parser);

    expect($matcher->matches([]))->toBeTrue()
        ->and($matcher->matches(['status' => 'closed']))->toBeTrue()
        ->and($parser->getDirectives())->toBe(['limit' => 10])
        ->and($parser->getSort()->getDirection())->toBe('desc')
        ->and(new Matcher(new SearchParser())->matches([]))->toBeTrue();
});

it('matches long phrases and word lists in linear time', function (string $query): void {
    $matcher = new Matcher(
        SearchParser::query($query)
            ->allowedFields([Field::string('value')->words()])
            ->maxLength(20_000),
    );
    $record = ['value' => str_repeat('a ', 20_000)];
    $start = hrtime(true);

    expect($matcher->matches($record))->toBeFalse()
        ->and((hrtime(true) - $start) / 1e9)->toBeLessThan(1.0);
})->with([
    'phrase' => ['value:"'.str_repeat('a ', 2_000).'b"'],
    'list' => ['value:'.implode(',', array_fill(0, 1_000, 'zz'))],
]);

it('matches long wildcard patterns without exhausting backtracking', function (): void {
    $fields = [Field::string('title')];
    $record = ['title' => str_repeat('ab', 200).'X'.str_repeat('a', 200).'Y'];

    expect(
        new Matcher(SearchParser::query('title:a*a*a*X*Y')->allowedFields($fields))->matches(
            $record,
        ),
    )
        ->toBeTrue()
        ->and(
            new Matcher(SearchParser::query('title:!=a*a*a*X*Y')->allowedFields($fields))->matches(
                $record,
            ),
        )
        ->toBeFalse();
});

it('matches shared item types and storage conversions', function (
    Field $field,
    string $query,
    array $record,
): void {
    expect(
        new Matcher(SearchParser::query($query)->allowedFields([$field]))->matches($record),
    )->toBeTrue();
})->with([
    [
        Field::array('value')->each(
            fn (FieldItemRules $rules): FieldItemRules => $rules->integer(),
        ),
        'value:2,3',
        ['value' => [1, '2']],
    ],
    [
        Field::array('value')
            ->each(fn (FieldItemRules $rules): FieldItemRules => $rules->decimal(2))
            ->scale(100),
        'value:1.25,1.50',
        ['value' => [125]],
    ],
    [
        Field::array('value')->each(fn (FieldItemRules $rules): FieldItemRules => $rules->string()),
        'value:red,blue',
        ['value' => ['blue']],
    ],
    [
        Field::array('value')->each(
            fn (FieldItemRules $rules): FieldItemRules => $rules->boolean(),
        ),
        'value:true,false',
        ['value' => ['1']],
    ],
    [
        Field::array('value')->each(fn (FieldItemRules $rules): FieldItemRules => $rules->date()),
        'value:2024-01-01,2024-02-01',
        ['value' => ['2024-02-01T10:00:00Z']],
    ],
]);

it('matches words phrases and prefixes without stemming', function (
    string $query,
    array $expected,
): void {
    $records = array_map(fn (string $value): array => ['value' => $value], [
        'Fresh coffee beans', 'coffee, fresh beans', 'freshly brewed coffee', 'fresh coffeepot', 'fresh coffee', 'running runners', 'café CAFÉ',
    ]);
    $matcher = new Matcher(
        SearchParser::query($query)->allowedFields([Field::string('value')->words()]),
    );

    expect(array_keys(array_filter($records, $matcher->matches(...))))->toBe($expected);
})->with([
    ['value:fresh|coffee', [0, 1, 4]],
    ['value:"fresh coffee"', [0, 4]],
    ['value:"fresh coff"*', [0, 1, 3, 4]],
    ['value:coff*', [0, 1, 2, 3, 4]],
    ['value:"fresh coffee",runners', [0, 4, 5]],
    ['value:run', []],
    ['value:"!!!"', []],
    ['NOT value:"!!!"', [0, 1, 2, 3, 4, 5, 6]],
    ['value:CAFÉ', [6]],
]);

it('normalizes stringable values and enum values inside iterables', function (): void {
    $matcher = new Matcher(
        SearchParser::query('value:published')->allowedFields([Field::string('value')]),
    );
    $text = new class () implements Stringable {
        public function __toString(): string
        {
            return 'PUBLISHED';
        }
    };

    expect($matcher->matches(['value' => $text]))->toBeTrue()
        ->and(
            $matcher->matches(['value' => new ArrayIterator([null, PublicationStatus::Published])]),
        )->toBeTrue()
        ->and($matcher->matches(['value' => new stdClass()]))->toBeFalse();
});

it('reads a field from each list element while preserving explicit indexes and whole keys', function (): void {
    $fields = [Field::string('sku', 'items.sku'), Field::string('first', 'items.0.sku')];
    $record = ['items' => [['sku' => 'A'], ['sku' => 'B']]];

    expect(
        new Matcher(SearchParser::query('sku:B first:A')->allowedFields($fields))->matches($record),
    )
        ->toBeTrue()
        ->and(new Matcher(SearchParser::query('first:B')->allowedFields($fields))->matches($record))
        ->toBeFalse()
        ->and(new Matcher(SearchParser::query('sku:B')->allowedFields($fields))->matches([
            'items.sku' => null,
            ...$record,
        ]))->toBeFalse();
});

it('reads a single-pass iterable once for all clauses on its field', function (): void {
    $values = (static function (): Generator {
        yield 1;
        yield 2;
    })();
    $matcher = new Matcher(SearchParser::query('value:2 value:1')->allowedFields([
        Field::integer('value'),
    ]));

    expect($matcher->matches(['value' => $values]))->toBeTrue();
});

it('reads a single-pass iterable once for every path through it', function (): void {
    $items = (static function (): Generator {
        yield ['sku' => 'A', 'quantity' => 2];
    })();
    $matcher = new Matcher(SearchParser::query('sku:A quantity:2')->allowedFields([
        Field::string('sku', 'items.sku'),
        Field::integer('quantity', 'items.quantity'),
    ]));

    expect($matcher->matches(['items' => $items]))->toBeTrue();
});

it('reads paths through lists and flattens each level', function (mixed $orders): void {
    $fields = [Field::string('sku', 'orders.items.sku')];
    $record = ['orders' => $orders];

    expect(new Matcher(SearchParser::query('sku:B')->allowedFields($fields))->matches($record))
        ->toBeTrue()
        ->and(new Matcher(SearchParser::query('sku:!=B')->allowedFields($fields))->matches($record))
        ->toBeFalse();
})->with([
    'arrays' => [[
        ['items' => [['sku' => 'A']]],
        ['items' => [['sku' => 'B'], ['sku' => 'C']]],
    ]],
    'collections' => [new ArrayIterator([
        new ArrayObject(['items' => new ArrayIterator([new ArrayObject(['sku' => 'A'])])]),
        (object) ['items' => new ArrayIterator([(object) ['sku' => 'B']])],
    ])],
]);

it('reads whole array keys before paths and only public object properties', function (): void {
    $matcher = new Matcher(SearchParser::query('value:coffee')->allowedFields([
        Field::string('value', 'product.title'),
    ]));
    $records = [
        ['product.title' => 'coffee', 'product' => ['title' => 'tea']],
        ['product.title' => null, 'product' => ['title' => 'coffee']],
        ['product' => ['title' => 'coffee']],
        new ArrayObject(['product.title' => 'coffee']),
        new ArrayObject(['product' => new ArrayObject(['title' => 'coffee'])]),
        (object) ['product' => (object) ['title' => 'coffee']],
        (object) ['product.title' => 'coffee'],
        [
            'product' => new class () {
                protected string $title = 'coffee';
            },
        ],
        ['product' => false],
    ];

    expect(array_keys(array_filter($records, $matcher->matches(...))))->toBe([0, 2, 3, 4, 5]);
});

it('requires both range bounds on one element while allowing separate clauses on different elements', function (): void {
    $fields = [Field::integer('value')];
    $record = ['value' => [1, 20]];

    expect(
        new Matcher(SearchParser::query('value:5..10')->allowedFields($fields))->matches($record),
    )->toBeFalse()
        ->and(
            new Matcher(SearchParser::query('value:<5 value:>10')->allowedFields($fields))->matches(
                $record,
            ),
        )->toBeTrue();
});

it('resolves dates as half-open intervals in the supplied zone', function (
    string $query,
    string $zone,
    array $values,
    array $expected,
): void {
    $fields = [Field::date('value')->allowPartialDates()];
    $timezone = new DateTimeZone($zone);
    $now = new DateTimeImmutable('2024-03-15T12:00:00Z');
    $positive = new Matcher(
        SearchParser::query($query)->allowedFields($fields),
        timezone: $timezone,
        now: $now,
    );
    $negative = new Matcher(
        SearchParser::query('NOT ('.$query.')')->allowedFields($fields),
        timezone: $timezone,
        now: $now,
    );
    $records = array_map(fn (mixed $value): array => ['value' => $value], $values);

    expect(array_keys(array_filter($records, $positive->matches(...))))->toBe($expected);

    foreach ($records as $record) {
        expect($negative->matches($record))->toBe(! $positive->matches($record));
    }

    expect($positive->matches([]))->toBeFalse()->and($negative->matches([]))->toBeTrue();
})->with([
    'local day' => [
        'value:2024-03-10',
        'America/New_York',
        [
            '2024-03-10T04:59:59Z',
            '2024-03-10T05:00:00Z',
            '2024-03-11T03:59:59Z',
            '2024-03-11T04:00:00Z',
        ],
        [1, 2],
    ],
    'explicit offset' => [
        'value:2024-03-10T01:30-05:00',
        'Asia/Tokyo',
        ['2024-03-10T06:30:00Z', '2024-03-10T06:30:59Z', '2024-03-10T06:31:00Z'],
        [0, 1],
    ],
    'stored fractions' => [
        'value:2024-03-15T10:30:00.000001Z',
        'America/New_York',
        [
            '2024-03-15T10:30:00.000000Z',
            '2024-03-15T10:30:00.000001Z',
            '2024-03-15T12:30:00.000001+02:00',
            '2024-03-15T10:30:00.000002Z',
        ],
        [1, 2],
    ],
    'stored local text' => [
        'value:2024-03-15',
        'America/Managua',
        ['2024-03-15 00:00:00', '2024-03-15T05:00:00Z', 'bad date', 2_024],
        [0],
    ],
    'stored words and times' => [
        'value:>2024-01-01',
        'UTC',
        ['', 'tomorrow', 'now', '12:00', '2024', '2024-06-01'],
        [5],
    ],
    'month' => [
        'value:2024-02',
        'UTC',
        ['2024-02-01', '2024-02-29T23:59:59Z', '2024-03-01'],
        [0, 1],
    ],
    'year' => ['value:2024', 'UTC', ['2023-12-31', '2024-12-31', '2025-01-01'], [1]],
    'greater' => ['value:>2024-03-15', 'UTC', ['2024-03-15T23:59:59Z', '2024-03-16', null], [1]],
    'greater or equal' => [
        'value:>=2024-03-15',
        'UTC',
        ['2024-03-14T23:59:59Z', '2024-03-15', '2024-03-16'],
        [1, 2],
    ],
    'less' => ['value:<2024-03-15', 'UTC', ['2024-03-14', '2024-03-15'], [0]],
    'less or equal' => ['value:<=2024-03-15', 'UTC', ['2024-03-15T23:59:59Z', '2024-03-16'], [0]],
    'relative day' => ['value:today-1d', 'UTC', ['2024-03-14', '2024-03-15'], [0]],
    'relative instant' => [
        'value:now',
        'UTC',
        [new DateTimeImmutable('2024-03-15T12:00:00Z'), '2024-03-15T12:00:00.000001Z'],
        [0],
    ],
    'list' => ['value:2024-01,2024-03', 'UTC', ['2024-01-10', '2024-02-10', '2024-03-10'], [0, 2]],
    'relative list' => [
        'value:yesterday,tomorrow',
        'UTC',
        ['2024-03-14', '2024-03-15', '2024-03-16'],
        [0, 2],
    ],
    'range' => [
        'value:2024-01..2024-02',
        'UTC',
        ['2024-01-01', '2024-02-29T23:59:59Z', '2024-03-01'],
        [0, 1],
    ],
    'relative range' => [
        'value:yesterday..today',
        'UTC',
        ['2024-03-14', '2024-03-15T23:59:59Z', '2024-03-16'],
        [0, 1],
    ],
    'open lower' => ['value:*..2024-02', 'UTC', ['2023-01-01', '2024-02-29', '2024-03-01'], [0, 1]],
    'open upper' => ['value:2024-02..*', 'UTC', ['2024-01-31', '2024-02-01', '2025-01-01'], [1, 2]],
    'skipped hour' => [
        'value:2024-03-10T02',
        'America/New_York',
        ['2024-03-10T06:59:59Z', '2024-03-10T07:00:00Z', '2024-03-10T07:30:00Z', null],
        [],
    ],
    'repeated hour' => [
        'value:2024-11-03T01',
        'America/New_York',
        ['2024-11-03T05:30:00Z', '2024-11-03T06:30:00Z'],
        [0],
    ],
    'stored date list' => [
        'value:2024-03-15',
        'UTC',
        [['bad date', new DateTimeImmutable('2024-03-15T12:00:00Z')], [], ['2024-03-16']],
        [0],
    ],
]);

it('treats stored dates outside the absolute day and datetime grammar as incomparable', function (
    string $value,
): void {
    $fields = [Field::date('value')->allowPartialDates()];
    $record = ['value' => $value];

    expect(
        new Matcher(SearchParser::query('value:>2024-01-01')->allowedFields($fields))->matches(
            $record,
        ),
    )
        ->toBeFalse()
        ->and(
            new Matcher(SearchParser::query('value:!=2024')->allowedFields($fields))->matches(
                $record,
            ),
        )
        ->toBeTrue();
})->with([
    'empty' => '',
    'time' => '12:00',
    'word' => 'tomorrow',
    'relative day' => 'today-1d',
    'relative instant' => 'now',
    'year' => '2024',
    'month' => '2024-06',
    'impossible date' => '2024-02-30',
    'PHP modifier' => '2024-03-01 +1 year',
]);

it('treats stringable collections as lists of alternatives', function (): void {
    $values = new class () implements IteratorAggregate, Stringable {
        public function getIterator(): Traversable
        {
            return new ArrayIterator(['first' => 'a', 'second' => 'b']);
        }

        public function __toString(): string
        {
            return '["a","b"]';
        }
    };
    $fields = [Field::string('value')];
    $record = ['value' => $values];

    expect(
        new Matcher(SearchParser::query('value:b value:a')->allowedFields($fields))->matches(
            $record,
        ),
    )
        ->toBeTrue()
        ->and(
            new Matcher(SearchParser::query('value:!=b')->allowedFields($fields))->matches($record),
        )
        ->toBeFalse();
});

it('uses the default timezone when none is supplied', function (): void {
    $previous = date_default_timezone_get();

    date_default_timezone_set('Asia/Tokyo');

    try {
        $matcher = new Matcher(
            SearchParser::query('created:2024-03-15')->allowedFields([Field::date('created')]),
        );

        expect($matcher->matches(['created' => '2024-03-14T15:00:00Z']))->toBeTrue()
            ->and($matcher->matches(['created' => '2024-03-15T15:00:00Z']))->toBeFalse();
    } finally {
        date_default_timezone_set($previous);
    }
});
