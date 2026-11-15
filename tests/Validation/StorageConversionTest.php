<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\Exception\InvalidFieldOperatorException;
use HosmelQ\SearchSyntaxParser\Exception\InvalidFieldValueException;
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;
use HosmelQ\SearchSyntaxParser\Validation\AllowedFieldItemRules;

it('scales numeric values exactly into integers or decimal strings', function (
    AllowedField $field,
    string $input,
    int|string $expected,
): void {
    $parser = SearchParser::query('value:'.$input)->allowedFields([$field]);

    expect($parser->build()['value'])->toBe($expected)
        ->and($field->toStorage($parser->parse()->getValue()))->toBe($expected);
})->with([
    [AllowedField::decimal('value', 2)->scale(100), '10.50', 1_050],
    [AllowedField::decimal('value', 2)->scale('0.1'), '10.50', '1.05'],
    [AllowedField::integer('value')->scale('0.5'), '3', '1.5'],
    [AllowedField::integer('value')->scale('0.5'), '4', 2],
    [AllowedField::numeric('value')->scale(100), '0.29', 29],
    [AllowedField::numeric('value')->scale('1e2'), '-10.5', -1_050],
    [AllowedField::integer('value')->scale(0), '100', 0],
    [AllowedField::decimal('value', 2)->scale('0.00001'), '0.01', '0.0000001'],
]);

it('keeps user values in the AST and string output while converting comparisons lists and ranges', function (): void {
    $field = AllowedField::decimal('price', 2, internalName: 'unit_price')->max(11)->scale(100);
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

it('maps canonical cast values and preserves ordinal comparisons', function (): void {
    $field = AllowedField::in('priority', ['low', 'normal', 'high'])->map([
        'low' => 1,
        'normal' => 2,
        'high' => 3,
    ]);
    $parser = SearchParser::query('priority:>NORMAL')->allowedFields([$field]);

    expect($parser->parse()->getValue())->toBe('normal')
        ->and($parser->build()['operator'])->toBe('>')->and($parser->build()['value'])->toBe(2)
        ->and($parser->build('string'))->toBe('priority:>normal')
        ->and(
            SearchParser::query('priority:LOW,HIGH')->allowedFields([$field])->build()['values'],
        )->toBe(
            [1, 3],
        )
        ->and(
            SearchParser::query('priority:[low TO high]')->allowedFields([$field])->build()['from'],
        )->toBe(
            1,
        );
});

it('maps shared array items and passes unmapped values through', function (): void {
    $field = AllowedField::array('tags')
        ->each(fn ($rules) => $rules->string())
        ->map(['open' => false, 'closed' => 0]);

    expect(
        SearchParser::query('tags:open,other,closed')->allowedFields([$field])->build()['values'],
    )
        ->toBe([false, 'other', 0])
        ->and(AllowedField::boolean('flag')->map([0 => 'off', 1 => 'on'])->toStorage(false))->toBe(
            'off',
        )
        ->and(new AllowedField('value')->toStorage(1.5))->toBe(1.5);
});

it('ignores and validates user values before storage conversion including defaults', function (): void {
    $field = AllowedField::integer('amount')->max(10)->ignore('all', 0)->scale(100)->default('5');

    expect(SearchParser::query('amount:ALL,5,0')->allowedFields([$field])->build()['values'])->toBe(
        [500],
    )
        ->and(
            SearchParser::query('amount:[ALL TO 5]')->allowedFields([$field])->build()['from'],
        )->toBeNull()
        ->and(SearchParser::query('')->allowedFields([$field])->parse()->getValue())->toBe(5)
        ->and(SearchParser::query('')->allowedFields([$field])->build()['value'])->toBe(500)
        ->and(fn (): mixed => SearchParser::query('amount:11')->allowedFields([$field])->build())
        ->toThrow(InvalidFieldValueException::class);
});

it('rejects wildcard patterns on converted fields before resolving other values', function (
    string $query,
    bool $scale,
): void {
    $field = $scale
        ? AllowedField::numeric('value')->scale(100)
        : AllowedField::string('value')->map(['x' => 1]);

    $field->alias('@fail', static function (): never {
        throw new LogicException('Wildcard restrictions must run before alias resolution.');
    });

    SearchParser::query($query)->allowedFields([$field])->parse();
})->throws(InvalidFieldOperatorException::class)->with([
    'value:ab*', 'value:!=ab*', 'value:"a b"*', 'value:@fail,ab*', 'value:-ab*',
])->with([false, true]);

it('allows existence and converts quoted literal asterisks', function (): void {
    $field = AllowedField::string('value')->map(['*' => 1]);

    expect(SearchParser::query('value:*')->allowedFields([$field])->build()['type'])->toBe('exists')
        ->and(SearchParser::query('value:"*"')->allowedFields([$field])->build()['value'])->toBe(1);
});

it('rejects scaling non-numeric field types and invalid factors when configured', function (): void {
    expect(fn (): AllowedField => AllowedField::string('value')->scale(100))->toThrow(
        InvalidArgumentException::class,
    )
        ->and(fn (): AllowedField => AllowedField::integer('value')->scale('invalid'))->toThrow(
            InvalidArgumentException::class,
        );
});

it('matches storage map keys ignoring case for strings', function (): void {
    $field = AllowedField::string('priority')->map(['High' => 3, 1 => 'one']);

    expect($field->toStorage('HIGH'))->toBe('3')
        ->and($field->toStorage('high'))->toBe('3')
        ->and($field->toStorage(1))->toBe('one')
        ->and($field->toStorage('low'))->toBe('low');
});

it('rejects storage map keys that collide ignoring case', function (): void {
    expect(fn (): AllowedField => AllowedField::string('priority')->map(['high' => 3, 'HIGH' => 4]))
        ->toThrow(
            InvalidArgumentException::class,
            'Storage map keys must be unique ignoring case.',
        );
});

it('scales floats without precision loss and keeps oversized integers as text', function (): void {
    $amount = AllowedField::numeric('amount')->scale(100);

    expect($amount->toStorage(1_234_567_890_123.45))->toBe(123_456_789_012_345)
        ->and($amount->toStorage(0.123_456_789_012_345))->toBe('12.3456789012345')
        ->and(AllowedField::decimal('price', 0, 2)->scale(100)->toStorage('99999999999999999999'))
        ->toBe('9999999999999999999900');
});

it('scales array items with a numeric shared type', function (): void {
    $field = AllowedField::array('prices')->each(fn ($rules) => $rules->numeric())->scale(100);

    expect(SearchParser::query('prices:1.5,2')->allowedFields([$field])->build()['values'])->toBe([
        150,
        200,
    ]);
});

it('matches fractional map keys from the exact float value', function (): void {
    $field = AllowedField::numeric('level')->map(['1.5' => 'half', 1 => 'one']);

    expect($field->toStorage(1.5))->toBe('half')
        ->and($field->toStorage(1.0))->toBe('one')
        ->and($field->toStorage(1.000_000_000_000_000_2))->toBe('1.0000000000000002');
});

it('stores every value as exact text when the map stores a string', function (): void {
    $level = AllowedField::numeric('level')->map([7 => 'seven']);
    $tier = AllowedField::integer('tier')->map([1 => 'pending', 2 => 20]);
    $flag = AllowedField::boolean('flag')->map([1 => 'on']);

    expect($level->storesText())->toBeTrue()
        ->and($level->toStorage(7))->toBe('seven')
        ->and($level->toStorage(2))->toBe('2')
        ->and($level->toStorage(1.234_567_890_123_45))->toBe('1.23456789012345')
        ->and($level->toStorage(1.0e20))->toBe('100000000000000000000')
        ->and($tier->toStorage(2))->toBe('20')
        ->and($tier->toStorage(0))->toBe('0')
        ->and($flag->toStorage(true))->toBe('on')
        ->and($flag->toStorage(false))->toBe('0')
        ->and(SearchParser::query('tier:0,1')->allowedFields([$tier])->build()['values'])
        ->toBe(['0', 'pending']);
});

it('matches decimal values against map keys by value without trailing zeros', function (): void {
    $text = AllowedField::decimal('amount', 0, 2)->map([7 => 'seven', '1.5' => 'half']);
    $number = AllowedField::decimal('amount', 0, 2)->map([7 => 70]);

    expect($text->toStorage('7.0'))->toBe('seven')
        ->and($text->toStorage('7.00'))->toBe('seven')
        ->and($text->toStorage('1.50'))->toBe('half')
        ->and($text->toStorage('10.50'))->toBe('10.5')
        ->and($text->toStorage('-0.0'))->toBe('0')
        ->and($number->toStorage('7.00'))->toBe(70)
        ->and($number->toStorage('10.50'))->toBe('10.5')
        ->and(SearchParser::query('amount:7.0,1.50')->allowedFields([$text])->build()['values'])
        ->toBe(['seven', 'half']);
});

it('matches decimal keys written with trailing zeros', function (): void {
    $field = AllowedField::decimal('amount', 0, 2)->map(['1.50' => 'half', '-7' => 'minus']);

    expect($field->toStorage('1.50'))->toBe('half')
        ->and($field->toStorage('1.5'))->toBe('half')
        ->and($field->toStorage('-7.00'))->toBe('minus')
        ->and(SearchParser::query('amount:@half')->allowedFields([
            AllowedField::decimal('amount', 0, 2)->alias('@half', 1.5)->map(['1.50' => 'half']),
        ])->build()['value'])->toBe('half')
        ->and(SearchParser::query('code:1.50')->allowedFields([
            AllowedField::string('code')->map(['1.5' => 'a', '1.50' => 'b']),
        ])->build()['value'])->toBe('b');
});

it('rejects decimal map keys equal by value in any configuration order', function (
    Closure $field,
): void {
    SearchParser::query('')->allowedFields([$field()]);
})->throws(
    InvalidArgumentException::class,
    "Storage map keys of decimal field 'amount' must be unique by value.",
)->with(
    [
        fn (): AllowedField
            => AllowedField::decimal('amount', 0, 2)->map(['1.5' => 'a', '1.50' => 'b']),
        fn (): AllowedField => AllowedField::decimal('amount', 0, 2)->map([7 => 'a', '7.0' => 'b']),
        fn (): AllowedField => AllowedField::array('amount')
            ->map(['1.5' => 'a', '1.50' => 'b'])
            ->each(
                fn (AllowedFieldItemRules $rules): AllowedFieldItemRules => $rules->decimal(0, 2),
            ),
        fn (): AllowedField => AllowedField::array('amount')
            ->map(['1.5' => 'a', '1.50' => 'b'])
            ->at(
                1,
                fn (AllowedFieldItemRules $rules): AllowedFieldItemRules => $rules->decimal(0, 2),
            ),
    ],
);

it('converts list items with their written positions after ignored items are removed', function (): void {
    $field = AllowedField::array('amount')
        ->each(fn (AllowedFieldItemRules $rules): AllowedFieldItemRules => $rules->string())
        ->at(0, fn (AllowedFieldItemRules $rules): AllowedFieldItemRules => $rules->decimal(0, 2))
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

it('scales numeric text that validation accepts with surrounding whitespace', function (): void {
    expect(SearchParser::query('amount:@seven')->allowedFields([
        AllowedField::numeric('amount')->alias('@seven', ' 7 ')->scale(100),
    ])->build()['value'])->toBe(700);
});

it('converts array items with the rules of their position', function (): void {
    $mapped = AllowedField::array('amount')
        ->each(fn (AllowedFieldItemRules $rules): AllowedFieldItemRules => $rules->decimal(0, 2))
        ->at(0, fn (AllowedFieldItemRules $rules): AllowedFieldItemRules => $rules->string())
        ->map([7 => 'seven']);
    $scaled = AllowedField::array('price')
        ->each(fn (AllowedFieldItemRules $rules): AllowedFieldItemRules => $rules->numeric())
        ->scale(100)
        ->at(0, fn (AllowedFieldItemRules $rules): AllowedFieldItemRules => $rules->string());

    expect(SearchParser::query('amount:7.00,7.00')->allowedFields([$mapped])->build()['values'])
        ->toBe(['7.00', 'seven'])
        ->and($mapped->toStorage('7.00'))->toBe('7.00')
        ->and($mapped->toStorage('7.00', 1))->toBe('seven')
        ->and(SearchParser::query('price:2.5,1.5')->allowedFields([$scaled])->build()['values'])
        ->toBe(['2.5', 150]);
});

it('rejects decimal text with a trailing newline before any conversion', function (): void {
    $field = AllowedField::decimal('amount', 0, 2)->map([7 => 'seven']);

    expect($field->validate("7.00\n"))->toBeFalse()
        ->and(AllowedField::decimal('amount', 0, 2)->scale(100)->validate("7.00\n"))->toBeFalse()
        ->and(
            fn (): mixed
                => SearchParser::query("amount:\"7.00\n\"")->allowedFields([$field])->build(),
        )
        ->toThrow(InvalidFieldValueException::class);
});

it('keeps number storage for maps without strings and other conversions', function (): void {
    $quantity = AllowedField::integer('quantity')->map([99 => 10]);

    expect($quantity->storesText())->toBeFalse()
        ->and($quantity->toStorage(99))->toBe(10)
        ->and($quantity->toStorage(5))->toBe(5)
        ->and(AllowedField::numeric('amount')->scale(100)->storesText())->toBeFalse()
        ->and(AllowedField::string('name')->storesText())->toBeFalse();
});

it('rejects a second storage conversion', function (): void {
    expect(fn (): AllowedField => AllowedField::numeric('amount')->scale(100)->map([1 => 2]))
        ->toThrow(InvalidArgumentException::class, 'A field accepts only one storage conversion.');
});

it('rejects non-finite numeric values', function (): void {
    expect(fn (): mixed => SearchParser::query('amount:1e400')->allowedFields([
        AllowedField::numeric('amount')->scale(100),
    ])->build())->toThrow(InvalidFieldValueException::class);
});

it('scales floats exactly whatever serialize_precision is', function (): void {
    $previous = ini_set('serialize_precision', '17');

    try {
        expect(AllowedField::numeric('amount')->scale(10)->toStorage(0.1))->toBe(1)
            ->and(ini_get('serialize_precision'))->toBe('17');
    } finally {
        ini_set('serialize_precision', $previous === false ? '-1' : $previous);
    }
});
