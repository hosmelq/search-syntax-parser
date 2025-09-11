<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\Adapter\ArrayAdapter;
use HosmelQ\SearchSyntaxParser\Adapter\QueryAdapterInterface;
use HosmelQ\SearchSyntaxParser\AST\Node\ComparisonNode;
use HosmelQ\SearchSyntaxParser\AST\Node\NodeInterface;
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;
use HosmelQ\SearchSyntaxParser\Validation\AllowedFieldItemRules;

it('creates from query and trims input', function (): void {
    $result = SearchParser::query('  title:Coffee  ')->build();

    expect($result)->toEqual([
        'field' => 'title',
        'operator' => '=',
        'type' => 'comparison',
        'value' => 'Coffee',
    ]);
});

it('returns default adapter and memoizes instance', function (): void {
    $parser = SearchParser::query('title:Coffee');

    $adapterA = $parser->adapter();
    $adapterB = $parser->adapter();

    expect($adapterA)->toBeInstanceOf(ArrayAdapter::class)
        ->and($adapterA)->toBe($adapterB);
});

it('resolves custom adapter via extend', function (): void {
    $parser = SearchParser::query('title:Coffee');

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

it('throws on unknown adapter', function (): void {
    $parser = SearchParser::query('title:Coffee');

    $parser->adapter('unknown');
})->throws(InvalidArgumentException::class, "Adapter 'unknown' not supported.");

it('reports allowed fields and validation helpers', function (): void {
    $parser = SearchParser::query('age:10')->allowedFields([
        AllowedField::integer('age', 'user_age'),
    ]);

    expect($parser->isFieldAllowed('age'))->toBeTrue()
        ->and($parser->isFieldAllowed('name'))->toBeFalse()
        ->and($parser->getAllowedField('age'))->not->toBeNull()
        ->and($parser->getAllowedFieldNames())->toBe(['age'])
        ->and($parser->validateField('age', 10))->toBeTrue()
        ->and($parser->validateField('age', 'ten'))->toBeFalse()
        ->and($parser->validateField('other', 'any'))->toBeTrue();
});

it('validates array values via validateField', function (): void {
    $parser = (new SearchParser())->allowedFields([
        AllowedField::integer('age'),
    ]);

    expect($parser->validateField('age', [1, 2, 3]))->toBeTrue()
        ->and($parser->validateField('age', [1, 'x']))->toBeFalse();
});

it('accepts single value for array field', function (): void {
    $parser = SearchParser::query('labels:abc')->allowedFields([
        AllowedField::array('labels')
            ->max(20)
            ->each(fn ($keyword): AllowedFieldItemRules => $keyword->string()->min(1)),
    ]);

    $ast = $parser->parse();

    expect($ast)->toBeInstanceOf(ComparisonNode::class)
        ->getField()->toBe('labels')
        ->getOperator()->toBe('=')
        ->getValue()->toBe('abc');
});
