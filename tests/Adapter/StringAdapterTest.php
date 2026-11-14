<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\Adapter\StringAdapter;
use HosmelQ\SearchSyntaxParser\AST\Node\ComparisonNode;
use HosmelQ\SearchSyntaxParser\AST\Node\TermNode;
use HosmelQ\SearchSyntaxParser\AST\Node\WildcardNode;
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;

it('normalizes queries into explicit syntax', function (string $query, string $expected): void {
    expect(SearchParser::query($query)->build('string'))->toBe($expected);
})->with([
    ['  a b ', 'a AND b'],
    ['-name:coffee', 'NOT name:coffee'],
    ['(a OR b) c', '(a OR b) AND c'],
    ['a OR b AND c', 'a OR b AND c'],
    ['NOT (a b)', 'NOT (a AND b)'],
    ['NOT -a', 'NOT NOT a'],
    ['(a AND b) OR c', 'a AND b OR c'],
    ['name!=a', 'name:!=a'],
    ['name:"a b"*', 'name:"a b"*'],
    ['name:"Co"*', 'name:Co*'],
    ['name:a**b***c', 'name:a*b*c'],
    ['***', '""*'],
    ['name:***', 'name:""*'],
    ['name:"a*b"*', 'name:"a*b"*'],
    ['price:[* TO 10.00]', 'price:[* TO 10.0]'],
    ['flag:TRUE', 'flag:true'],
    ['flag:"TRUE"', 'flag:"TRUE"'],
    ['code:"123"', 'code:"123"'],
    ['code:01234', 'code:01234'],
    ['name:"AND"', 'name:"AND"'],
    ['"123"', '"123"'],
    ['name:"-foo"', 'name:"-foo"'],
    ['name:""', 'name:""'],
]);

it('round trips observable array output', function (string $query): void {
    $parser = SearchParser::query($query);

    expect(SearchParser::query($parser->build('string'))->build())->toBe($parser->build());
})->with([
    'a AND b OR NOT c', 'a AND (b AND c)', 'a OR (b OR c)',
    '(a OR b) AND (c OR d)', 'a OR b AND (c OR d)',
    'NOT (a OR b AND c)', 'NOT NOT a', 'name:*', 'NOT name:*',
    'name:a,b,"c d"', 'name:!=a,b', 'name:jo*,"literal*",42',
    'name:!=jo*,bob', 'price:[-10 TO 50]', 'price:[* TO *]',
    'name:["AND" TO "OR"]', 'name:"co*fee"', '"co*fee"',
    'co*fee', '*fee', 'nike*', '***', '"a b"*', '"a*b"*', '""*',
    'name:"a\\\\b\\"c"', 'name:"a\\\'b"', 'nombre:José',
    'created_at:>=2025-01-01T12:30:45.123Z', 'name:and,OR,Not',
    'value:1.0', 'value:-0.5', 'value:0.000000001',
    'value:100000000000000000000.0', 'value:99999999999999999999',
    'value:true', 'value:false', 'value:"true"', 'value:"1.0"',
    '"a\nb"', 'email:john@example.com', "name:O'Brien", 'name:hello!world', 'a*b!c', 'a*b\\c', 'a*b"c',
]);

it('preserves external field names with every field node', function (string $query): void {
    $field = AllowedField::string('name', 'full_name');
    $parser = SearchParser::query($query)->allowedFields([$field]);
    $text = $parser->build('string');

    expect($text)->not->toContain('full_name')
        ->and(SearchParser::query($text)->allowedFields([$field])->build())->toBe($parser->build());
})->with(['name:coffee', 'name:a,b', 'name:[a TO z]', 'name:*', 'name:jo*']);

it('serializes floats without changing their inferred type or value', function (
    float $value,
): void {
    $adapter = new StringAdapter();
    $text = $adapter->build(new ComparisonNode('value', '=', $value));

    expect(SearchParser::query($text)->build()['value'])->toBe($value);
})->with([0.0, -0.0, 1.0, 1.5, 1e-20, -1e-20, 1e20, PHP_FLOAT_MAX, PHP_FLOAT_MIN, 5e-324]);

it('rejects wildcard segments that the grammar cannot express', function (array $segments): void {
    new StringAdapter()->build(new WildcardNode('name', '=', $segments));
})->throws(LogicException::class)->with([
    [['a', 'b c']], [['a b', 'c']], [['a*b', 'c']], [['a']], [[]], [['a', '', 'b']],
]);

it('quotes programmatic terms whose text needs literal syntax', function (string $value): void {
    $text = new StringAdapter()->build(new TermNode($value));

    expect(SearchParser::query($text)->parse()->getValue())->toBe($value);
})->with(['', 'a b', '*', 'co*fee', 'AND', '-coffee']);

it('quotes values that would merge with a comparison operator', function (
    string $query,
    string $expected,
): void {
    $parser = SearchParser::query($query);
    $text = $parser->build('string');

    expect($text)->toBe($expected)
        ->and(SearchParser::query($text)->build())->toBe($parser->build());
})->with([
    ['x:< =5', 'x:<"=5"'],
    ['x:> =a', 'x:>"=a"'],
    ['x:=5', 'x:"=5"'],
]);

it('refuses field names the query syntax cannot express', function (string $name): void {
    SearchParser::query('')
        ->allowedFields([AllowedField::string($name)->default('x')])
        ->build('string');
})->throws(LogicException::class, 'This field name cannot be expressed by the query syntax.')
    ->with(['my field', 'AND', '-x', '*', 'a:b']);
