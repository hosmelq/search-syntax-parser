<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\Adapter\ArrayAdapter;
use HosmelQ\SearchSyntaxParser\AST\Node\ComparisonNode;
use HosmelQ\SearchSyntaxParser\AST\Node\ExistsNode;
use HosmelQ\SearchSyntaxParser\AST\Node\InNode;
use HosmelQ\SearchSyntaxParser\AST\Node\NodeInterface;
use HosmelQ\SearchSyntaxParser\AST\Node\UnaryOperatorNode;
use HosmelQ\SearchSyntaxParser\Exception\ParseException;
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;

it('applies defaults at the AST level in configured order', function (): void {
    $parser = SearchParser::query('coffee')->allowedFields([
        AllowedField::integer('age', 'user_age')->default(18),
        AllowedField::array('status')->default(['active', 'draft']),
        AllowedField::string('deleted')->default(null),
    ]);

    expect($parser->build('string'))->toBe(
        'coffee',
    )
        ->and($parser->build())->toBe(new ArrayAdapter($parser)->build($parser->parse()))
        ->and($parser->build()['left']['left']['right']['field'])->toBe('user_age')
        ->and($parser->build()['left']['left']['right']['default'])->toBeTrue();
});

it('supports every scalar default and singleton lists', function (
    mixed $default,
    array $expected,
): void {
    expect(
        SearchParser::query('')
            ->allowedFields([new AllowedField('value')->default($default)])
            ->build(),
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

it('uses defaults for empty and whitespace-only queries', function (string $query): void {
    $parser = SearchParser::query($query)
        ->allowedFields([AllowedField::string('status')->default('active')]);

    expect($parser->parse()->getValue())->toBe('active')
        ->and($parser->build()['default'])->toBeTrue()
        ->and($parser->build('string'))->toBe('');
})->with(['', '   ', "\n\t", "\u{00A0}"]);

it('omits defaults for fields referenced anywhere in the AST', function (string $query): void {
    $parser = SearchParser::query($query)->allowedFields([
        new AllowedField('status')->default('fallback'),
    ]);

    expect($parser->build())->toBe(SearchParser::query($query)->allowAnyField()->build());
})->with([
    'status:active', 'NOT status:active', 'status:active,draft', 'status:[a TO z]',
    'status:*', 'NOT status:*', 'status:act*', 'status:act*,draft',
    'coffee OR (tea AND NOT status:active)',
]);

it('uses external names when deciding whether a default applies', function (): void {
    $parser = SearchParser::query('status:active')->allowedFields([
        AllowedField::string('status', 'stored_status')->default('fallback'),
        AllowedField::string('stored_status', 'other_status')->default('draft'),
    ]);

    expect($parser->build()['left']['field'])->toBe('stored_status')
        ->and($parser->build()['right']['field'])->toBe('other_status')
        ->and($parser->build()['right']['value'])->toBe('draft')
        ->and($parser->build('string'))->toBe('status:active');
});

it('applies valid defaults without clause and depth limits', function (): void {
    $parser = SearchParser::query('coffee')->maxDepth(1)->maxClauses(1)->allowedFields([
        AllowedField::integer('age')->min(99)->default(100),
        AllowedField::array('tags')->size(2)->default(['a', 'b']),
        AllowedField::string('missing')->default(null),
    ]);

    expect($parser->parse()->getRight()->getOperand()->getField())->toBe('missing')
        ->and($parser->build()['left']['left']['right']['value'])->toBe(100)
        ->and($parser->build('string'))->toBe('coffee');
});

it('still checks the raw query length when defaults exist', function (): void {
    SearchParser::query('    ')
        ->maxLength(3)
        ->allowedFields([AllowedField::string('status')->default('active')])
        ->parse();
})->throws(ParseException::class, 'maximum length');

it('observes defaults configured after allowedFields', function (): void {
    $field = AllowedField::string('status');
    $parser = SearchParser::query('')->allowedFields([$field]);

    $field->default('active');

    expect($parser->parse()->getValue())->toBe('active')->and($parser->build('string'))->toBe('');
});

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

it('supports defaults on numeric external field names', function (): void {
    $parser = SearchParser::query('')->allowedFields([AllowedField::integer('123')->default(7)]);

    expect($parser->parse()->getField())->toBe('123')
        ->and($parser->parse()->getValue())->toBe(7)
        ->and($parser->build()['field'])->toBe('123');
});

it('rejects defaults that fail field validation when they apply', function (
    AllowedField $field,
): void {
    expect(fn (): null|NodeInterface => SearchParser::query('')->allowedFields([$field])->parse())
        ->toThrow(InvalidArgumentException::class, "Invalid default for field 'status'.");
})->with([
    'membership' => [AllowedField::in('status', ['open', 'closed'])->default('ACTVE')],
    'type' => [AllowedField::integer('status')->default('abc')],
    'size' => [AllowedField::string('status')->min(3)->default('ab')],
    'collection size' => [AllowedField::array('status')->size(2)->default(['open'])],
    'item type' => [
        AllowedField::array('status')->each(fn ($rules) => $rules->integer())->default(['abc']),
    ],
    'item position' => [
        AllowedField::array('status')->at(1, fn ($rules) => $rules->min(5))->default([1, 2]),
    ],
    'scalar on sized array' => [AllowedField::array('status')->size(2)->default('open')],
    'list item on scalar field' => [
        AllowedField::string('status')->min(3)->default(['open', 'ab']),
    ],
]);

it('validates defaults like the equivalent query clause', function (
    AllowedField $field,
    string $query,
): void {
    $default = SearchParser::query('')->allowedFields([$field])->build();
    $typed = SearchParser::query($query)->allowedFields([$field])->build();

    expect(array_diff_key($default, ['default' => true]))
        ->toBe(array_diff_key($typed, ['default' => true]));
})->with([
    'list on in field' => [
        AllowedField::in('status', ['open', 'pending', 'closed'])->default(['open', 'pending']),
        'status:open,pending',
    ],
    'list on sized string field' => [
        AllowedField::string('region')->min(3)->default(['north', 'south']),
        'region:north,south',
    ],
    'scalar on array field' => [AllowedField::array('tags')->default('php'), 'tags:php'],
]);

it('validates defaults with the complete collection and internal field name', function (): void {
    $values = ['open', 'closed'];
    $field = AllowedField::array('status', 'stored_status')
        ->size(2)
        ->each(fn ($rules) => $rules->in($values))
        ->default($values);
    $callback = AllowedField::callback(
        'region',
        fn ($value, $name): bool => $value === 'north' && $name === 'stored_region',
        'stored_region',
    )->default('north');

    expect(SearchParser::query('')->allowedFields([$field, $callback])->build()['left']['values'])
        ->toBe($values);
});

it('validates defaults only when applied and observes rules added after configuration', function (): void {
    $field = AllowedField::string('status')->default('open');
    $parser = SearchParser::query('')->allowedFields([$field]);

    $field->min(5);

    expect(fn (): null|NodeInterface => $parser->parse())->toThrow(
        InvalidArgumentException::class,
        'status',
    )
        ->and(
            SearchParser::query('status:closed')->allowedFields([$field])->parse()->getValue(),
        )->toBe(
            'closed',
        );
});

it('accepts null defaults for typed and array fields without running their value rules', function (
    AllowedField $field,
): void {
    $node = SearchParser::query('')->allowedFields([$field->default(null)])->parse();

    expect($field->isNullable())->toBeTrue()
        ->and($field->validate(null))->toBeTrue()
        ->and($node->isDefault())->toBeTrue()
        ->and($node->getOperand()->getField())->toBe('status');
})->with([AllowedField::integer('status')->min(1), AllowedField::array('status')->size(2)]);

it('distinguishes default clauses from parsed and spanless programmatic clauses', function (): void {
    foreach ([
        [42, 'status:42', new ComparisonNode('status', '=', 42)],
        [[1, 2], 'status:1,2', new InNode('status', '=', [1, 2])],
        [null, 'NOT status:*', new UnaryOperatorNode('NOT', new ExistsNode('status'))],
    ] as [$value, $query, $manual]) {
        $default = SearchParser::query('')
            ->allowedFields([new AllowedField('status')->default($value)])
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

it('reads string defaults like typed query text', function (
    AllowedField $field,
    bool|float|int|string $expected,
): void {
    expect(SearchParser::query('')->allowedFields([$field])->parse()->getValue())->toBe($expected);
})->with([
    'integer' => [AllowedField::integer('age')->default('18'), 18],
    'boolean' => [AllowedField::boolean('active')->default('true'), true],
    'membership' => [AllowedField::in('level', [1, 2])->default('1'), 1],
    'alias' => [
        AllowedField::integer('assignee')->alias('@me', static fn (): int => 7)->default('@me'),
        7,
    ],
    'typed scalar' => [AllowedField::integer('age')->default(21), 21],
]);

it('casts list default items by position on array fields', function (): void {
    $field = AllowedField::array('point')
        ->at(0, fn ($rules) => $rules->numeric())
        ->at(1, fn ($rules) => $rules->integer())
        ->default(['1.5', '2']);

    expect(SearchParser::query('')->allowedFields([$field])->parse()->getValues())->toBe([1.5, 2]);
});

it('requires non-string defaults to already have the field type', function (): void {
    expect(fn (): null|NodeInterface => SearchParser::query('')->allowedFields([
        AllowedField::string('code')->default(1),
    ])->parse())->toThrow(InvalidArgumentException::class, "Invalid default for field 'code'.");
});

it('flags both nodes of a null default', function (): void {
    $node = SearchParser::query('')
        ->allowedFields([AllowedField::string('deleted')->default(null)])
        ->parse();

    expect($node)->toBeInstanceOf(UnaryOperatorNode::class)
        ->and($node->isDefault())->toBeTrue()
        ->and($node->getOperand()->isDefault())->toBeTrue();
});

it('rejects non-string defaults whose type the field never produces from text', function (
    AllowedField $field,
): void {
    expect(fn (): null|NodeInterface => SearchParser::query('')->allowedFields([$field])->parse())
        ->toThrow(InvalidArgumentException::class, "Invalid default for field 'value'.");
})->with([
    'bool on integer' => [AllowedField::integer('value')->default(true)],
    'float on integer' => [AllowedField::integer('value')->default(18.0)],
    'int on boolean' => [AllowedField::boolean('value')->default(1)],
    'loose member' => [AllowedField::in('value', ['1', '2'])->default(1)],
    'list item' => [
        AllowedField::array('value')
            ->each(fn ($rules) => $rules->integer())
            ->default([1, '2', true]),
    ],
]);

it('accepts non-string defaults that already have the cast type', function (
    AllowedField $field,
    mixed $expected,
): void {
    $node = SearchParser::query('')->allowedFields([$field])->parse();

    expect($node instanceof InNode ? $node->getValues() : $node->getValue())->toBe($expected);
})->with([
    'int on numeric' => [AllowedField::numeric('value')->default(3), 3],
    'float on numeric' => [AllowedField::numeric('value')->default(2.5), 2.5],
    'bool on boolean' => [AllowedField::boolean('value')->default(false), false],
    'strict member' => [AllowedField::in('value', [1, 2])->default(2), 2],
    'positional list' => [
        AllowedField::array('value')
            ->at(0, fn ($rules) => $rules->integer())
            ->at(1, fn ($rules) => $rules->string())
            ->default([1, 'b']),
        [1, 'b'],
    ],
    'untyped' => [new AllowedField('value')->default(true), true],
]);
