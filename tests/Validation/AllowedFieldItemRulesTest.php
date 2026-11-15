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
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;
use HosmelQ\SearchSyntaxParser\Validation\AllowedFieldItemRules;
use HosmelQ\SearchSyntaxParser\Validation\Validators\InValidator;

it('does not mistake a regex failure for a non-decimal value', function (): void {
    $limit = ini_get('pcre.backtrack_limit');

    ini_set('pcre.backtrack_limit', '2');

    try {
        expect(fn (): bool|float|int|string => AllowedFieldItemRules::infer('1.25', false))
            ->toThrow(ParseException::class, 'Search query is too complex to tokenize.');
    } finally {
        ini_set('pcre.backtrack_limit', (string) $limit);
    }
});

it('rejects enum rules without a backed enum', function (string $enum): void {
    expect(fn (): AllowedField => AllowedField::enum('value', $enum))->toThrow(
        InvalidArgumentException::class,
    )
        ->and(fn (): AllowedFieldItemRules => new AllowedFieldItemRules()->enum($enum))->toThrow(
            InvalidArgumentException::class,
        );
})->with([UnbackedStatus::class, stdClass::class]);

it('rejects enums whose backing values or names collide ignoring case', function (
    string $enum,
): void {
    expect(fn (): AllowedField => AllowedField::enum('status', $enum))->toThrow(
        InvalidArgumentException::class,
    )
        ->and(fn (): AllowedFieldItemRules => new AllowedFieldItemRules()->enum($enum))->toThrow(
            InvalidArgumentException::class,
        );
})->with([AmbiguousEnumNames::class, AmbiguousEnumValues::class]);

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

it('rejects non-canonical members during validation without casting', function (): void {
    $field = AllowedField::in('status', ['open', 'Été']);

    expect($field->validate('OPEN'))->toBeFalse();
});

it('rejects partial dates at positions without the opt-in', function (): void {
    $tuple = AllowedField::array('dates')
        ->at(
            0,
            fn (AllowedFieldItemRules $rules): AllowedFieldItemRules
                => $rules->date()->allowPartialDates(),
        )
        ->at(1, fn (AllowedFieldItemRules $rules): AllowedFieldItemRules => $rules->date());

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
    fn (): AllowedField => AllowedField::string('value')->allowPartialDates(),
    fn (): AllowedField => AllowedField::integer('value')->allowPartialDates(),
    fn (): AllowedField => AllowedField::array('value')->allowPartialDates(),
    fn (): AllowedField => AllowedField::date('value', 'Y')->allowPartialDates(),
    fn (): AllowedFieldItemRules => new AllowedFieldItemRules()->allowPartialDates(),
    fn (): AllowedFieldItemRules => new AllowedFieldItemRules()->string()->allowPartialDates(),
    fn (): AllowedFieldItemRules => new AllowedFieldItemRules()->date('Y')->allowPartialDates(),
    fn (): AllowedFieldItemRules
        => new AllowedFieldItemRules()->date()->allowPartialDates()->string(),
    fn (): AllowedFieldItemRules
        => new AllowedFieldItemRules()->date()->allowPartialDates()->date('Y'),
]);

it('rejects times and non-opted-in partial dates', function (): void {
    foreach ([
        AllowedField::date('due')->withoutTime()->allowPartialDates(),
        AllowedField::date('due')->allowPartialDates()->withoutTime(),
    ] as $field) {
        expect($field->validate('now'))->toBeFalse();
    }

    expect(AllowedField::date('due')->withoutTime()->validate('2024'))->toBeFalse();
});

it('rejects values outside an enum', function (): void {
    SearchParser::query('value:other')
        ->allowedFields([AllowedField::enum('value', PublicationStatus::class)])
        ->build();
})->throws(InvalidFieldValueException::class);

it('accepts ULIDs within 128 bits and normalizes them to uppercase', function (
    string $value,
): void {
    $field = AllowedField::ulid('id');

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
    $field = AllowedField::uuid('id');

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
    $field = AllowedField::array('status')
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
        AllowedField::enum('value', $enum, 'stored_value'),
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

it('combines dates without time with partial dates in either order', function (): void {
    foreach ([
        AllowedField::date('due')->withoutTime()->allowPartialDates(),
        AllowedField::date('due')->allowPartialDates()->withoutTime(),
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

    expect(AllowedFieldItemRules::infer($huge, false))->toBe($huge)
        ->and(AllowedFieldItemRules::infer('-'.$huge, false))->toBe('-'.$huge)
        ->and(SearchParser::query('amount:'.$huge)->allowedFields([
            new AllowedField('amount')->map([7 => 'seven']),
        ])->build()['value'])->toBe($huge);
});

it('keeps the existing matching of non-string members', function (): void {
    $field = AllowedField::in('value', [true, false, 2, 1.5]);

    expect(
        SearchParser::query('value:TRUE,0,2,1.50')->allowedFields([$field])->parse()->getValues(),
    )->toBe([true, false, 2, 1.5]);
});

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

it('opts in shared and positional date item rules separately', function (): void {
    $shared = AllowedField::array('dates')->each(
        fn (AllowedFieldItemRules $rules): AllowedFieldItemRules
            => $rules->date()->allowPartialDates(),
    );
    $tuple = AllowedField::array('dates')
        ->at(
            0,
            fn (AllowedFieldItemRules $rules): AllowedFieldItemRules
                => $rules->date()->allowPartialDates(),
        )
        ->at(1, fn (AllowedFieldItemRules $rules): AllowedFieldItemRules => $rules->date());

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
    $field = AllowedField::enum('status', OverlappingEnumTokens::class);

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
    $field = AllowedField::enum('priority', PriorityLevel::class);

    foreach ([$token, '"'.$token.'"'] as $value) {
        expect(
            SearchParser::query('priority:'.$value)->allowedFields([$field])->parse()->getValue(),
        )->toBe(
            $expected,
        );
    }
})->with([['1', 1], ['Low', 1], ['LOW', 1], ['low', 1], ['High', 2], ['high', 2]]);

it('returns canonical allowed strings for Unicode and quoted input', function (): void {
    $field = AllowedField::in('status', ['open', 'Été']);

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
    $field = AllowedField::array('priority')
        ->each(fn ($rules) => $rules->enum(PriorityLevel::class))
        ->at(1, fn ($rules) => $rules->enum(PriorityLevel::class));

    expect(
        SearchParser::query('priority:low,HIGH')->allowedFields([$field])->parse()->getValues(),
    )->toBe(
        [1, 2],
    )
        ->and(SearchParser::query('priority:[low TO HIGH]')->allowedFields([
            AllowedField::enum('priority', PriorityLevel::class),
        ])->build()['to'])->toBe(2);
});

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
