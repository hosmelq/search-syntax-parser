<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\Adapter\ArrayAdapter;
use HosmelQ\SearchSyntaxParser\AST\Node\BinaryOperatorNode;
use HosmelQ\SearchSyntaxParser\AST\Node\ComparisonNode;
use HosmelQ\SearchSyntaxParser\AST\Node\ExistsNode;
use HosmelQ\SearchSyntaxParser\AST\Node\InNode;
use HosmelQ\SearchSyntaxParser\AST\Node\RangeNode;
use HosmelQ\SearchSyntaxParser\AST\Node\TermNode;
use HosmelQ\SearchSyntaxParser\AST\Node\UnaryOperatorNode;
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\Field;
use HosmelQ\SearchSyntaxParser\Validation\FieldItemRules;
use HosmelQ\SearchSyntaxParser\Validation\TextMatch;

it('allows existence and converts quoted literal asterisks', function (): void {
    $field = Field::string('value')->map(['*' => 1]);

    expect(SearchParser::query('value:*')->allowedFields([$field])->build()['type'])->toBe('exists')
        ->and(SearchParser::query('value:"*"')->allowedFields([$field])->build()['value'])->toBe(1);
});

it('builds complex query with precedence and grouping', function (): void {
    $result = SearchParser::query(
        'status:ACTIVE,DRAFT AND price:>100 OR (category:* AND -discount:*)',
    )->allowAnyField()->build();

    expect($result)->toEqual([
        'left' => [
            'left' => [
                'default' => false,
                'field' => 'status',
                'match' => ['exact', 'exact'],
                'operator' => '=',
                'quoted' => [false, false],
                'type' => 'in',
                'values' => ['ACTIVE', 'DRAFT'],
            ],
            'operator' => 'AND',
            'right' => [
                'default' => false,
                'field' => 'price',
                'match' => 'exact',
                'operator' => '>',
                'quoted' => false,
                'type' => 'comparison',
                'value' => 100,
            ],
            'type' => 'binary',
        ],
        'operator' => 'OR',
        'right' => [
            'left' => [
                'default' => false,
                'field' => 'category',
                'type' => 'exists',
            ],
            'operator' => 'AND',
            'right' => [
                'default' => false,
                'operand' => [
                    'default' => false,
                    'field' => 'discount',
                    'type' => 'exists',
                ],
                'operator' => 'NOT',
                'type' => 'unary',
            ],
            'type' => 'binary',
        ],
        'type' => 'binary',
    ]);
});

it('converts list items with their written positions after ignored items are removed', function (): void {
    $field = Field::array('amount')
        ->each(fn (FieldItemRules $rules): FieldItemRules => $rules->string())
        ->at(0, fn (FieldItemRules $rules): FieldItemRules => $rules->decimal(0, 2))
        ->ignore('0')
        ->map([7 => 'seven']);
    $ignored = SearchParser::query('amount:0,hello')->allowedFields([$field])->parse();

    expect($ignored->getPositions())->toBe([1])
        ->and(SearchParser::query('amount:0,hello')->allowedFields([$field])->build()['values'])
        ->toBe(['hello'])
        ->and(SearchParser::query('amount:0,7.00')->allowedFields([$field])->build()['values'])
        ->toBe(['7.00'])
        ->and(SearchParser::query('amount:7.00,7.00')->allowedFields([$field])->build()['values'])
        ->toBe(['seven', '7.00'])
        ->and(SearchParser::query('amount:!=0,7.00')->allowedFields([$field])->build()['values'])
        ->toBe(['7.00']);
});

it('exposes effective text matches for comparisons and mixed quoted lists', function (
    string $mode,
    TextMatch $expected,
): void {
    $field = Field::string('title')->$mode();
    $parser = SearchParser::query('title:mug,"cup",plate')->allowedFields([$field]);
    $list = $parser->parse();

    expect($field->getTextMatch($list, 0))->toBe($expected)
        ->and($field->getTextMatch($list, 1))->toBe(TextMatch::Exact)
        ->and($parser->build()['match'])->toBe([$expected->value, 'exact', $expected->value])
        ->and(SearchParser::query('title:mug')->allowedFields([$field])->build()['match'])->toBe(
            $expected->value,
        )
        ->and(SearchParser::query('title:"mug"')->allowedFields([$field])->build()['match'])->toBe(
            'exact',
        );
})->with([
    ['prefix', TextMatch::Prefix], ['contains', TextMatch::Contains], ['words', TextMatch::Words],
]);

it('keeps default text values unquoted and explicit wildcard patterns independent', function (): void {
    $field = Field::string('title')->words()->default('mug');
    $parser = SearchParser::query('title:mu*,"cup"')->allowedFields([$field]);

    expect(SearchParser::query('')->allowedFields([$field])->build()['match'])->toBe('words')
        ->and($parser->build()['left']['type'])->toBe('wildcard')
        ->and($parser->build()['left'])->not->toHaveKey('match')
        ->and($parser->build()['right']['match'])->toBe('exact');
});

it('keeps user values in the AST and string output while converting comparisons lists and ranges', function (): void {
    $field = Field::decimal('price', 2, internalName: 'unit_price')->max(11)->scale(100);
    $comparison = SearchParser::query('price:>10.50')->allowedFields([$field]);
    $list = SearchParser::query('price:5.00,"10.50"')->allowedFields([$field]);
    $range = SearchParser::query('price:[5.00 TO 10.50]')->allowedFields([$field]);
    $open = SearchParser::query('price:[* TO 10.50]')->allowedFields([$field]);

    expect($comparison->parse()->getValue())->toBe('10.50')
        ->and($comparison->build()['value'])->toBe(1_050)
        ->and($comparison->build()['field'])->toBe('unit_price')
        ->and($comparison->build('string'))->toBe('price:>10.50')
        ->and($list->parse()->getValues())->toBe(['5.00', '10.50'])
        ->and($list->build()['values'])->toBe([500, 1_050])
        ->and($range->parse()->getFrom())->toBe('5.00')
        ->and($range->build())->toBe([
            'field' => 'unit_price',
            'from' => 500,
            'to' => 1_050,
            'type' => 'range',
        ])
        ->and($open->build())->toBe([
            'field' => 'unit_price',
            'from' => null,
            'to' => 1_050,
            'type' => 'range',
        ]);
});

it('maps binary node recursively', function (): void {
    $adapter = new ArrayAdapter();
    $left = new ComparisonNode('price', '>', 10);
    $right = new TermNode('coffee');
    $node = new BinaryOperatorNode('AND', $left, $right);

    expect($adapter->build($node))->toBe([
        'left' => [
            'default' => false,
            'field' => 'price',
            'match' => 'exact',
            'operator' => '>',
            'quoted' => false,
            'type' => 'comparison',
            'value' => 10,
        ],
        'operator' => 'AND',
        'right' => [
            'quoted' => false,
            'type' => 'term',
            'value' => 'coffee',
        ],
        'type' => 'binary',
    ]);
});

it('maps comparison node', function (): void {
    $adapter = new ArrayAdapter();
    $node = new ComparisonNode('price', '>=', 10);

    expect($adapter->build($node))->toBe([
        'default' => false,
        'field' => 'price',
        'match' => 'exact',
        'operator' => '>=',
        'quoted' => false,
        'type' => 'comparison',
        'value' => 10,
    ]);
});

it('maps exists node', function (): void {
    $adapter = new ArrayAdapter();
    $node = new ExistsNode('category');

    expect($adapter->build($node))->toBe([
        'default' => false,
        'field' => 'category',
        'type' => 'exists',
    ]);
});

it('maps in node', function (): void {
    $adapter = new ArrayAdapter();
    $node = new InNode('status', '!=', ['A', 'B']);

    expect($adapter->build($node))->toBe([
        'default' => false,
        'field' => 'status',
        'match' => ['exact', 'exact'],
        'operator' => '!=',
        'quoted' => [false, false],
        'type' => 'in',
        'values' => ['A', 'B'],
    ]);
});

it('maps range node', function (): void {
    $adapter = new ArrayAdapter();
    $node = new RangeNode('date', '2025-01-01', '2025-12-31');

    expect($adapter->build($node))->toBe([
        'field' => 'date',
        'from' => '2025-01-01',
        'to' => '2025-12-31',
        'type' => 'range',
    ]);
});

it('maps shared array items and passes unmapped values through', function (): void {
    $field = Field::array('tags')
        ->each(fn ($rules) => $rules->string())
        ->map(['open' => false, 'closed' => 0]);

    expect(
        SearchParser::query('tags:open,other,closed')->allowedFields([$field])->build()['values'],
    )
        ->toBe([false, 'other', 0])
        ->and(Field::boolean('flag')->map([0 => 'off', 1 => 'on'])->toStorage(false))->toBe(
            'off',
        )
        ->and(new Field('value')->toStorage(1.5))->toBe(1.5);
});

it('maps term node', function (): void {
    $adapter = new ArrayAdapter();
    $node = new TermNode('coffee');

    expect($adapter->build($node))->toBe([
        'quoted' => false,
        'type' => 'term',
        'value' => 'coffee',
    ]);
});

it('maps unary node recursively', function (): void {
    $adapter = new ArrayAdapter();
    $inner = new ComparisonNode('flag', '=', true);
    $node = new UnaryOperatorNode('NOT', $inner);

    expect($adapter->build($node))->toBe([
        'default' => false,
        'operand' => [
            'default' => false,
            'field' => 'flag',
            'match' => 'exact',
            'operator' => '=',
            'quoted' => false,
            'type' => 'comparison',
            'value' => true,
        ],
        'operator' => 'NOT',
        'type' => 'unary',
    ]);
});

it('marks every comparison and list with exact matching when no mode is configured', function (): void {
    $parser = SearchParser::query('x:1,2')->allowAnyField();

    expect($parser->build()['match'])->toBe(['exact', 'exact'])
        ->and(new ArrayAdapter()->build($parser->parse())['match'])->toBe(['exact', 'exact'])
        ->and(SearchParser::query('x:1')->allowAnyField()->build()['match'])->toBe('exact');
});
