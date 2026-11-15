<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\AST\Node\NodeInterface;
use HosmelQ\SearchSyntaxParser\Exception\InvalidFieldValueException;
use HosmelQ\SearchSyntaxParser\Exception\ParseException;
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Tests\Fixtures\AmbiguousEnumNames;
use HosmelQ\SearchSyntaxParser\Tests\Fixtures\AmbiguousEnumValues;
use HosmelQ\SearchSyntaxParser\Tests\Fixtures\OverlappingEnumTokens;
use HosmelQ\SearchSyntaxParser\Tests\Fixtures\PriorityLevel;
use HosmelQ\SearchSyntaxParser\Tests\Fixtures\PublicationStatus;
use HosmelQ\SearchSyntaxParser\Tests\Fixtures\UnbackedStatus;
use HosmelQ\SearchSyntaxParser\Validation\Field;
use HosmelQ\SearchSyntaxParser\Validation\FieldItemRules;
use HosmelQ\SearchSyntaxParser\Validation\Validators\InValidator;

it('does not mistake a regex failure for a non-decimal value', function (): void {
    $limit = ini_get('pcre.backtrack_limit');

    ini_set('pcre.backtrack_limit', '2');

    try {
        expect(fn (): bool|float|int|string => FieldItemRules::infer('1.25', false))
            ->toThrow(ParseException::class, 'Search query is too complex to tokenize.');
    } finally {
        ini_set('pcre.backtrack_limit', (string) $limit);
    }
});

it('rejects enum rules without a backed enum', function (string $enum): void {
    expect(fn (): Field => Field::enum('value', $enum))->toThrow(
        InvalidArgumentException::class,
    )
        ->and(fn (): FieldItemRules => new FieldItemRules()->enum($enum))->toThrow(
            InvalidArgumentException::class,
        );
})->with([UnbackedStatus::class, stdClass::class]);

it('rejects enums whose backing values or names collide ignoring case', function (
    string $enum,
): void {
    expect(fn (): Field => Field::enum('status', $enum))->toThrow(
        InvalidArgumentException::class,
    )
        ->and(fn (): FieldItemRules => new FieldItemRules()->enum($enum))->toThrow(
            InvalidArgumentException::class,
        );
})->with([AmbiguousEnumNames::class, AmbiguousEnumValues::class]);

it('rejects invalid typed values instead of coercing them into valid ones', function (
    Field $field,
    string $value,
): void {
    SearchParser::query('field:'.$value)->allowedFields([$field])->build();
})->throws(InvalidFieldValueException::class)->with([
    [Field::integer('field'), '01234'],
    [Field::integer('field'), '99999999999999999999'],
    [Field::integer('field'), '1e5'],
    [Field::integer('field'), '1.0'],
    [Field::decimal('field', 0), '1e2'],
    [Field::numeric('field'), 'abc'],
    [Field::decimal('field', 2), '1.5'],
    [Field::boolean('field'), 'yes'],
    [Field::boolean('field')->max(1), 'true'],
    [Field::in('field', ['01', '02']), '1'],
]);

it('rejects non-canonical members during validation without casting', function (): void {
    $field = Field::in('status', ['open', 'Été']);

    expect($field->validate('OPEN'))->toBeFalse();
});

it('rejects partial dates at positions without the opt-in', function (): void {
    $tuple = Field::array('dates')
        ->at(
            0,
            fn (FieldItemRules $rules): FieldItemRules
                => $rules->date()->allowPartialDates(),
        )
        ->at(1, fn (FieldItemRules $rules): FieldItemRules => $rules->date());

    expect(
        fn (): null|NodeInterface
                => SearchParser::query('dates:2024,2024-03')->allowedFields([$tuple])->parse(),
    )->toThrow(InvalidFieldValueException::class);
});

it('rejects partial-date configuration on other types and custom formats', function (
    Closure $configure,
): void {
    $configure();
})->throws(InvalidArgumentException::class)->with([
    fn (): Field => Field::string('value')->allowPartialDates(),
    fn (): Field => Field::integer('value')->allowPartialDates(),
    fn (): Field => Field::array('value')->allowPartialDates(),
    fn (): Field => Field::date('value', 'Y')->allowPartialDates(),
    fn (): FieldItemRules => new FieldItemRules()->allowPartialDates(),
    fn (): FieldItemRules => new FieldItemRules()->string()->allowPartialDates(),
    fn (): FieldItemRules => new FieldItemRules()->date('Y')->allowPartialDates(),
    fn (): FieldItemRules
        => new FieldItemRules()->date()->allowPartialDates()->string(),
    fn (): FieldItemRules
        => new FieldItemRules()->date()->allowPartialDates()->date('Y'),
]);

it('rejects times and non-opted-in partial dates', function (): void {
    foreach ([
        Field::date('due')->withoutTime()->allowPartialDates(),
        Field::date('due')->allowPartialDates()->withoutTime(),
    ] as $field) {
        expect($field->validate('now'))->toBeFalse();
    }

    expect(Field::date('due')->withoutTime()->validate('2024'))->toBeFalse();
});

it('rejects values outside an enum', function (): void {
    SearchParser::query('value:other')
        ->allowedFields([Field::enum('value', PublicationStatus::class)])
        ->build();
})->throws(InvalidFieldValueException::class);

it('accepts ULIDs within 128 bits and normalizes them to uppercase', function (
    string $value,
): void {
    $field = Field::ulid('id');

    expect(
        SearchParser::query('id:"'.$value.'"')->allowedFields([$field])->parse()->getValue(),
    )->toBe(
        mb_strtoupper($value),
    );
})->with([
    '00000000000000000000000000',
    '7ZZZZZZZZZZZZZZZZZZZZZZZZZ',
    '01arz3ndektsv4rrffq69g5fav',
]);

it('accepts UUIDs of any version and normalizes them to lowercase', function (string $value): void {
    $field = Field::uuid('id');

    foreach ([$value, '"'.$value.'"'] as $input) {
        expect(
            SearchParser::query('id:'.$input)->allowedFields([$field])->parse()->getValue(),
        )->toBe(
            mb_strtolower($value),
        );
    }
})->with([
    '00000000-0000-0000-0000-000000000000',
    'FFFFFFFF-FFFF-FFFF-FFFF-FFFFFFFFFFFF',
    '550E8400-E29B-41D4-A716-446655440000',
    '550E8400-E29B-F1D4-0716-446655440000',
]);

it('canonicalizes case-insensitive shared and positional members', function (): void {
    $field = Field::array('status')
        ->each(fn ($rules) => $rules->in(['open', 'closed']))
        ->at(1, fn ($rules) => $rules->in(['closed']));

    expect(SearchParser::query('status:OPEN,CLOSED')->allowedFields([$field])->parse()->getValues())
        ->toBe(['open', 'closed']);
});

it('casts backed enum values to their backing type', function (
    string $query,
    string $enum,
    int|string $expected,
): void {
    $parser = SearchParser::query($query)->allowedFields([
        Field::enum('value', $enum, 'stored_value'),
    ]);

    expect($parser->build())->toBe([
        'default' => false,
        'field' => 'stored_value',
        'match' => 'exact',
        'operator' => '=',
        'quoted' => str_contains($query, '"'),
        'type' => 'comparison',
        'value' => $expected,
    ]);
})->with([
    ['value:draft', PublicationStatus::class, 'draft'],
    ['value:"published"', PublicationStatus::class, 'published'],
    ['value:2', PriorityLevel::class, 2],
    ['value:"1"', PriorityLevel::class, 1],
]);

it('casts values according to the field schema regardless of quoting', function (
    Field $field,
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
    [Field::string('field'), '12345', '12345'],
    [Field::string('field'), 'true', 'true'],
    [Field::string('field')->max(3), '5', '5'],
    [Field::string('field')->size(2), '01', '01'],
    [Field::string('field')->size(4), 'José', 'José'],
    [Field::integer('field'), '123', 123],
    [Field::numeric('field'), '123', 123],
    [Field::numeric('field'), '1e5', 100_000.0],
    [Field::numeric('field'), '1.5', 1.5],
    [Field::decimal('field', 2), '10.50', '10.50'],
    [Field::decimal('field', 2), '5.00', '5.00'],
    [Field::boolean('field'), 'true', true],
    [Field::boolean('field'), 'TRUE', true],
    [Field::boolean('field'), '1', true],
    [Field::boolean('field'), 'false', false],
    [Field::boolean('field'), 'FALSE', false],
    [Field::boolean('field'), '0', false],
    [Field::in('field', ['01', '02']), '01', '01'],
    [Field::in('field', ['true', 'false']), 'true', 'true'],
    [Field::in('field', [1, 2, 3]), '2', 2],
    [Field::in('field', [1.5, 2.5]), '1.5', 1.5],
    [Field::in('field', [true, false]), '1', true],
]);

it('combines dates without time with partial dates in either order', function (): void {
    foreach ([
        Field::date('due')->withoutTime()->allowPartialDates(),
        Field::date('due')->allowPartialDates()->withoutTime(),
    ] as $field) {
        expect($field->validate('2024'))->toBeTrue()->and($field->validate('2024-03'))->toBeTrue();
    }
});

it('infers untyped values conservatively', function (
    string $input,
    bool|float|int|string $value,
): void {
    expect(SearchParser::query('value:'.$input)->allowAnyField()->parse()->getValue())->toBe(
        $value,
    );
})->with([
    ['01234', '01234'],
    ['99999999999999999999', '99999999999999999999'],
    ['1e5', '1e5'],
    ['01.5', '01.5'],
    ['5.', '5.'],
    ['.5', '.5'],
    ['123', 123],
    ['-5', -5],
    ['-12.50', -12.5],
    ['10.50', 10.5],
    ['TRUE', true],
    ['FALSE', false],
    ['"123"', '123'],
    ['"true"', 'true'],
]);

it('keeps decimals beyond the float range as text', function (): void {
    $huge = str_repeat('9', 310).'.0';

    expect(FieldItemRules::infer($huge, false))->toBe($huge)
        ->and(FieldItemRules::infer('-'.$huge, false))->toBe('-'.$huge)
        ->and(SearchParser::query('amount:'.$huge)->allowedFields([
            new Field('amount')->map([7 => 'seven']),
        ])->build()['value'])->toBe($huge);
});

it('keeps the existing matching of non-string members', function (): void {
    $field = Field::in('value', [true, false, 2, 1.5]);

    expect(
        SearchParser::query('value:TRUE,0,2,1.50')->allowedFields([$field])->parse()->getValues(),
    )->toBe([true, false, 2, 1.5]);
});

it('matches boolean and float membership values by their inferred value', function (
    Field $field,
    string $value,
    bool|float|int|string $expected,
): void {
    expect(
        SearchParser::query('field:'.$value)->allowedFields([$field])->parse()->getValue(),
    )->toBe(
        $expected,
    );
})->with([
    [Field::in('field', [true, false]), 'true', true],
    [Field::in('field', [true, false]), 'false', false],
    [Field::in('field', [true, false]), '0', false],
    [Field::in('field', [1.5, 2.0]), '2.0', 2.0],
    [Field::in('field', [1.5, 2.0]), '2', 2.0],
    [Field::in('field', [true, false, '']), '""', ''],
]);

it('opts in shared and positional date item rules separately', function (): void {
    $shared = Field::array('dates')->each(
        fn (FieldItemRules $rules): FieldItemRules
            => $rules->date()->allowPartialDates(),
    );
    $tuple = Field::array('dates')
        ->at(
            0,
            fn (FieldItemRules $rules): FieldItemRules
                => $rules->date()->allowPartialDates(),
        )
        ->at(1, fn (FieldItemRules $rules): FieldItemRules => $rules->date());

    expect(
        SearchParser::query('dates:2024,2024-03')->allowedFields([$shared])->parse()->getValues(),
    )
        ->toBe(['2024', '2024-03'])
        ->and(
            SearchParser::query('dates:2024,2024-03-15')
                ->allowedFields([$tuple])
                ->parse()
                ->getValues(),
        )
        ->toBe(['2024', '2024-03-15']);
});

it('prefers backing values over another case name while accepting both forms', function (): void {
    $field = Field::enum('status', OverlappingEnumTokens::class);

    expect(
        SearchParser::query('status:ENABLED,Active,OPEN')
            ->allowedFields([$field])
            ->parse()
            ->getValues(),
    )
        ->toBe(['enabled', 'enabled', 'open'])
        ->and($field->tryCast('Enabled'))->toBe('enabled')
        ->and(
            SearchParser::query('')
                ->allowedFields([$field->default('ACTIVE')])
                ->parse()
                ->getValue(),
        )->toBe(
            'enabled',
        );
});

it('resolves integer-backed enum names and values regardless of case or quotes', function (
    string $token,
    int $expected,
): void {
    $field = Field::enum('priority', PriorityLevel::class);

    foreach ([$token, '"'.$token.'"'] as $value) {
        expect(
            SearchParser::query('priority:'.$value)->allowedFields([$field])->parse()->getValue(),
        )->toBe(
            $expected,
        );
    }
})->with([['1', 1], ['Low', 1], ['LOW', 1], ['low', 1], ['High', 2], ['high', 2]]);

it('returns canonical allowed strings for Unicode and quoted input', function (): void {
    $field = Field::in('status', ['open', 'Été']);

    expect(SearchParser::query('status:OPEN,"ÉTÉ"')->allowedFields([$field])->parse()->getValues())
        ->toBe(['open', 'Été'])
        ->and($field->tryCast('éTé'))->toBe('Été')
        ->and($field->validate('open'))->toBeTrue()
        ->and(new InValidator(['open', 'Été'])(['open', 'Été'], 'status'))->toBeTrue()
        ->and(
            SearchParser::query('')->allowedFields([$field->default('OPEN')])->parse()->getValue(),
        )
        ->toBe('open');
});

it('supports enum names in shared and positional rules and range bounds', function (): void {
    $field = Field::array('priority')
        ->each(fn ($rules) => $rules->enum(PriorityLevel::class))
        ->at(1, fn ($rules) => $rules->enum(PriorityLevel::class));

    expect(
        SearchParser::query('priority:low,HIGH')->allowedFields([$field])->parse()->getValues(),
    )->toBe(
        [1, 2],
    )
        ->and(SearchParser::query('priority:[low TO HIGH]')->allowedFields([
            Field::enum('priority', PriorityLevel::class),
        ])->build()['to'])->toBe(2);
});

it('uses inference for untyped fields and callbacks', function (
    string $input,
    bool|float|int|string $expected,
): void {
    foreach ([
        'field',
        new Field('field'),
        Field::callback('field', fn (): bool => true),
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
