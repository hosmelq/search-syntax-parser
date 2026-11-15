<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\Exception\ErrorReason;
use HosmelQ\SearchSyntaxParser\Exception\InvalidFieldException;
use HosmelQ\SearchSyntaxParser\Exception\InvalidFieldOperatorException;
use HosmelQ\SearchSyntaxParser\Exception\InvalidFieldValueException;
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;
use HosmelQ\SearchSyntaxParser\Validation\TextMatch;

it('keeps typed dynamic names in the AST and expands internal names for every field node', function (
    string $query,
): void {
    $field = AllowedField::string('meta.*', 'attributes.*')->keys('/^[a-z]+$/D')->prefix();
    $parser = SearchParser::query($query)->allowedFields([$field]);

    expect($parser->getAllowedField('meta.color'))->toBe($field)
        ->and($parser->parse()->getField())->toBe('meta.color')
        ->and($parser->build()['field'])->toBe('attributes.color')
        ->and($parser->build('string'))->toStartWith('meta.color:');

    $node = SearchParser::query('meta.color:blue')->allowedFields([$field])->parse();

    expect($field->getTextMatch($node))->toBe(TextMatch::Prefix);
})->with([
    'meta.color:blue',
    'meta.color:blue,red',
    'meta.color:[a TO z]',
    'meta.color:*',
    'meta.color:bl*',
]);

it('applies pattern value casts validation aliases ignores and storage conversions', function (): void {
    $field = AllowedField::integer('metrics.*', 'counters.*')
        ->keys(fn (string $key): bool => in_array($key, ['views', 'likes'], true))
        ->alias('all', 2)
        ->min(1)
        ->ignore(0)
        ->scale(100);

    expect(
        SearchParser::query('metrics.views:all')->allowedFields([$field])->build()['value'],
    )->toBe(
        200,
    )
        ->and(
            SearchParser::query('metrics.likes:0,2')->allowedFields([$field])->build()['values'],
        )->toBe(
            [200],
        )
        ->and(SearchParser::query('metrics.views:0..2')->allowedFields([$field])->build())->toBe([
            'field' => 'counters.views',
            'from' => null,
            'to' => 200,
            'type' => 'range',
        ]);

    SearchParser::query('metrics.likes:bad')->allowedFields([$field])->parse();
})->throws(InvalidFieldValueException::class);

it('prefers exact fields then the longest prefix regardless of configuration order', function (
    bool $reverse,
): void {
    $fields = [
        AllowedField::string('meta.*', 'attributes.*')->keys('/^.+$/D'),
        AllowedField::integer('meta.stats.*', 'statistics.*')->keys('/^[a-z]+$/D'),
        AllowedField::boolean('meta.stats.active', 'enabled'),
    ];
    $parser = SearchParser::query('meta.stats.active:true meta.stats.count:12 meta.color:123')
        ->allowedFields($reverse ? array_reverse($fields) : $fields);
    $result = $parser->build();

    expect($result['left']['left']['field'])->toBe('enabled')
        ->and($result['left']['left']['value'])->toBeTrue()
        ->and($result['left']['right']['field'])->toBe('statistics.count')
        ->and($result['left']['right']['value'])->toBe(12)
        ->and($result['right']['field'])->toBe('attributes.color')
        ->and($result['right']['value'])->toBe('123');
})->with([false, true]);

it('rejects invalid keys without falling through to a broader pattern or allowAnyField', function (
    bool $allowAny,
): void {
    $parser = SearchParser::query('é meta.stats.private:12')->allowedFields([
        AllowedField::string('meta.*')->keys('/^.+$/D'),
        AllowedField::integer('meta.stats.*')->keys(fn (string $key): bool => $key === 'views'),
    ]);

    if ($allowAny) {
        $parser->allowAnyField();
    }

    try {
        $parser->parse();

        test()->fail('Expected an invalid key error.');
    } catch (InvalidFieldException $invalidFieldException) {
        expect($invalidFieldException->getField())->toBe('meta.stats.private')
            ->and($invalidFieldException->getPosition())->toBe(2)
            ->and($invalidFieldException->getReason())->toBe(ErrorReason::FieldNotAllowed)
            ->and($invalidFieldException->getParameters())->toBe([
                'field' => 'meta.stats.private',
                'allowed' => ['meta.*', 'meta.stats.*'],
            ])
            ->and($invalidFieldException->getMessage())->toContain('meta.*, meta.stats.*');
    }
})->with([false, true]);

it('requires a nonempty key and a matching prefix', function (string $name): void {
    SearchParser::query($name.':red')->allowedFields([
        AllowedField::string('meta.*')->keys('/^[a-z]+$/D'),
    ])->parse();
})->throws(InvalidFieldException::class)->with([
    'meta.', 'meta', 'metadata.color', 'other.color', 'meta.123', 'meta.Color',
]);

it('preserves Unicode prefixes and keys when mapping dynamic fields', function (): void {
    $result = SearchParser::query('méta.色:blue')->allowedFields([
        AllowedField::string('méta.*', '属性.*')->keys('/^色$/uD'),
    ])->build();

    expect($result['field'])->toBe('属性.色')->and($result['value'])->toBe('blue');
});

it('reports the typed name for pattern operator failures', function (): void {
    try {
        SearchParser::query('meta.color:*blue')->allowedFields([
            AllowedField::string('meta.*')->keys('/^[a-z]+$/D'),
        ])->parse();

        test()->fail('Expected a leading wildcard error.');
    } catch (InvalidFieldOperatorException $invalidFieldOperatorException) {
        expect($invalidFieldOperatorException->getField())->toBe('meta.color')
            ->and($invalidFieldOperatorException->getReason())->toBe(
                ErrorReason::LeadingWildcardNotAllowed,
            )
            ->and($invalidFieldOperatorException->getPosition())->toBe(11);
    }
});

it('requires key rules when configuring a pattern into a parser', function (bool $shorthand): void {
    SearchParser::query('')->allowedFields([
        $shorthand ? 'meta.*' : AllowedField::string('meta.*'),
    ]);
})->throws(InvalidArgumentException::class, 'requires a keys() rule')->with([false, true]);

it('rejects defaults on pattern fields including null defaults', function (mixed $value): void {
    AllowedField::string('meta.*')->keys('/^.+$/D')->default($value);
})->throws(InvalidArgumentException::class, 'cannot have defaults')->with([
    null,
    'blue',
    [['red', 'blue']],
]);

it('rejects an internal name without a pattern suffix', function (): void {
    AllowedField::string('meta.*', 'attributes');
})->throws(InvalidArgumentException::class);

it('rejects invalid regexes and key rules on exact fields', function (): void {
    expect(fn (): AllowedField => AllowedField::string('meta.*')->keys('/['))->toThrow(
        InvalidArgumentException::class,
    )
        ->and(fn (): AllowedField => AllowedField::string('meta.color')->keys('/^.+$/D'))->toThrow(
            InvalidArgumentException::class,
        );
});

it('rejects empty and wildcard keys even when any field is allowed', function (
    string $query,
): void {
    expect(fn (): mixed => SearchParser::query($query)->allowAnyField()->allowedFields([
        AllowedField::string('meta.*')->keys(static fn (string $key): bool => true),
    ])->build())->toThrow(InvalidFieldException::class);
})->with(['meta.:x', 'meta.*:x', 'meta.a*b:x']);

it('treats a key closure result other than true as a rejection', function (): void {
    $field = AllowedField::string('meta.*')->keys(static fn (string $key): int => 1);

    expect(fn (): mixed => SearchParser::query('meta.color:red')->allowedFields([$field])->build())
        ->toThrow(InvalidFieldException::class);
});

it('resolves a repeated pattern name to the last configured field', function (): void {
    $parser = SearchParser::query('meta.b:x')->allowedFields([
        AllowedField::string('meta.*')->keys('/^a$/'),
        AllowedField::string('meta.*')->keys('/^b$/'),
    ]);

    expect($parser->build()['field'])->toBe('meta.b');
});
