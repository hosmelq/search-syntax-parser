<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\Adapter\ArrayAdapter;
use HosmelQ\SearchSyntaxParser\Adapter\StringAdapter;
use HosmelQ\SearchSyntaxParser\AST\Node\UnaryOperatorNode;
use HosmelQ\SearchSyntaxParser\AST\Visitor\NegationNormalFormTransformer;
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;

it('does not change parser output until explicitly applied and preserves source metadata', function (): void {
    $original = SearchParser::query('  NOT price:>10 OR status:!=open')->allowAnyField()->parse();
    $node = new NegationNormalFormTransformer()->transform($original);

    expect($original->getRight()->getOperator())->toBe('!=')
        ->and($node->getSpan())->toBe($original->getSpan())
        ->and($node->getLeft()->getSpan())->toBe($original->getLeft()->getSpan())
        ->and($node->getLeft()->getOperand()->getSpan())->toBe(
            $original->getLeft()->getOperand()->getSpan(),
        )
        ->and($node->getRight()->getSpan())->toBe($original->getRight()->getSpan())
        ->and($node->getRight()->getOperand()->getSpan())->toBe($original->getRight()->getSpan());
});

it('keeps defaults recognisable when a hand-built negation contains them', function (): void {
    $ast = SearchParser::query('a:1')->allowedFields([
        AllowedField::integer('a'),
        AllowedField::string('st')->default(null),
        AllowedField::string('k')->default('x'),
    ])->parse();
    $node = new NegationNormalFormTransformer()->transform(new UnaryOperatorNode('NOT', $ast));

    expect(new StringAdapter()->build($node))->toBe('NOT a:1');
});

it('normalizes negation into complements of positive leaves', function (
    string $query,
    string $expected,
): void {
    $original = SearchParser::query($query)->allowAnyField()->parse();
    $transformer = new NegationNormalFormTransformer();
    $node = $transformer->transform($original);

    expect(new StringAdapter()->build($node))->toBe($expected)
        ->and($transformer->transform($node))->toEqual($node);
})->with([
    ['NOT (a AND b)', 'NOT a OR NOT b'],
    ['NOT (a OR b)', 'NOT a AND NOT b'],
    ['NOT (a OR (b AND NOT c))', 'NOT a AND (NOT b OR c)'],
    ['NOT NOT a', 'a'],
    ['NOT NOT NOT a', 'NOT a'],
    ['status:!=open', 'NOT status:open'],
    ['NOT status:!=open', 'status:open'],
    ['status:!=open,closed', 'NOT status:open,closed'],
    ['NOT status:!=open,closed', 'status:open,closed'],
    ['title:!=mu*', 'NOT title:mu*'],
    ['NOT title:!=mu*', 'title:mu*'],
    ['title:!=mu*,cup', 'NOT title:mu* AND NOT title:cup'],
    ['NOT title:!=mu*,cup', 'title:mu* OR title:cup'],
    ['NOT price:>10', 'NOT price:>10'],
    ['NOT price:>=10', 'NOT price:>=10'],
    ['NOT price:<10', 'NOT price:<10'],
    ['NOT price:<=10', 'NOT price:<=10'],
    ['NOT price:[1 TO 10]', 'NOT price:[1 TO 10]'],
    ['NOT status:*', 'NOT status:*'],
    ['NOT mu*', 'NOT mu*'],
    ['NOT "cold brew"', 'NOT "cold brew"'],
]);

it('preserves comparison and per-item quoted flags through negation normalization', function (
    string $query,
    string $expected,
): void {
    $node = SearchParser::query($query)->allowAnyField()->parse();
    $normalized = new NegationNormalFormTransformer()->transform($node);

    expect(new StringAdapter()->build($normalized))->toBe($expected);
})->with([
    ['title:!="mug"', 'NOT title:"mug"'],
    ['title:!=mug,"cup"', 'NOT title:mug,"cup"'],
]);

it('preserves default origin and omission during normalization', function (): void {
    $original = SearchParser::query('NOT (price:>10 OR status:!=open)')
        ->allowAnyField()
        ->allowedFields([
            AllowedField::string('region')->default('north'),
            AllowedField::array('tags')->default(['one', 'two']),
            AllowedField::string('deleted')->default(null),
        ])
        ->parse();
    $node = new NegationNormalFormTransformer()->transform($original);

    expect(new StringAdapter()->build($node))->toBe('NOT price:>10 AND status:open')
        ->and($node->getRight()->isDefault())->toBeTrue()
        ->and($node->getLeft()->getRight()->isDefault())->toBeTrue()
        ->and($node->getLeft()->getLeft()->getRight()->isDefault())->toBeTrue();
});

it('retains set complement semantics for missing and multi-valued fields', function (
    string $query,
    array $expected,
): void {
    $rows = [
        ['id' => 1],
        ['id' => 2, 'price' => 5, 'tag' => []],
        ['id' => 3, 'price' => 15, 'tag' => ['a']],
        ['id' => 4, 'price' => null, 'tag' => ['b']],
        ['id' => 5, 'price' => 10, 'tag' => ['a', 'b']],
    ];
    $matches = function (array $node, array $row) use (&$matches): bool {
        if ($node['type'] === 'unary') {
            return ! $matches($node['operand'], $row);
        }

        if ($node['type'] === 'binary') {
            return $node['operator'] === 'AND'
                ? $matches($node['left'], $row) && $matches($node['right'], $row)
                : $matches($node['left'], $row) || $matches($node['right'], $row);
        }

        $values = $row[$node['field']] ?? [];
        $values = is_array($values) ? $values : [$values];
        $positive = array_any($values, static fn ($value): bool => $node['type'] === 'in'
            ? in_array($value, $node['values'], true)
            : match ($node['operator']) {
                '>' => $value > $node['value'],
                default => $value === $node['value'],
            });

        return $node['operator'] === '!=' ? ! $positive : $positive;
    };
    $original = SearchParser::query($query)->allowedFields([
        AllowedField::numeric('price')->nullable(),
        AllowedField::array('tag')->nullable(),
    ])->parse();
    $normalized = new NegationNormalFormTransformer()->transform($original);

    foreach ([$original, $normalized] as $node) {
        $array = new ArrayAdapter()->build($node);
        $selected = array_filter($rows, static fn (array $row): bool => $matches($array, $row));

        expect(array_column($selected, 'id'))->toBe($expected);
    }
})->with([
    ['NOT price:>10', [1, 2, 4, 5]],
    ['NOT (price:>10 OR tag:a)', [1, 2, 4]],
    ['tag:!=a', [1, 2, 4]],
    ['tag:!=a,b', [1, 2]],
    ['tag:a,b', [3, 4, 5]],
    ['tag:a tag:b', [5]],
]);
