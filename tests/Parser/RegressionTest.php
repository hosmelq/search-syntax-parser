<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\Exception\InvalidFieldValueException;
use HosmelQ\SearchSyntaxParser\Exception\ParseException;
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;

it('keeps punctuation and unicode values intact', function (
    string $input,
    string $value,
): void {
    expect(SearchParser::query($input)->allowAnyField()->build())->toBe([
        'default' => false,
        'field' => mb_strstr($input, ':', true),
        'operator' => '=',
        'type' => 'comparison',
        'value' => $value,
    ]);
})->with([
    ['nombre:José', 'José'],
    ['niño:José', 'José'],
    ['title:t-shirt', 't-shirt'],
    ['sku:ABC-123', 'ABC-123'],
    ['email:john@example.com', 'john@example.com'],
    ['title:-foo', '-foo'],
    ['title:and', 'and'],
    ['title:OR', 'OR'],
    ['title:Not', 'Not'],
]);

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

it('accepts negative comparisons, lists and ranges', function (): void {
    expect(SearchParser::query('temp:>-5')->allowAnyField()->parse()->getValue())->toBe(-5)
        ->and(SearchParser::query('temp>-5')->allowAnyField()->parse()->getValue())->toBe(-5)
        ->and(SearchParser::query('temp:-5,-1')->allowAnyField()->parse()->getValues())->toBe([
            -5,
            -1,
        ])
        ->and(SearchParser::query('temp:[-5 TO 5]')->allowAnyField()->build())->toBe([
            'field' => 'temp',
            'from' => -5,
            'to' => 5,
            'type' => 'range',
        ]);
});

it('reserves TO only as the separator inside a range', function (): void {
    $ast = SearchParser::query('go to market')->parse();

    expect($ast->getLeft()->getRight()->getValue())->toBe('to')
        ->and(SearchParser::query('title:TO')->allowAnyField()->parse()->getValue())->toBe('TO');
});

it('accepts keyword tokens in lists and range bounds', function (): void {
    expect(SearchParser::query('title:and,or,not')->allowAnyField()->parse()->getValues())->toBe([
        'and',
        'or',
        'not',
    ])
        ->and(SearchParser::query('title:[AND TO OR]')->allowAnyField()->build())->toBe([
            'field' => 'title',
            'from' => 'AND',
            'to' => 'OR',
            'type' => 'range',
        ]);
});

it('accepts ISO datetimes as complete values', function (string $datetime): void {
    expect(
        SearchParser::query('created_at:>'.$datetime)->allowAnyField()->parse()->getValue(),
    )->toBe(
        $datetime,
    );
})->with([
    '2025-01-01T12:30', '2025-01-01T12:30:45', '2025-01-01T12:30:45.123Z',
    '2025-01-01T12:30:45Z', '2025-01-01T12:30:45+05:30', '2025-01-01t12:30:45.123-0530',
]);

it('keeps text attached to a datetime in the same value', function (string $value): void {
    expect(SearchParser::query('file:'.$value)->allowAnyField()->parse()->getValue())->toBe($value);
})->with(['2025-01-01T12:30:00-backup', '2025-01-01T12:30Zfoo', '2025-01-01T12:30:45+05:30abc']);

it('accepts quoted URLs and times', function (string $value): void {
    expect(SearchParser::query('value:"'.$value.'"')->allowAnyField()->parse()->getValue())->toBe(
        $value,
    );
})->with(['https://example.com', '12:30']);

it('rejects unterminated quoted values', function (string $input): void {
    SearchParser::query($input)->allowAnyField()->build();
})->throws(ParseException::class, 'Unterminated quoted string.')->with([
    'title:"Coffee',
    "title:'Coffee",
    '"',
    "'",
]);

it('rejects invalid UTF-8', function (string $input): void {
    SearchParser::query($input)->allowAnyField()->parse();
})->throws(ParseException::class, 'Search query must be valid UTF-8.')->with([
    "title:Coffee price:<10 \xff",
    "\xff",
]);

it('tokenizes very long words', function (): void {
    expect(
        SearchParser::query('title:'.str_repeat('a', 1_000_000))
            ->allowAnyField()
            ->maxLength(2_000_000)
            ->parse()
            ->getValue(),
    )->toHaveLength(
        1_000_000,
    );
});

it('reports tokenizer limits separately from invalid UTF-8', function (): void {
    $limit = ini_get('pcre.backtrack_limit');

    ini_set('pcre.backtrack_limit', '1');

    try {
        expect(fn (): mixed => SearchParser::query('title:Coffee')->allowAnyField()->parse())
            ->toThrow(ParseException::class, 'Search query is too complex to tokenize.');
    } finally {
        ini_set('pcre.backtrack_limit', (string) $limit);
    }
});

it('rejects syntax that cannot be a value', function (string $input): void {
    SearchParser::query($input)->allowAnyField()->parse();
})->throws(ParseException::class, 'Expected value.')->with([
    'title:(a OR b)', 'title:[1 TO (b)]', 'title:a,(b)', 'title:- 5',
    'title:-AND', "title:-\u{00A0}5", 'title:>', 'title:]', '*',
]);

it('keeps negation above AND and AND above OR', function (): void {
    $ast = SearchParser::query('a OR b c AND NOT d')->parse();

    expect($ast->getOperator())->toBe('OR')
        ->and($ast->getRight()->getOperator())->toBe('AND')
        ->and($ast->getRight()->getLeft()->getOperator())->toBe('AND')
        ->and($ast->getRight()->getRight()->getOperator())->toBe('NOT')
        ->and(SearchParser::query('(a OR b) c')->parse()->getLeft()->getOperator())->toBe('OR');
});

it('rejects nesting above the default limit', function (string $input): void {
    SearchParser::query($input)->allowAnyField()->build();
})->throws(ParseException::class, 'maximum depth of 32')->with([
    str_repeat('(', 33).'a'.str_repeat(')', 33),
    str_repeat('NOT ', 33).'a',
    str_repeat('-', 33).'a',
    str_repeat('(NOT ', 17).'a'.str_repeat(')', 17),
]);

it('rejects leaf clauses above the default limit', function (string $input): void {
    SearchParser::query($input)->allowAnyField()->parse();
})->throws(ParseException::class, 'maximum of 1024 clauses')->with([
    str_repeat('a ', 1_025),
    implode(' OR ', array_fill(0, 1_025, 'f:*')),
]);

it('allows the depth and clause boundaries', function (): void {
    expect(
        SearchParser::query(str_repeat('(', 32).'a'.str_repeat(')', 32))->parse()->getValue(),
    )->toBe(
        'a',
    )
        ->and(SearchParser::query(str_repeat('NOT ', 32).'a')->build()['operator'])->toBe('NOT')
        ->and(SearchParser::query(str_repeat('a ', 1_024))->parse()->getOperator())->toBe('AND');
});

it('counts lists, ranges, comparisons and exists queries once each', function (): void {
    $parser = SearchParser::query('a:1,2,3 b:[1 TO 3] c:1 d:*')->allowAnyField()->maxClauses(4);

    expect($parser->parse()->getOperator())->toBe('AND')
        ->and(fn (): mixed => $parser->maxClauses(3)->build())->toThrow(
            ParseException::class,
            'maximum of 3 clauses',
        );
});

it('supports configurable limits', function (): void {
    $parser = SearchParser::query('NOT (a b)')->maxDepth(2)->maxClauses(2)->maxLength(9);

    expect($parser->parse()->getOperator())->toBe('NOT')
        ->and(fn (): mixed => $parser->maxDepth(1)->build())->toThrow(
            ParseException::class,
            'maximum depth of 1',
        )
        ->and(fn (): mixed => $parser->maxLength(8)->build())->toThrow(
            ParseException::class,
            'maximum length of 8 characters',
        );
});

it('rejects queries longer than the default length before tokenizing', function (): void {
    expect(SearchParser::query(str_repeat('é', 10_000))->parse()->getValue())->toHaveLength(10_000)
        ->and(fn (): mixed => SearchParser::query(str_repeat('(', 10_001))->parse())
        ->toThrow(
            ParseException::class,
            'Search query exceeds the maximum length of 10000 characters.',
        );
});

it('rejects nonpositive limits', function (int $limit): void {
    expect(fn (): SearchParser => SearchParser::query('a')->maxDepth($limit))->toThrow(
        InvalidArgumentException::class,
    )
        ->and(fn (): SearchParser => SearchParser::query('a')->maxClauses($limit))->toThrow(
            InvalidArgumentException::class,
        )
        ->and(fn (): SearchParser => SearchParser::query('a')->maxLength($limit))->toThrow(
            InvalidArgumentException::class,
        );
})->with([0, -1]);

it('keeps unquoted * range bounds open without casting or validating them', function (
    string $query,
    null|int $from,
    null|int $to,
): void {
    expect(
        SearchParser::query($query)->allowedFields([AllowedField::integer('x')->max(100)])->build(),
    )->toBe(
        [
            'field' => 'x',
            'from' => $from,
            'to' => $to,
            'type' => 'range',
        ],
    );
})->with([
    ['x:[* TO 50]', null, 50],
    ['x:[10 TO *]', 10, null],
    ['x:[* TO *]', null, null],
]);

it('validates quoted asterisk range bounds as literal values', function (): void {
    SearchParser::query('x:["*" TO 5]')->allowedFields([AllowedField::integer('x')])->parse();
})->throws(InvalidFieldValueException::class);

it('accepts escaped line breaks inside quotes', function (): void {
    expect(SearchParser::query("title:\"a\\\nb\"")->allowAnyField()->parse()->getValue())->toBe(
        "a\\\nb",
    );
});

it('preserves adjacency for negative values after unicode and escaped quotes', function (
    string $prefix,
): void {
    expect(
        SearchParser::query($prefix.' temp:>-5')->allowAnyField()->parse()->getRight()->getValue(),
    )->toBe(
        -5,
    );
})->with(['nombre:José', 'title:"a\\\"b"']);
