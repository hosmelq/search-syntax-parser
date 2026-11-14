<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\Exception\InvalidFieldValueException;
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;
use HosmelQ\SearchSyntaxParser\Validation\AllowedFieldItemRules;

it('casts values according to the field schema regardless of quoting', function (
    AllowedField $field,
    string $value,
    bool|float|int|string $expected,
): void {
    foreach ([$value, '"'.$value.'"'] as $input) {
        expect(
            SearchParser::query('field:'.$input)->allowedFields([$field])->parse()->getValue(),
        )->toBe(
            $expected,
        );
    }
})->with([
    [AllowedField::string('field'), '12345', '12345'],
    [AllowedField::string('field'), 'true', 'true'],
    [AllowedField::string('field')->max(3), '5', '5'],
    [AllowedField::string('field')->size(2), '01', '01'],
    [AllowedField::string('field')->size(4), 'José', 'José'],
    [AllowedField::integer('field'), '123', 123],
    [AllowedField::numeric('field'), '123', 123],
    [AllowedField::numeric('field'), '1e5', 100_000.0],
    [AllowedField::numeric('field'), '1.5', 1.5],
    [AllowedField::decimal('field', 2), '10.50', '10.50'],
    [AllowedField::decimal('field', 2), '5.00', '5.00'],
    [AllowedField::boolean('field'), 'true', true],
    [AllowedField::boolean('field'), 'TRUE', true],
    [AllowedField::boolean('field'), '1', true],
    [AllowedField::boolean('field'), 'false', false],
    [AllowedField::boolean('field'), 'FALSE', false],
    [AllowedField::boolean('field'), '0', false],
    [AllowedField::in('field', ['01', '02']), '01', '01'],
    [AllowedField::in('field', ['true', 'false']), 'true', 'true'],
    [AllowedField::in('field', [1, 2, 3]), '2', 2],
    [AllowedField::in('field', [1.5, 2.5]), '1.5', 1.5],
    [AllowedField::in('field', [true, false]), '1', true],
]);

it('uses inference for untyped fields and callbacks', function (
    string $input,
    bool|float|int|string $expected,
): void {
    foreach ([
        'field',
        new AllowedField('field'),
        AllowedField::callback('field', fn (): bool => true),
    ] as $field) {
        expect(
            SearchParser::query('field:'.$input)->allowedFields([$field])->parse()->getValue(),
        )->toBe(
            $expected,
        );
    }
})->with([
    ['123', 123], ['"123"', '123'], ['01234', '01234'], ['true', true], ['"true"', 'true'], [
        '1e5',
        '1e5',
    ],
]);

it('rejects invalid typed values instead of coercing them into valid ones', function (
    AllowedField $field,
    string $value,
): void {
    SearchParser::query('field:'.$value)->allowedFields([$field])->build();
})->throws(InvalidFieldValueException::class)->with([
    [AllowedField::integer('field'), '01234'],
    [AllowedField::integer('field'), '99999999999999999999'],
    [AllowedField::integer('field'), '1e5'],
    [AllowedField::integer('field'), '1.0'],
    [AllowedField::decimal('field', 0), '1e2'],
    [AllowedField::numeric('field'), 'abc'],
    [AllowedField::decimal('field', 2), '1.5'],
    [AllowedField::boolean('field'), 'yes'],
    [AllowedField::boolean('field')->max(1), 'true'],
    [AllowedField::in('field', ['01', '02']), '1'],
]);

it('validates both range bounds with the field rules', function (string $input): void {
    SearchParser::query($input)
        ->allowedFields([AllowedField::integer('age')->min(0)->max(3)])
        ->parse();
})->throws(InvalidFieldValueException::class)->with([
    'age:[abc TO xyz]', 'age:[abc TO 2]', 'age:[1 TO xyz]', 'age:[1 TO 5]', 'age:[-1 TO 2]',
]);

it('casts range bounds using the field type', function (): void {
    expect(
        SearchParser::query('price:[5.00 TO 10.50]')
            ->allowedFields([AllowedField::decimal('price', 2)->max(11)])
            ->build(),
    )->toBe(
        [
            'field' => 'price',
            'from' => '5.00',
            'to' => '10.50',
            'type' => 'range',
        ],
    );
});

it('measures numeric types numerically for every size rule', function (
    string $type,
    string $rule,
): void {
    $field = match ($type) {
        'integer' => AllowedField::integer('value'),
        'numeric' => AllowedField::numeric('value'),
        'decimal' => AllowedField::decimal('value', 2),
    };
    $field = match ($rule) {
        'min' => $field->min(100),
        'max' => $field->max(100),
        'between' => $field->between(100, 200),
        'size' => $field->size(150),
    };
    $value = $type === 'decimal' ? '150.00' : '150';

    if ($rule === 'max') {
        expect(
            fn (): mixed
                => SearchParser::query('value:"'.$value.'"')->allowedFields([$field])->build(),
        )->toThrow(
            InvalidFieldValueException::class,
        );
    } else {
        expect(
            SearchParser::query('value:"'.$value.'"')->allowedFields([$field])->parse()->getValue(),
        )->toBe(
            $type === 'decimal' ? $value : 150,
        );
    }
})->with(['integer', 'numeric', 'decimal'])->with(['min', 'max', 'between', 'size']);

it('measures numeric-looking strings by character length', function (string $rule): void {
    $field = AllowedField::string('code');
    $field = match ($rule) {
        'min' => $field->min(3),
        'max' => $field->max(3),
        'between' => $field->between(2, 3),
        'size' => $field->size(3),
    };

    expect(SearchParser::query('code:123')->allowedFields([$field])->parse()->getValue())->toBe(
        '123',
    );
})->with(['min', 'max', 'between', 'size']);

it('rejects unsupported sizes without a TypeError', function (string $rule): void {
    $field = match ($rule) {
        'min' => AllowedField::boolean('flag')->min(0),
        'max' => AllowedField::boolean('flag')->max(1),
        'between' => AllowedField::boolean('flag')->between(0, 1),
        'size' => AllowedField::boolean('flag')->size(1),
    };

    SearchParser::query('flag:true')->allowedFields([$field])->build();
})->throws(InvalidFieldValueException::class)->with(['min', 'max', 'between', 'size']);

it('casts negative tuple items with their index rules', function (): void {
    $field = AllowedField::array('location')
        ->size(2)
        ->at(
            0,
            fn (AllowedFieldItemRules $rules): AllowedFieldItemRules
                => $rules->numeric()->between(-90, 90),
        )
        ->at(
            1,
            fn (AllowedFieldItemRules $rules): AllowedFieldItemRules
                => $rules->numeric()->between(-180, 180),
        );

    expect(
        SearchParser::query('location:37.7749,-122.4194')
            ->allowedFields([$field])
            ->parse()
            ->getValues(),
    )->toBe(
        [37.774_9, -122.419_4],
    );
});

it('uses index types before shared item types and inference', function (): void {
    $typed = AllowedField::array('values')
        ->each(fn (AllowedFieldItemRules $rules): AllowedFieldItemRules => $rules->integer())
        ->at(0, fn (AllowedFieldItemRules $rules): AllowedFieldItemRules => $rules->string());
    $untyped = AllowedField::array('values')
        ->at(1, fn (AllowedFieldItemRules $rules): AllowedFieldItemRules => $rules->string());

    expect(SearchParser::query('values:1,2')->allowedFields([$typed])->parse()->getValues())->toBe([
        '1',
        2,
    ])
        ->and(
            SearchParser::query('values:true,123,4')
                ->allowedFields([$untyped])
                ->parse()
                ->getValues(),
        )->toBe(
            [true, '123', 4],
        );
});

it('inherits shared item types when index rules only constrain size', function (): void {
    $field = AllowedField::array('values')
        ->at(0, fn (AllowedFieldItemRules $rules): AllowedFieldItemRules => $rules->max(100))
        ->each(fn (AllowedFieldItemRules $rules): AllowedFieldItemRules => $rules->decimal(2));

    expect(
        fn (): mixed => SearchParser::query('values:150.00')->allowedFields([$field])->build(),
    )->toThrow(
        InvalidFieldValueException::class,
    )
        ->and(
            SearchParser::query('values:50.00')->allowedFields([$field])->parse()->getValue(),
        )->toBe(
            '50.00',
        );
});

it('keeps item size rules independent of type rule order', function (
    string $type,
    string $scope,
    string $sizeRule,
): void {
    foreach ([true, false] as $sizeFirst) {
        $configure = function (AllowedFieldItemRules $rules) use (
            $type,
            $sizeRule,
            $sizeFirst,
        ): void {
            $setType = function () use ($type, $rules): void {
                match ($type) {
                    'integer' => $rules->integer(),
                    'numeric' => $rules->numeric(),
                    'decimal' => $rules->decimal(2),
                };
            };
            $setSize = function () use ($sizeRule, $rules): void {
                match ($sizeRule) {
                    'min' => $rules->min(100),
                    'max' => $rules->max(200),
                    'between' => $rules->between(100, 200),
                    'size' => $rules->size(150),
                };
            };

            if ($sizeFirst) {
                $setSize();

                $setType();
            } else {
                $setType();

                $setSize();
            }
        };
        $field = AllowedField::array('values');

        $scope === 'each' ? $field->each($configure) : $field->at(0, $configure);

        $value = $type === 'decimal' ? '150.00' : '150';

        expect(
            SearchParser::query('values:"'.$value.'"')
                ->allowedFields([$field])
                ->parse()
                ->getValue(),
        )->toBe(
            $type === 'decimal' ? $value : 150,
        );
    }
})
    ->with(['integer', 'numeric', 'decimal'])
    ->with(['each', 'at'])
    ->with(['min', 'max', 'between', 'size']);

it('casts item strings, booleans and membership values', function (
    Closure $configure,
    string $query,
    array $expected,
): void {
    $field = AllowedField::array('values')->each($configure);

    expect(
        SearchParser::query('values:'.$query)->allowedFields([$field])->parse()->getValues(),
    )->toBe(
        $expected,
    );
})->with([
    [
        fn (AllowedFieldItemRules $rules): AllowedFieldItemRules => $rules->string()->max(4),
        '123,true',
        ['123', 'true'],
    ],
    [
        fn (AllowedFieldItemRules $rules): AllowedFieldItemRules => $rules->boolean(),
        'true,0',
        [true, false],
    ],
    [
        fn (AllowedFieldItemRules $rules): AllowedFieldItemRules => $rules->in(['01', 2]),
        '01,2',
        ['01', 2],
    ],
]);

it('rejects decimal item maxima regardless of rule order', function (
    string $scope,
    bool $sizeFirst,
): void {
    $configure = function (AllowedFieldItemRules $rules) use ($sizeFirst): void {
        if ($sizeFirst) {
            $rules->max(100)->decimal(2);
        } else {
            $rules->decimal(2)->max(100);
        }
    };
    $field = AllowedField::array('values');

    $scope === 'each' ? $field->each($configure) : $field->at(0, $configure);

    SearchParser::query('values:150.00')->allowedFields([$field])->build();
})->throws(InvalidFieldValueException::class)->with(['each', 'at'])->with([true, false]);

it('matches boolean and float membership values by their inferred value', function (
    AllowedField $field,
    string $value,
    bool|float|int|string $expected,
): void {
    expect(
        SearchParser::query('field:'.$value)->allowedFields([$field])->parse()->getValue(),
    )->toBe(
        $expected,
    );
})->with([
    [AllowedField::in('field', [true, false]), 'true', true],
    [AllowedField::in('field', [true, false]), 'false', false],
    [AllowedField::in('field', [true, false]), '0', false],
    [AllowedField::in('field', [1.5, 2.0]), '2.0', 2.0],
    [AllowedField::in('field', [1.5, 2.0]), '2', 2.0],
    [AllowedField::in('field', [true, false, '']), '""', ''],
]);

it('sizes shared item rules by the effective item type', function (): void {
    $decimal = AllowedField::array('values')
        ->each(fn (AllowedFieldItemRules $rules): AllowedFieldItemRules => $rules->max(180))
        ->at(0, fn (AllowedFieldItemRules $rules): AllowedFieldItemRules => $rules->decimal(0, 6));
    $string = AllowedField::array('values')
        ->each(fn (AllowedFieldItemRules $rules): AllowedFieldItemRules => $rules->max(3))
        ->at(0, fn (AllowedFieldItemRules $rules): AllowedFieldItemRules => $rules->string());

    expect(
        fn (): mixed => SearchParser::query('values:999.5')->allowedFields([$decimal])->build(),
    )->toThrow(
        InvalidFieldValueException::class,
    )
        ->and(
            SearchParser::query('values:179.5')->allowedFields([$decimal])->parse()->getValue(),
        )->toBe(
            '179.5',
        )
        ->and(
            SearchParser::query('values:12')->allowedFields([$string])->parse()->getValue(),
        )->toBe(
            '12',
        );
});

it('compares numeric-looking strings by value on untyped fields', function (string $value): void {
    SearchParser::query('age:'.$value)->allowedFields([new AllowedField('age')->max(100)])->build();
})->throws(InvalidFieldValueException::class)->with([
    '150',
    '0150',
    '"150"',
    '1e5',
    '99999999999999999999',
]);
