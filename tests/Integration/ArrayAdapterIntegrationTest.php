<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;

it('builds complex query with precedence and grouping', function (): void {
    $result = SearchParser::query(
        'status:ACTIVE,DRAFT AND price:>100 OR (category:* AND -discount:*)',
    )->allowAnyField()->build();

    expect($result)->toEqual([
        'left' => [
            'left' => [
                'default' => false,
                'field' => 'status',
                'operator' => '=',
                'type' => 'in',
                'values' => ['ACTIVE', 'DRAFT'],
            ],
            'operator' => 'AND',
            'right' => [
                'default' => false,
                'field' => 'price',
                'operator' => '>',
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
                'operator' => '=',
                'type' => 'in',
                'values' => ['ACTIVE', 'DRAFT'],
            ],
            'operator' => 'AND',
            'right' => [
                'default' => false,
                'field' => 'unit_price',
                'operator' => '>',
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
