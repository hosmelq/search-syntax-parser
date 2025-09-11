<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;

it('builds complex query with precedence and grouping', function (): void {
    $result = SearchParser::query('status:ACTIVE,DRAFT AND price:>100 OR (category:* AND -discount:*)')->build();

    expect($result)->toEqual([
        'left' => [
            'left' => [
                'field' => 'status',
                'operator' => '=',
                'type' => 'in',
                'values' => ['ACTIVE', 'DRAFT'],
            ],
            'operator' => 'AND',
            'right' => [
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
                'field' => 'category',
                'type' => 'exists',
            ],
            'operator' => 'AND',
            'right' => [
                'operand' => [
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
    $parser = SearchParser::query('status:ACTIVE,DRAFT AND price:>100 OR (category:* AND -discount:*)')
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
                'field' => 'state',
                'operator' => '=',
                'type' => 'in',
                'values' => ['ACTIVE', 'DRAFT'],
            ],
            'operator' => 'AND',
            'right' => [
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
                'field' => 'cat',
                'type' => 'exists',
            ],
            'operator' => 'AND',
            'right' => [
                'operand' => [
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
