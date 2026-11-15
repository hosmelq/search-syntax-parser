<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\AST\Node\TermNode;
use HosmelQ\SearchSyntaxParser\AST\Node\UnaryOperatorNode;
use HosmelQ\SearchSyntaxParser\AST\Visitor\NegationNormalFormTransformer;
use HosmelQ\SearchSyntaxParser\Query\Bounds;
use HosmelQ\SearchSyntaxParser\Query\ClauseCompiler;
use HosmelQ\SearchSyntaxParser\Query\Operator;
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\Field;

it('excludes term fields whose types or rules reject the candidate', function (
    Field $field,
    string $query,
): void {
    $node = SearchParser::query($query)->allowLeadingWildcards()->parse();

    expect(new ClauseCompiler()->term($field, $node))->toBeNull()
        ->and(new ClauseCompiler()->term($field, $node, true))->toBeNull();
})->with([
    'integer' => [Field::integer('value'), '1.5'],
    'uuid' => [Field::uuid('value'), 'coffee'],
    'validation' => [Field::string('value')->min(4), 'a'],
    'operator' => [Field::string('value')->operators('!='), 'coffee'],
    'wildcard operator' => [Field::string('value')->operators('!='), 'coff*'],
    'numeric wildcard' => [Field::numeric('value'), '10*'],
    'mapped wildcard' => [Field::string('value')->map(['coffee' => 'c']), 'coff*'],
    'disabled wildcard' => [Field::string('value')->withoutWildcards(), 'coff*'],
    'leading wildcard' => [Field::string('value'), '*fee'],
    'short wildcard' => [Field::string('value')->minWildcardLength(5), 'coff*'],
    'infix words wildcard' => [Field::string('value')->words(), 'co*ee'],
]);

it('propagates invalid term alias configuration', function (): void {
    $field = Field::string('value')->alias('mine', fn (): array => []);

    new ClauseCompiler()->term($field, new TermNode('mine'));
})->throws(InvalidArgumentException::class, 'alias must resolve');

it('compiles scalar leaves and their complements with source positions', function (
    string $query,
    Operator $operator,
    mixed $value,
): void {
    $field = Field::integer('quantity', 'stored_quantity')->nullable();

    foreach ([false, true] as $negated) {
        $ast = SearchParser::query('  '.($negated ? 'NOT ' : '').$query)
            ->allowedFields([$field])
            ->parse();
        $ast = new NegationNormalFormTransformer()->transform($ast);
        $leaf = $ast instanceof UnaryOperatorNode ? $ast->getOperand() : $ast;
        $clauses = new ClauseCompiler()->compile($field, $leaf, $negated);

        expect($clauses)->toHaveCount(1)
            ->and($clauses[0]->field)->toBe('quantity')
            ->and($clauses[0]->operator)->toBe($negated ? $operator->complement() : $operator)
            ->and($clauses[0]->value)->toEqual($value)
            ->and($clauses[0]->position)->toBe($negated ? 6 : 2)
            ->and($clauses[0]->negated)->toBe($negated)
            ->and($clauses[0]->text)->toBeFalse()
            ->and($clauses[0]->fullText)->toBeNull();
    }
})->with([
    ['quantity:10', Operator::Equal, 10],
    ['quantity:>10', Operator::Greater, 10],
    ['quantity:>=10', Operator::GreaterOrEqual, 10],
    ['quantity:<10', Operator::Less, 10],
    ['quantity:<=10', Operator::LessOrEqual, 10],
    ['quantity:5,10', Operator::In, [5, 10]],
    ['quantity:5..10', Operator::Between, new Bounds(5, 10)],
    ['quantity:*..10', Operator::Between, new Bounds(null, 10)],
    ['quantity:5..*', Operator::Between, new Bounds(5, null)],
    ['quantity:*..*', Operator::Between, new Bounds(null, null)],
    ['quantity:*', Operator::Exists, null],
]);

it('converts stored values without mutating the parsed leaves', function (
    string $query,
    mixed $expected,
): void {
    $field = Field::decimal('price', 0, 2)->scale(100);
    $node = SearchParser::query($query)->allowedFields([$field])->parse();
    $before = serialize($node);
    $clause = new ClauseCompiler()->compile($field, $node)[0];

    expect($clause->value)->toEqual($expected)
        ->and($clause->text)->toBeFalse()
        ->and(serialize($node))->toBe($before);
})->with([
    ['price:10.50', 1_050],
    ['price:5,10.50', [500, 1_050]],
    ['price:5..10.50', new Bounds(500, 1_050)],
    ['price:*..10.50', new Bounds(null, 1_050)],
    ['price:5..*', new Bounds(500, null)],
]);

it('expands text lists into alternatives with quoted overrides and complements', function (
    bool $negated,
): void {
    $field = Field::string('title')->contains();
    $node = SearchParser::query(' title:cof,"tea"')->allowedFields([$field])->parse();
    $clauses = new ClauseCompiler()->compile($field, $node, $negated);

    expect($clauses)->toHaveCount(2)
        ->and($clauses[0]->operator)->toBe($negated ? Operator::NotLike : Operator::Like)
        ->and($clauses[0]->value)->toBe('%cof%')
        ->and($clauses[1]->operator)->toBe($negated ? Operator::NotEqual : Operator::Equal)
        ->and($clauses[1]->value)->toBe('tea')
        ->and($clauses[0]->position)->toBe(1)
        ->and($clauses[1]->position)->toBe(1)
        ->and($clauses[0]->negated)->toBe($negated)
        ->and($clauses[1]->negated)->toBe($negated);
})->with([false, true]);

it('keeps external dynamic names while converting their values', function (): void {
    $field = Field::string('meta.*', 'attributes.*')->keys('/^[a-z]+$/D')->map(['red' => 1]);
    $node = SearchParser::query('meta.color:red')->allowedFields([$field])->parse();
    $clause = new ClauseCompiler()->compile($field, $node)[0];

    expect($clause->field)->toBe('meta.color')
        ->and($clause->value)->toBe(1)
        ->and($clause->text)->toBeFalse();
});

it('keeps quoted exact lists together even when the field defaults to prefix matching', function (): void {
    $field = Field::string('title')->prefix();
    $node = SearchParser::query('title:"coffee","tea"')->allowedFields([$field])->parse();
    $clauses = new ClauseCompiler()->compile($field, $node);

    expect($clauses)->toHaveCount(1)
        ->and($clauses[0]->operator)->toBe(Operator::In)
        ->and($clauses[0]->value)->toBe(['coffee', 'tea'])
        ->and($clauses[0]->text)->toBeTrue();
});

it('keeps spanless defaults and programmatic terms without invented positions', function (): void {
    $field = Field::string('title')->default('coffee');
    $node = SearchParser::query('')->allowedFields([$field])->parse();

    expect(new ClauseCompiler()->compile($field, $node)[0]->position)->toBeNull()
        ->and(new ClauseCompiler()->term($field, new TermNode('tea'))->position)->toBeNull();
});

it('normalizes inequalities from parsed leaves and combines surrounding negation', function (
    Field $field,
    string $query,
    array $operators,
    array $values,
    bool $negated,
): void {
    $node = SearchParser::query('  '.$query)->allowedFields([$field])->parse();
    $clauses = new ClauseCompiler()->compile($field, $node, $negated);

    expect($clauses)->toHaveSameSize($operators);

    foreach ($clauses as $index => $clause) {
        expect($clause->operator)->toBe(
            $negated ? $operators[$index]->complement() : $operators[$index],
        )
            ->and($clause->value)->toBe($values[$index])
            ->and($clause->negated)->toBe(! $negated)
            ->and($clause->position)->toBe(2);
    }
})->with([
    [
        Field::string('status'),
        'status:!=sold,expired',
        [Operator::NotIn],
        [['sold', 'expired']],
    ],
    [Field::string('title'), 'title:!=co*', [Operator::NotLike], ['co%']],
    [
        Field::string('title')->contains(),
        'title:!=coffee',
        [Operator::NotLike],
        ['%coffee%'],
    ],
    [
        Field::string('title')->contains(),
        'title:!=cof,"tea"',
        [Operator::NotLike, Operator::NotEqual],
        ['%cof%', 'tea'],
    ],
])->with([false, true]);

it('preserves phrases words and prefixes when compiling full-text leaves', function (
    string $query,
    array $words,
    bool $phrase,
    bool $prefix,
): void {
    $field = Field::string('text')->words();
    $node = SearchParser::query($query)->allowedFields([$field])->parse();
    $clause = new ClauseCompiler()->compile($field, $node)[0];

    expect($clause->fullText->words)->toBe($words)
        ->and($clause->fullText->phrase)->toBe($phrase)
        ->and($clause->fullText->prefix)->toBe($prefix)
        ->and($clause->operator)->toBe($prefix ? Operator::Like : Operator::Equal);
})->with([
    ['text:coffee|ceramic', ['coffee', 'ceramic'], false, false],
    ['text:"coffee ceramic"', ['coffee', 'ceramic'], true, false],
    ['text:"coffee*"', ['coffee'], true, false],
    ['text:coff*', ['coff'], false, true],
    ['text:"fresh coff"*', ['fresh', 'coff'], false, true],
    ['text:"!!!"', [], true, false],
]);

it('preserves whole-word phrase flags for every list item', function (bool $negated): void {
    $field = Field::string('text')->words();
    $node = SearchParser::query('text:"coffee ceramic",espresso')->allowedFields([$field])->parse();
    $clauses = new ClauseCompiler()->compile($field, $node, $negated);

    expect($clauses)->toHaveCount(2)
        ->and($clauses[0]->fullText->words)->toBe(['coffee', 'ceramic'])
        ->and($clauses[0]->fullText->phrase)->toBeTrue()
        ->and($clauses[1]->fullText->words)->toBe(['espresso'])
        ->and($clauses[1]->fullText->phrase)->toBeFalse()
        ->and($clauses[0]->operator)->toBe($negated ? Operator::NotEqual : Operator::Equal)
        ->and($clauses[1]->operator)->toBe($clauses[0]->operator);
})->with([false, true]);

it('resolves eligible terms with storage conversions and text matching', function (
    Field $field,
    string $query,
    Operator $operator,
    mixed $value,
    bool $text,
): void {
    $node = SearchParser::query(' '.$query)->parse();

    foreach ([false, true] as $negated) {
        $clause = new ClauseCompiler()->term($field, $node, $negated);

        expect($clause->field)->toBe('value')
            ->and($clause->operator)->toBe($negated ? $operator->complement() : $operator)
            ->and($clause->value)->toBe($value)
            ->and($clause->text)->toBe($text)
            ->and($clause->position)->toBe(1)
            ->and($clause->negated)->toBe($negated);
    }
})->with([
    [Field::string('value'), 'a%_!', Operator::Like, '%a!%!_!!%', true],
    [Field::string('value')->prefix(), 'coffee', Operator::Like, '%coffee%', true],
    [Field::string('value'), '"co*fee"', Operator::Like, '%co*fee%', true],
    [Field::integer('value'), '10', Operator::Equal, 10, false],
    [Field::decimal('value', 0, 2)->scale(100), '10.50', Operator::Equal, 1_050, false],
    [Field::string('value')->map(['low' => 0]), 'low', Operator::Equal, 0, false],
    [Field::integer('value')->map([1 => 'open']), '1', Operator::Equal, 'open', false],
    [Field::integer('value')->alias('small', 1), 'small', Operator::Equal, 1, false],
    [Field::integer('value')->ignore(1), '1', Operator::Equal, 1, false],
    [Field::string('value'), 'coff*', Operator::Like, 'coff%', true],
]);

it('supports whole-word terms phrases and word prefixes', function (
    string $query,
    array $words,
    bool $phrase,
    bool $prefix,
): void {
    $node = SearchParser::query($query)->parse();
    $field = Field::string('text')->words();
    $clause = new ClauseCompiler()->term($field, $node);

    expect($clause->fullText->words)->toBe($words)
        ->and($clause->fullText->phrase)->toBe($phrase)
        ->and($clause->fullText->prefix)->toBe($prefix);
})->with([
    ['coffee-ceramic', ['coffee', 'ceramic'], false, false],
    ['"coffee ceramic"', ['coffee', 'ceramic'], true, false],
    ['coff*', ['coff'], false, true],
    ['"fresh coff"*', ['fresh', 'coff'], false, true],
]);

it('translates text matches and wildcard segments with literal LIKE escapes', function (
    string $query,
    string $mode,
    Operator $operator,
    string $value,
): void {
    $field = Field::string('title')->$mode();
    $node = SearchParser::query($query)->allowedFields([$field])->parse();
    $clause = new ClauseCompiler()->compile($field, $node)[0];

    expect($clause->operator)->toBe($operator)
        ->and($clause->value)->toBe($value)
        ->and($clause->text)->toBeTrue();
})->with([
    ['title:a%_!', 'prefix', Operator::Like, 'a!%!_!!%'],
    ['title:a%_!', 'contains', Operator::Like, '%a!%!_!!%'],
    ['title:"a%_!"', 'contains', Operator::Equal, 'a%_!'],
    ['title:a%_!*z', 'prefix', Operator::Like, 'a!%!_!!%z'],
    ['title:"a*%"*', 'prefix', Operator::Like, 'a*!%%'],
    ['title:>coffee', 'contains', Operator::Greater, 'coffee'],
]);
