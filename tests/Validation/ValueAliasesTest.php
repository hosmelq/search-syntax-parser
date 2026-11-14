<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\Exception\InvalidFieldValueException;
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;

it('resolves scalar aliases without casting their result', function (
    bool|float|int|string $value,
): void {
    $field = new AllowedField('x')->alias('token', $value);

    expect(SearchParser::query('x:token')->allowedFields([$field])->parse()->getValue())->toBe(
        $value,
    );
})->with([true, false, 0, 42, 1.5, '007', 'true', '*']);

it('calls aliases without arguments at parse time on every parse', function (): void {
    $userId = 7;
    $calls = [];
    $field = AllowedField::integer('owner')->alias(
        '@me',
        static function (...$arguments) use (&$userId, &$calls): int {
            $calls[] = $arguments;

            return $userId;
        },
    );
    $parser = SearchParser::query('owner:@me')->allowedFields([$field]);

    expect($calls)->toBeEmpty()->and($parser->parse()->getValue())->toBe(7);

    $userId = 9;

    expect($parser->parse()->getValue())->toBe(9)->and($calls)->toBe([[], []]);
});

it('matches aliases exactly and only in unquoted field values', function (
    string $query,
    string $value,
): void {
    $field = AllowedField::string('x')->alias('yes', 'resolved');

    expect(SearchParser::query($query)->allowedFields([$field])->parse()->getValue())->toBe($value);
})->with([
    ['x:yes', 'resolved'], ['x:YES', 'YES'], ['x:Yes', 'Yes'],
    ['x:"yes"', 'yes'], ["x:'yes'", 'yes'], ['x:yesmore', 'yesmore'],
]);

it('resolves aliases in lists and range bounds', function (): void {
    $field = AllowedField::integer('x')->alias('@me', 7)->alias('@max', static fn (): int => 10);
    $list = SearchParser::query('x:@me,@max')->allowedFields([$field])->parse();
    $range = SearchParser::query('x:[@me TO @max]')->allowedFields([$field])->parse();

    expect($list->getValues())->toBe([7, 10])
        ->and($range->getFrom())->toBe(7)->and($range->getTo())->toBe(10);
});

it('resolves aliases before applying shared and per-index array validation', function (): void {
    $field = AllowedField::array('x')
        ->alias('yes', true)
        ->alias('@me', 7)
        ->at(0, static fn ($rules) => $rules->boolean())
        ->at(1, static fn ($rules) => $rules->integer()->min(5));

    expect(SearchParser::query('x:yes,@me')->allowedFields([$field])->parse()->getValues())->toBe([
        true,
        7,
    ]);
});

it('validates alias results in every value context', function (string $query): void {
    SearchParser::query($query)->allowedFields([
        AllowedField::integer('x')->min(10)->alias('@me', 7),
    ])->parse();
})->throws(InvalidFieldValueException::class)->with([
    'x:@me',
    'x:10,@me',
    'x:[@me TO 10]',
    'x:[10 TO @me]',
]);

it('does not cast alias results to satisfy field types', function (): void {
    SearchParser::query('x:token')->allowedFields([
        AllowedField::string('x')->alias('token', 7),
    ])->parse();
})->throws(InvalidFieldValueException::class);

it('rejects alias closures returning nonscalar values', function (mixed $value): void {
    SearchParser::query('x:token')->allowedFields([
        new AllowedField('x')->alias('token', static fn (): mixed => $value),
    ])->parse();
})->throws(InvalidArgumentException::class)->with([null, [[]], [(object) []]]);

it('does not resolve aliases for trusted defaults or quoted range bounds', function (): void {
    $field = AllowedField::string('x')->alias('token', 'resolved')->default('token');

    expect(SearchParser::query('')->allowedFields([$field])->parse()->getValue())->toBe('token')
        ->and(
            SearchParser::query('x:["token" TO token]')
                ->allowedFields([$field])
                ->parse()
                ->getFrom(),
        )->toBe(
            'token',
        );
});

it('never resolves quoted aliases inside lists or either range bound', function (): void {
    $field = AllowedField::string('x')->alias('yes', 'resolved');

    expect(SearchParser::query('x:"yes",yes')->allowedFields([$field])->parse()->getValues())
        ->toBe(['yes', 'resolved'])
        ->and(SearchParser::query('x:[yes TO "yes"]')->allowedFields([$field])->parse()->getTo())
        ->toBe('yes');
});

it('rejects a closure returned by an alias closure', function (): void {
    SearchParser::query('x:token')->allowedFields([
        new AllowedField('x')->alias('token', static fn (): Closure => static fn (): int => 1),
    ])->parse();
})->throws(InvalidArgumentException::class);

it('rejects alias tokens that contain wildcards', function (): void {
    AllowedField::integer('owner')->alias('a*', 5);
})->throws(InvalidArgumentException::class, 'A value alias token cannot contain a wildcard.');
