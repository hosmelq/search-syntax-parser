<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\Adapter\StringAdapter;
use HosmelQ\SearchSyntaxParser\AST\Node\BinaryOperatorNode;
use HosmelQ\SearchSyntaxParser\AST\Node\ComparisonNode;
use HosmelQ\SearchSyntaxParser\AST\Node\RangeNode;
use HosmelQ\SearchSyntaxParser\AST\Node\TermNode;
use HosmelQ\SearchSyntaxParser\AST\Node\UnaryOperatorNode;
use HosmelQ\SearchSyntaxParser\AST\Node\WildcardNode;
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Sorting\AllowedSort;
use HosmelQ\SearchSyntaxParser\Validation\Field;
use HosmelQ\SearchSyntaxParser\Validation\FieldItemRules;

it('refuses field names the query syntax cannot express', function (string $name): void {
    new StringAdapter()->build(new ComparisonNode($name, '=', 'x'));
})->throws(LogicException::class, 'This field name cannot be expressed by the query syntax.')
    ->with(['my field', 'AND', '-x', '*', 'a:b']);

it('rejects non-finite values in programmatic trees', function (float $value): void {
    new StringAdapter()->build(new ComparisonNode('price', '=', $value));
})->throws(LogicException::class, 'Non-finite floats cannot be expressed by the query syntax.')
    ->with([INF, -INF, NAN]);

it('rejects wildcard segments that the grammar cannot express', function (array $segments): void {
    new StringAdapter()->build(new WildcardNode('name', '=', $segments));
})->throws(LogicException::class)->with([
    [['a', 'b c']], [['a b', 'c']], [['a*b', 'c']], [['a']], [[]], [['a', '', 'b']],
]);

it('appends directives before the typed sort and groups an OR filter root', function (): void {
    $parser = SearchParser::query('sort:created-desc limit:25 mode:only (coffee OR tea)')
        ->allowedFields([
            Field::string('mode')->directive(),
            Field::integer('limit')->directive(),
        ])
        ->allowedSorts(['created']);

    expect($parser->build('string'))->toBe(
        '(coffee OR tea) AND mode:only AND limit:25 AND sort:created-desc',
    );
});

it('keeps a minus before dots bare when the parser reads it as literal text', function (): void {
    $fields = static fn (): array => [Field::string('p')->prefix()];
    $parser = SearchParser::query('p:-..5')->allowedFields($fields());

    expect($parser->build('string'))->toBe('p:-..5')
        ->and(SearchParser::query('p:-..5')->allowedFields($fields())->build())->toBe(
            $parser->build(),
        )
        ->and(SearchParser::query('p:"-5..1"')->allowedFields($fields())->build('string'))->toBe(
            'p:"-5..1"',
        );
});

it('keeps datetime-like field names apart from their colon', function (
    string $query,
    string $expected,
): void {
    $parser = SearchParser::query($query)->allowAnyField();
    $text = $parser->build('string');

    expect($text)->toBe($expected)
        ->and(SearchParser::query($text)->allowAnyField()->build())->toBe($parser->build());
})->with([
    ['2026-01-01T12 :12', '2026-01-01T12 :12'],
    ['2026-01-01T١٢ :12', '2026-01-01T١٢ :12'],
    ['2026-01-01T12:30+05 :30', '2026-01-01T12:30+05 :30'],
    ['2026-01-01T12:30 :*', '2026-01-01T12:30 :*'],
]);

it('normalizes queries into explicit syntax', function (string $query, string $expected): void {
    expect(
        SearchParser::query($query)->allowAnyField()->allowLeadingWildcards()->build('string'),
    )->toBe(
        $expected,
    );
})->with([
    ['  a b ', 'a AND b'],
    ['-name:coffee', 'NOT name:coffee'],
    ['(a OR b) c', '(a OR b) AND c'],
    ['a OR b AND c', 'a OR b AND c'],
    ['NOT (a b)', 'NOT (a AND b)'],
    ['NOT -a', 'NOT NOT a'],
    ['(a AND b) OR c', 'a AND b OR c'],
    ['name!=a', 'name:!=a'],
    ['name:"a b"*', 'name:"a b"*'],
    ['name:"Co"*', 'name:Co*'],
    ['name:a**b***c', 'name:a*b*c'],
    ['***', '""*'],
    ['name:***', 'name:""*'],
    ['name:"a*b"*', 'name:"a*b"*'],
    ['name:"1..2"*', 'name:"1..2"*'],
    ['price:[* TO 10.00]', 'price:[* TO 10.0]'],
    ['flag:TRUE', 'flag:true'],
    ['flag:"TRUE"', 'flag:"TRUE"'],
    ['code:"123"', 'code:"123"'],
    ['code:01234', 'code:01234'],
    ['name:"AND"', 'name:"AND"'],
    ['"123"', '"123"'],
    ['name:"-foo"', 'name:"-foo"'],
    ['name:""', 'name:""'],
]);

it('omits defaults inside programmatic boolean trees without leaving dangling operators', function (): void {
    $default = new ComparisonNode('status', '=', 'open', default: true);
    $term = new TermNode('coffee');
    $adapter = new StringAdapter();

    foreach (['AND', 'OR'] as $operator) {
        expect($adapter->build(new BinaryOperatorNode($operator, $default, $term)))->toBe('coffee')
            ->and($adapter->build(new BinaryOperatorNode($operator, $term, $default)))->toBe(
                'coffee',
            );
    }

    expect($adapter->build(new UnaryOperatorNode('NOT', $default)))->toBeEmpty();
});

it('omits every default clause while preserving explicit filters', function (
    string $query,
    string $expected,
): void {
    $parser = SearchParser::query($query)->allowedFields([
        'title',
        Field::string('status')->default('open'),
        Field::array('tags')->default(['a', 'b']),
        Field::string('deleted')->default(null),
    ]);

    expect($parser->build('string'))->toBe($expected);
})->with([
    ['', ''], ['title:mug', 'title:mug'], ['title:mug OR title:cup', 'title:mug OR title:cup'],
    ['status:open', 'status:open'], ['NOT deleted:*', 'NOT deleted:*'],
]);

it('parses compact bounds like bracket ranges and serializes them with brackets', function (
    string $bounds,
    mixed $from,
    mixed $to,
): void {
    $parser = SearchParser::query('x:'.$bounds)->allowAnyField();
    $node = $parser->parse();

    [$left, $right] = explode('..', $bounds, 2);

    $bracket = SearchParser::query('x:['.$left.' TO '.$right.']')->allowAnyField();

    expect($node)->toBeInstanceOf(RangeNode::class)
        ->and($node->getFrom())->toBe($from)
        ->and($node->getTo())->toBe($to)
        ->and($parser->build())->toBe($bracket->build())
        ->and($parser->build('string'))->toBe($bracket->build('string'))
        ->and($node->getSpan()->offset)->toBe(0)
        ->and($node->getSpan()->length)->toBe(mb_strlen('x:'.$bounds));
})->with([
    ['1..5', 1, 5],
    ['1.5..2.75', 1.5, 2.75],
    ['-5..5', -5, 5],
    ['-5..-1', -5, -1],
    ['-2.5..-0.5', -2.5, -0.5],
    ['10..*', 10, null],
    ['*..20', null, 20],
    ['*..*', null, null],
    ['2024-01-01..2024-03-31', '2024-01-01', '2024-03-31'],
    ['2024-01-01T10:00..2024-01-01T12:00', '2024-01-01T10:00', '2024-01-01T12:00'],
    [
        '2024-01-01T10:30:45.123+05:30..2024-01-01T12:30:45.999Z',
        '2024-01-01T10:30:45.123+05:30',
        '2024-01-01T12:30:45.999Z',
    ],
    ['*..2024-01-01T12:00', null, '2024-01-01T12:00'],
    ['2024-01-01T10:00..*', '2024-01-01T10:00', null],
    ['2024..2024-06', 2_024, '2024-06'],
    ['-30d..now', '-30d', 'now'],
    ['-30d..2024-01-01T12:00', '-30d', '2024-01-01T12:00'],
]);

it('preserves external field names with every field node', function (string $query): void {
    $field = Field::string('name', 'full_name');
    $parser = SearchParser::query($query)->allowedFields([$field]);
    $text = $parser->build('string');

    expect($text)->not->toContain('full_name')
        ->and(SearchParser::query($text)->allowedFields([$field])->build())->toBe($parser->build());
})->with(['name:coffee', 'name:a,b', 'name:[a TO z]', 'name:*', 'name:jo*']);

it('preserves field value quotes through parsing and string serialization', function (
    string $query,
    bool $quoted,
    string $text,
): void {
    $parser = SearchParser::query($query)->allowedFields([Field::string('title')]);

    expect($parser->parse()->isQuoted())->toBe($quoted)
        ->and($parser->build()['quoted'])->toBe($quoted)
        ->and($parser->build('string'))->toBe($text);
})->with([
    ['title:mug', false, 'title:mug'],
    ['title:"mug"', true, 'title:"mug"'],
    ["title:'mug'", true, 'title:"mug"'],
    ['title>"mug"', true, 'title:>"mug"'],
]);

it('preserves non-exact modes when serializing numeric-looking strings and keyword values', function (): void {
    $field = Field::string('title')->prefix();
    $parser = SearchParser::query('title:123,true,AND,=5')->allowedFields([$field]);
    $text = $parser->build('string');

    expect($text)->toBe('title:123,true,AND,=5')
        ->and(SearchParser::query($text)->allowedFields([$field])->build())->toBe($parser->build());
});

it('preserves precedence when omitting defaults exposes a nested boolean operator', function (): void {
    $default = new ComparisonNode('status', '=', 'open', default: true);
    $alternative = new BinaryOperatorNode('OR', new TermNode('coffee'), new TermNode('tea'));
    $left = new BinaryOperatorNode('AND', $default, $alternative);
    $right = new BinaryOperatorNode('AND', $alternative, $default);
    $adapter = new StringAdapter();

    foreach ([$left, $right] as $node) {
        expect($adapter->build(new BinaryOperatorNode('AND', $node, new TermNode('mug'))))
            ->toBe('(coffee OR tea) AND mug')
            ->and($adapter->build(new UnaryOperatorNode('NOT', $node)))
            ->toBe('NOT (coffee OR tea)');
    }
});

it('preserves quoted terms in array output and string round trips', function (
    string $query,
    string $value,
    bool $quoted,
): void {
    $parser = SearchParser::query($query);
    $node = $parser->parse();
    $text = $parser->build('string');
    $roundTrip = SearchParser::query($text)->parse();

    expect($node->getValue())->toBe($value)
        ->and($node->isQuoted())->toBe($quoted)
        ->and($parser->build())->toBe(['quoted' => $quoted, 'type' => 'term', 'value' => $value])
        ->and($roundTrip->getValue())->toBe($value)
        ->and($roundTrip->isQuoted())->toBe($quoted);
})->with([
    ['coffee', 'coffee', false], ['007', '007', false], ['123', '123', false],
    ['TRUE', 'TRUE', false], ['12.50', '12.50', false], ['"Cold Brew"', 'Cold Brew', true],
    ['"coffee"', 'coffee', true], ["'007'", '007', true], ['""', '', true],
    ['"co*fee"', 'co*fee', true], ['"a\\"b"', 'a"b', true],
]);

it('quotes comparison values that equal an alias token so they stay literal', function (): void {
    $fields = static fn (): array => [Field::integer('level')->alias('7', 9)];
    $parser = SearchParser::query('level:"7"')->allowedFields($fields());

    expect($parser->parse()->getValue())->toBe(7)
        ->and($parser->build('string'))->toBe('level:"7"')
        ->and(SearchParser::query('level:"7"')->allowedFields($fields())->build())->toBe(
            $parser->build(),
        );
});

it('quotes programmatic comparison values that would be read as compact ranges', function (): void {
    $text = new StringAdapter()->build(new ComparisonNode('title', '=', 'a..b'));
    $node = SearchParser::query($text)->allowAnyField()->parse();

    expect($text)->toBe('title:"a..b"')
        ->and($node)->toBeInstanceOf(ComparisonNode::class)
        ->and($node->getValue())->toBe('a..b');
});

it('quotes programmatic terms whose text needs literal syntax', function (string $value): void {
    $text = new StringAdapter()->build(new TermNode($value));

    expect(SearchParser::query($text)->parse()->getValue())->toBe($value);
})->with(['', 'a b', '*', 'co*fee', 'AND', '-coffee']);

it('renders typed settings without any filter tree and omits defaults', function (): void {
    $parser = SearchParser::query('sort:created mode:only')->allowedFields([
        Field::string('mode')->directive(),
        Field::boolean('archived')->directive()->default(false),
    ])->allowedSorts(['created']);

    expect($parser->build('string'))->toBe('mode:only AND sort:created-asc')
        ->and($parser->parse())->toBeNull()
        ->and($parser->build())->toBeNull()
        ->and(new StringAdapter($parser)->build(null))->toBe('mode:only AND sort:created-asc');
});

it('round trips date expression text and quotes unchanged', function (string $query): void {
    $fields = [
        Field::date('created')->allowPartialDates(),
        Field::date('due'),
        Field::array('dates')->each(
            fn (FieldItemRules $rules): FieldItemRules
                => $rules->date()->allowPartialDates(),
        ),
    ];
    $parser = SearchParser::query($query)->allowedFields($fields);
    $text = $parser->build('string');

    expect($text)->toBe($query)
        ->and(SearchParser::query($text)->allowedFields($fields)->build())->toBe($parser->build());
})->with([
    'created:2024', 'created:2024-03', 'created:[2023 TO 2024-06]',
    'created:>-7d', 'due:<today', 'created:<=now-1h', 'created:NOW+1M',
    'created:[-30d TO now]', 'created:[now-7d TO -1d]', 'created:[* TO tomorrow]',
    'created:[today-1M TO *]', 'created:-7d,+3d,ToDaY,now+1m', 'created:"TODAY-7d"',
    'created:2024-03-15T10+05:30', 'dates:2024,-7d,tomorrow+1w',
]);

it('round trips directives whose public names become numeric PHP array keys', function (): void {
    $field = Field::integer('123')->directive();
    $parser = SearchParser::query('123:7')->allowedFields([$field]);
    $text = $parser->build('string');
    $reparsed = SearchParser::query($text)->allowedFields([$field]);

    expect($text)->toBe('123:7')
        ->and($reparsed->parse())->toBeNull()
        ->and($reparsed->getDirectives())->toBe([123 => 7]);
});

it('round trips filters, scalar directives, and sorting in stable declaration order', function (
    string $query,
): void {
    $configure = static fn (string $input): SearchParser
        => SearchParser::query($input)->allowedFields([
            Field::string('mode')->directive()->alias('mine', 'only')->default('without'),
            Field::integer('limit')->directive()->default(20),
            Field::boolean('archived')->directive()->default(false),
            new Field('value')->directive(),
            Field::string('status')->default('open'),
        ])->allowedSorts([
            AllowedSort::field('created-at', 'created_at'),
            AllowedSort::field('updated-at', 'updated_at'),
        ])->defaultSort('updated-at');
    $original = $configure($query);
    $text = $original->build('string');
    $reparsed = $configure($text);

    expect($reparsed->build())->toBe($original->build())
        ->and($reparsed->getDirectives())->toBe($original->getDirectives())
        ->and($reparsed->getTypedDirectives())->toBe($original->getTypedDirectives())
        ->and($reparsed->getSort())->toEqual($original->getSort())
        ->and($reparsed->build('string'))->toBe($text);
})->with([
    'sort:CREATED-AT-DESC mode:mine limit:25 (coffee OR tea)',
    '(coffee OR tea) AND (mode:"only" AND archived:TRUE)',
    'value:"123" mode:"a*,b..c"',
    'value:1.5 limit:-1',
    'value:false',
    'value:""',
    'mode:"mine"',
    'sort:updated-at-asc mode:only',
    'coffee',
]);

it('round trips observable array output', function (string $query): void {
    $parser = SearchParser::query($query)->allowAnyField()->allowLeadingWildcards();

    expect(
        SearchParser::query($parser->build('string'))
            ->allowAnyField()
            ->allowLeadingWildcards()
            ->build(),
    )->toBe(
        $parser->build(),
    );
})->with([
    'a AND b OR NOT c', 'a AND (b AND c)', 'a OR (b OR c)',
    '(a OR b) AND (c OR d)', 'a OR b AND (c OR d)',
    'NOT (a OR b AND c)', 'NOT NOT a', 'name:*', 'NOT name:*',
    'name:a,b,"c d"', 'name:!=a,b', 'name:jo*,"literal*",42',
    'name:!=jo*,bob', 'price:[-10 TO 50]', 'price:[* TO *]',
    'name:["AND" TO "OR"]', 'name:"co*fee"', '"co*fee"',
    'co*fee', '*fee', 'nike*', '***', '"a b"*', '"a*b"*', '""*',
    'name:"a\\\\b\\"c"', 'name:"a\\\'b"', 'nombre:José',
    'created_at:>=2025-01-01T12:30:45.123Z', 'name:and,OR,Not',
    'value:1.0', 'value:-0.5', 'value:0.000000001',
    'value:100000000000000000000.0', 'value:99999999999999999999',
    'value:true', 'value:false', 'value:"true"', 'value:"1.0"',
    '"a\nb"', 'email:john@example.com', "name:O'Brien", 'name:hello!world', 'a*b!c', 'a*b\\c', 'a*b"c',
]);

it('round trips resolved directive values that also name aliases without resolving them again', function (): void {
    $calls = 0;
    $field = Field::integer('limit')->directive()->alias('25', 50)->alias(
        'mine',
        function () use (&$calls): int {
            $calls++;

            return 25;
        },
    );
    $parser = SearchParser::query('limit:mine')->allowedFields([$field]);
    $text = $parser->build('string');

    expect($text)->toBe('limit:"25"')->and($calls)->toBe(1);

    $reparsed = SearchParser::query($text)->allowedFields([$field]);

    expect($reparsed->parse())->toBeNull()->and($reparsed->getDirectives())->toBe(['limit' => 25]);
});

it('separates unquoted values that would merge with a comparison operator', function (
    string $query,
    string $expected,
): void {
    $parser = SearchParser::query($query)->allowAnyField();
    $text = $parser->build('string');

    expect($text)->toBe($expected)
        ->and(SearchParser::query($text)->allowAnyField()->build())->toBe($parser->build());
})->with([
    ['x:< =5', 'x:< =5'],
    ['x:> =a', 'x:> =a'],
    ['x:=5', 'x:=5'],
    ['x:> =a,=b', 'x:> =a,=b'],
]);

it('serializes alias tokens without running alias closures', function (): void {
    $field = Field::string('owner')->alias(
        'me',
        static fn (): never
            => throw new LogicException('Alias closures must not run while serializing.'),
    );

    expect(SearchParser::query('owner:"me" owner:other')->allowedFields([$field])->build('string'))
        ->toBe('owner:"me" AND owner:other');
});

it('serializes floats without changing their inferred type or value', function (
    float $value,
): void {
    $adapter = new StringAdapter();
    $text = $adapter->build(new ComparisonNode('value', '=', $value));

    expect(SearchParser::query($text)->allowAnyField()->build()['value'])->toBe($value);
})->with([0.0, -0.0, 1.0, 1.5, 1e-20, -1e-20, 1e20, PHP_FLOAT_MAX, PHP_FLOAT_MIN, 5e-324]);

it('writes unquoted values bare when the field reads them back unchanged', function (
    string $query,
): void {
    $fields = static fn (): array => [
        Field::string('title'),
        Field::string('name')->prefix(),
        Field::decimal('price', 0, 2),
        Field::in('status', ['1', '2', 'open']),
        new Field('code'),
    ];
    $parser = SearchParser::query($query)->allowedFields($fields());
    $text = $parser->build('string');

    expect($text)->toBe($query)
        ->and(SearchParser::query($text)->allowedFields($fields())->build())->toBe(
            $parser->build(),
        );
})->with([
    'number on string field' => ['title:123'],
    'boolean word on string field' => ['title:true'],
    'leading hyphen' => ['title:-x'],
    'decimal text' => ['price:10.50'],
    'numeric member' => ['status:1'],
    'negative number on prefix field' => ['name:-5'],
    'hyphenated list item' => ['name:a,-b'],
    'untyped leading zeros' => ['code:007'],
    'untyped quoted number' => ['code:"123"'],
    'range bounds' => ['price:[1.00 TO 10.50]'],
]);
