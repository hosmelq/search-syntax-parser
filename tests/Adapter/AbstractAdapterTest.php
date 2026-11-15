<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\Adapter\ArrayAdapter;
use HosmelQ\SearchSyntaxParser\AST\Node\ComparisonNode;
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;
use HosmelQ\SearchSyntaxParser\Validation\TextMatch;

it('keeps typed dynamic names in the AST and expands internal names for every field node', function (
    string $query,
): void {
    $field = AllowedField::string('meta.*', 'attributes.*')->keys('/^[a-z]+$/D')->prefix();
    $parser = SearchParser::query($query)->allowedFields([$field]);

    expect($parser->getAllowedField('meta.color'))->toBe($field)
        ->and($parser->parse()->getField())->toBe('meta.color')
        ->and($parser->build()['field'])->toBe('attributes.color')
        ->and($parser->build('string'))->toStartWith('meta.color:');

    $node = SearchParser::query('meta.color:blue')->allowedFields([$field])->parse();

    expect($field->getTextMatch($node))->toBe(TextMatch::Prefix);
})->with([
    'meta.color:blue',
    'meta.color:blue,red',
    'meta.color:[a TO z]',
    'meta.color:*',
    'meta.color:bl*',
]);

it('maps comparison node with internal field name', function (): void {
    $searchParser = new SearchParser()->allowedFields([
        new AllowedField('price', 'unit_price'),
    ]);
    $adapter = new ArrayAdapter($searchParser);
    $node = new ComparisonNode('price', '=', 5);

    expect($adapter->build($node))->toBe([
        'default' => false,
        'field' => 'unit_price',
        'match' => 'exact',
        'operator' => '=',
        'quoted' => false,
        'type' => 'comparison',
        'value' => 5,
    ]);
});

it('maps internal field names in complex output', function (): void {
    $parser = SearchParser::query(
        'status:ACTIVE,DRAFT AND price:>100 OR (category:* AND -discount:*)',
    )
        ->allowedFields([
            new AllowedField('status', 'state'),
            new AllowedField('price', 'unit_price'),
            new AllowedField('category', 'cat'),
            new AllowedField('discount', 'disc'),
        ]);
    $result = $parser->build();

    expect($result)->toEqual([
        'left' => [
            'left' => [
                'default' => false,
                'field' => 'state',
                'match' => ['exact', 'exact'],
                'operator' => '=',
                'quoted' => [false, false],
                'type' => 'in',
                'values' => ['ACTIVE', 'DRAFT'],
            ],
            'operator' => 'AND',
            'right' => [
                'default' => false,
                'field' => 'unit_price',
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
                'field' => 'cat',
                'type' => 'exists',
            ],
            'operator' => 'AND',
            'right' => [
                'default' => false,
                'operand' => [
                    'default' => false,
                    'field' => 'disc',
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

it('maps wildcard fields while preserving bare terms', function (): void {
    $result = SearchParser::query('name:jo* co*fee')
        ->allowedFields([AllowedField::string('name', 'full_name')])
        ->build();

    expect($result['left']['field'])->toBe('full_name')->and($result['right']['field'])->toBeNull();
});

it('preserves Unicode prefixes and keys when mapping dynamic fields', function (): void {
    $result = SearchParser::query('méta.色:blue')->allowedFields([
        AllowedField::string('méta.*', '属性.*')->keys('/^色$/uD'),
    ])->build();

    expect($result['field'])->toBe('属性.色')->and($result['value'])->toBe('blue');
});
