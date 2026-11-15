<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\AST\Node\NodeInterface;
use HosmelQ\SearchSyntaxParser\Date\DateValue;
use HosmelQ\SearchSyntaxParser\Exception\InvalidFieldValueException;
use HosmelQ\SearchSyntaxParser\Exception\ParseException;
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;
use HosmelQ\SearchSyntaxParser\Validation\AllowedFieldItemRules;

it('requires an explicit opt-in for partial dates and preserves their text', function (
    string $value,
): void {
    foreach ([$value, '"'.$value.'"'] as $text) {
        expect(fn (): null|NodeInterface => SearchParser::query('created:'.$text)
            ->allowedFields([AllowedField::date('created')])->parse())
            ->toThrow(InvalidFieldValueException::class)
            ->and(SearchParser::query('created:'.$text)->allowedFields([
                AllowedField::date('created')->allowPartialDates(),
            ])->parse()->getValue())->toBe($value);
    }
})->with(['2024', '2024-03']);

it('rejects invalid partial calendars even with the opt-in', function (string $value): void {
    expect(AllowedField::date('created')->allowPartialDates()->validate($value))->toBeFalse();
})->with(['0000', '2024-00', '2024-13', '2024-3', '24', '2024-', '2024-03T10']);

it('supports partial dates in lists and both range bounds', function (): void {
    $fields = [AllowedField::date('created')->allowPartialDates()];
    $range = SearchParser::query('created:[2023 TO 2024-06]')->allowedFields($fields)->parse();

    expect(
        SearchParser::query('created:2024,2024-03')->allowedFields($fields)->parse()->getValues(),
    )
        ->toBe(['2024', '2024-03'])
        ->and($range->getFrom())->toBe('2023')
        ->and($range->getTo())->toBe('2024-06');
});

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
        ->toBe(['2024', '2024-03-15'])
        ->and(
            fn (): null|NodeInterface
                => SearchParser::query('dates:2024,2024-03')->allowedFields([$tuple])->parse(),
        )
        ->toThrow(InvalidFieldValueException::class);
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

it('accepts the relative grammar without resolving its text', function (string $value): void {
    foreach ([$value, '"'.$value.'"'] as $text) {
        expect(SearchParser::query('created:'.$text)->allowedFields([
            AllowedField::date('created'),
        ])->parse()->getValue())->toBe($value);
    }
})->with([
    'now', 'NOW', 'NoW', 'today', 'TODAY', 'yesterday', 'YeStErDaY', 'tomorrow', 'TOMORROW',
    'now-7d', 'now+2h', 'now+1m', 'now-1M', 'now+2y', 'now-1w', 'now+0d', 'now-0007d',
    'today-7d', 'ToDaY+2M', 'yesterday-1y', 'tomorrow+1w', '-7d', '+3d', '-2h', '+5m',
]);

it('rejects malformed relative dates in both validation and resolution', function (
    string $value,
): void {
    expect(AllowedField::date('created')->validate($value))->toBeFalse()
        ->and(fn (): DateValue => DateValue::parse($value, new DateTimeZone('UTC')))
        ->toThrow(InvalidArgumentException::class);
})->with([
    '', 'now-1D', 'now+2H', 'now+1Y', 'now-1W', 'today+1h', 'today-1m', 'yesterday+0h',
    'tomorrow-2m', 'now-1s', 'now-1ms', 'now-1.5d', 'now--1d', 'now+-1d', 'now+1d-2d',
    'today+1d+2w', '-1d+2h', 'now-', 'now+1', 'now 1d', 'now- 1d', 'now-1 d', 'nowZ',
    'now-1dZ', 'today+1MZ', 'nextweek', ' now', 'today ', '-d', '+d', '7d',
]);

it('lexes relative dates in every field position', function (string $value): void {
    $fields = [AllowedField::date('created')];

    foreach (['=', '!=', '>', '>=', '<', '<='] as $operator) {
        $written = $operator === '=' ? '' : $operator;
        $node = SearchParser::query('created:'.$written.$value)->allowedFields($fields)->parse();

        expect($node->getValue())->toBe($value)->and($node->getOperator())->toBe($operator);
    }

    $range = SearchParser::query('created:['.$value.' TO '.$value.']')
        ->allowedFields($fields)
        ->parse();

    expect(
        SearchParser::query('created:'.$value.',now,'.$value)
            ->allowedFields($fields)
            ->parse()
            ->getValues(),
    )
        ->toBe([$value, 'now', $value])
        ->and($range->getFrom())->toBe($value)
        ->and($range->getTo())->toBe($value);
})->with(['-7d', '+3d', 'today', 'now-1h', 'ToMoRrOw+1M']);

it('parses negative relative values in ranges and rejects a separated minus', function (): void {
    $fields = [AllowedField::date('created')];
    $range = SearchParser::query('created:[-30d TO now]')->allowedFields($fields)->parse();

    expect($range->getFrom())->toBe('-30d')->and($range->getTo())->toBe('now')
        ->and(
            fn (): null|NodeInterface
                => SearchParser::query('created:>- 7d')->allowedFields($fields)->parse(),
        )
        ->toThrow(ParseException::class);
});

it('keeps relative defaults and date array items as text', function (): void {
    $default = SearchParser::query('')
        ->allowedFields([AllowedField::date('due')->default('today')])
        ->parse();
    $items = AllowedField::array('dates')->each(
        fn (AllowedFieldItemRules $rules): AllowedFieldItemRules => $rules->date(),
    );

    expect($default->getValue())->toBe('today')->and($default->isDefault())->toBeTrue()
        ->and(
            SearchParser::query('dates:-7d,tomorrow+1w')
                ->allowedFields([$items])
                ->parse()
                ->getValues(),
        )
        ->toBe(['-7d', 'tomorrow+1w']);
});

it('keeps relative grammar out of custom date formats', function (): void {
    expect(AllowedField::date('due', 'Y-m-d')->validate('today'))->toBeFalse();
});

it('rejects relative offsets longer than six digits so every accepted value resolves', function (): void {
    $field = AllowedField::date('created');

    expect($field->validate('-99999d'))->toBeTrue()
        ->and($field->validate('-999999h'))->toBeTrue()
        ->and($field->validate('-1000000d'))->toBeFalse()
        ->and($field->validate('today+99999999999999999999d'))->toBeFalse();
});

it('points array fields to item rules for partial dates', function (): void {
    expect(fn (): AllowedField => AllowedField::array('dates')->allowPartialDates())->toThrow(
        InvalidArgumentException::class,
        'On array fields, allow partial dates on the each() or at() date rules.',
    );
});

it('validates relative values only when they resolve within the supported years', function (): void {
    $field = AllowedField::date('created');

    expect($field->validate('today-100y'))->toBeTrue()
        ->and($field->validate('today+999999y'))->toBeFalse()
        ->and($field->validate('-999999w'))->toBeFalse();
});

it('accepts only calendar dates and day anchors without time', function (
    string $value,
    bool $valid,
): void {
    $field = AllowedField::date('due')->withoutTime();
    $partial = AllowedField::date('due')->allowPartialDates()->withoutTime();

    expect($field->validate($value))->toBe($valid)
        ->and($partial->validate($value))->toBe($valid)
        ->and(AllowedField::date('due')->validate($value))->toBeTrue();
})->with([
    ['2024-03-15', true],
    ['today', true],
    ['YESTERDAY', true],
    ['tomorrow+1w', true],
    ['today-7d', true],
    ['today+1M', true],
    ['2024-03-15T10', false],
    ['2024-03-15T10:30Z', false],
    ['2024-03-15T00:00:00+02:00', false],
    ['now', false],
    ['NOW-1d', false],
    ['-7d', false],
    ['+3d', false],
]);

it('combines dates without time with partial dates in either order', function (): void {
    foreach ([
        AllowedField::date('due')->withoutTime()->allowPartialDates(),
        AllowedField::date('due')->allowPartialDates()->withoutTime(),
    ] as $field) {
        expect($field->validate('2024'))->toBeTrue()
            ->and($field->validate('2024-03'))->toBeTrue()
            ->and($field->validate('now'))->toBeFalse();
    }

    expect(AllowedField::date('due')->withoutTime()->validate('2024'))->toBeFalse();
});

it('reports times on fields without time at the value in comparisons, lists and ranges', function (
    string $query,
    int $position,
): void {
    try {
        SearchParser::query($query)->allowedFields([
            AllowedField::date('due')->withoutTime(),
        ])->parse();

        test()->fail('Expected an invalid date value.');
    } catch (InvalidFieldValueException $invalidFieldValueException) {
        expect($invalidFieldValueException->getField())->toBe('due')
            ->and($invalidFieldValueException->getPosition())->toBe($position);
    }
})->with([
    ['due:<now', 5],
    ['due:today,2024-03-15T10:00', 4],
    ['due:[today TO -7d]', 4],
    ['due:today..now', 4],
]);

it('rejects time defaults on fields without time regardless of configuration order', function (
    Closure $field,
): void {
    SearchParser::query('')->allowedFields([$field()])->parse();
})->throws(InvalidArgumentException::class)->with([
    fn (): AllowedField => AllowedField::date('due')->withoutTime()->default('now'),
    fn (): AllowedField => AllowedField::date('due')->default('now')->withoutTime(),
]);

it('keeps day-anchor defaults on fields without time as text', function (): void {
    expect(SearchParser::query('')->allowedFields([
        AllowedField::date('due')->withoutTime()->default('today'),
    ])->parse()->getValue())->toBe('today');
});

it('supports dates without time on shared and positional array item rules', function (): void {
    $shared = AllowedField::array('dates')->each(
        fn (AllowedFieldItemRules $rules): AllowedFieldItemRules => $rules->date()->withoutTime(),
    );
    $tuple = AllowedField::array('dates')
        ->at(
            0,
            fn (AllowedFieldItemRules $rules): AllowedFieldItemRules
                => $rules->date()->withoutTime(),
        )
        ->at(1, fn (AllowedFieldItemRules $rules): AllowedFieldItemRules => $rules->date());

    expect(
        SearchParser::query('dates:today,2024-03-15')
            ->allowedFields([$shared])
            ->parse()
            ->getValues(),
    )
        ->toBe(['today', '2024-03-15'])
        ->and(SearchParser::query('dates:today,now')->allowedFields([$tuple])->parse()->getValues())
        ->toBe(['today', 'now'])
        ->and(fn (): null|NodeInterface => SearchParser::query('dates:now,today')
            ->allowedFields([$tuple])
            ->parse())
        ->toThrow(InvalidFieldValueException::class);
});

it('rejects dates without time on other types, custom formats and array fields', function (
    Closure $configure,
): void {
    $configure();
})->throws(InvalidArgumentException::class)->with([
    fn (): AllowedField => AllowedField::string('value')->withoutTime(),
    fn (): AllowedField => AllowedField::array('value')->withoutTime(),
    fn (): AllowedField => AllowedField::date('value', 'Y-m-d')->withoutTime(),
    fn (): AllowedFieldItemRules => new AllowedFieldItemRules()->withoutTime(),
    fn (): AllowedFieldItemRules => new AllowedFieldItemRules()->date('Y-m-d')->withoutTime(),
    fn (): AllowedFieldItemRules => new AllowedFieldItemRules()->date()->withoutTime()->string(),
    fn (): AllowedFieldItemRules
        => new AllowedFieldItemRules()->date()->withoutTime()->date('Y-m-d'),
]);
