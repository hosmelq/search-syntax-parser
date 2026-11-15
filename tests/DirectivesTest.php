<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\Adapter\QueryAdapterInterface;
use HosmelQ\SearchSyntaxParser\AST\Node\NodeInterface;
use HosmelQ\SearchSyntaxParser\Exception\ErrorReason;
use HosmelQ\SearchSyntaxParser\Exception\InvalidFieldOperatorException;
use HosmelQ\SearchSyntaxParser\Exception\InvalidFieldValueException;
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;

it('extracts directives through nested AND groups while preserving filter semantics', function (): void {
    $parser = SearchParser::query('((mode:only AND coffee) AND (tea OR milk))')->allowedFields([
        AllowedField::in('mode', ['only', 'without'])->directive(),
    ]);

    expect($parser->build())->toBe(SearchParser::query('coffee AND (tea OR milk)')->build())
        ->and($parser->getDirectives())->toBe(['mode' => 'only'])
        ->and($parser->getTypedDirectives())->toBe(['mode' => 'only']);
});

it('rejects directives below OR or negation before resolving aliases even if filters vanish', function (
    string $query,
    int $position,
): void {
    $parser = SearchParser::query($query)->allowedFields([
        AllowedField::string('mode')->directive()->ignore('only')->alias(
            '@me',
            static function (): never {
                throw new LogicException('Placement must be checked before resolving aliases.');
            },
        ),
        AllowedField::string('status')->ignore('any'),
    ]);

    try {
        $parser->parse();

        test()->fail('Expected an invalid directive placement.');
    } catch (InvalidFieldOperatorException $invalidFieldOperatorException) {
        expect($invalidFieldOperatorException->getField())->toBe('mode')
            ->and($invalidFieldOperatorException->getReason())->toBe(
                ErrorReason::DirectiveNotTopLevel,
            )
            ->and($invalidFieldOperatorException->getPosition())->toBe($position)
            ->and($invalidFieldOperatorException->getParameters())->toBe(['field' => 'mode']);
    }
})->with([
    ['mode:@me OR coffee', 0],
    ['coffee OR mode:@me', 10],
    ['é OR (tea AND mode:@me)', 14],
    ['(mode:@me AND tea) OR coffee', 1],
    ['NOT mode:@me', 4],
    ['-mode:@me', 1],
    ['NOT (tea AND (mode:@me))', 14],
    ['NOT NOT mode:@me', 8],
    ['mode:only OR status:any', 0],
    ['status:any OR (mode:only)', 15],
    ['(mode:only OR status:any) AND tea', 1],
]);

it('requires a scalar equality directive before aliases or casting', function (
    string $clause,
    int $position,
): void {
    try {
        SearchParser::query($clause)->allowedFields([
            AllowedField::string('mode')->directive()->alias('@me', static function (): never {
                throw new LogicException('Clause restrictions must precede alias resolution.');
            }),
        ])->parse();

        test()->fail('Expected an invalid directive clause.');
    } catch (InvalidFieldOperatorException $invalidFieldOperatorException) {
        expect($invalidFieldOperatorException->getReason())->toBe(
            ErrorReason::DirectiveInvalidClause,
        )
            ->and($invalidFieldOperatorException->getField())->toBe('mode')
            ->and($invalidFieldOperatorException->getPosition())->toBe($position);
    }
})->with([
    ['mode:@me,only', 5],
    ['mode:[@me TO only]', 5],
    ['mode:1..3', 5],
    ['mode:only*', 5],
    ['mode:"only"*', 5],
    ['mode:*', 5],
    ['mode:!=@me', 5],
    ['mode:>@me', 5],
    ['mode>=@me', 4],
    ['mode:<@me', 5],
    ['mode<=@me', 4],
]);

it('rejects repeated directives including an ignored first mention', function (): void {
    try {
        SearchParser::query('é mode:any AND (mode:only)')->allowedFields([
            AllowedField::string('mode')->directive()->ignore('any')->default('without'),
        ])->parse();

        test()->fail('Expected a duplicate directive.');
    } catch (InvalidFieldOperatorException $invalidFieldOperatorException) {
        expect($invalidFieldOperatorException->getReason())->toBe(ErrorReason::DuplicateDirective)
            ->and($invalidFieldOperatorException->getField())->toBe('mode')
            ->and($invalidFieldOperatorException->getPosition())->toBe(16);
    }
});

it('casts directives, resolves aliases once, and exposes defaults separately', function (): void {
    $calls = 0;
    $parser = SearchParser::query('limit:@me mode:ONLY')->allowedFields([
        AllowedField::in('mode', ['only', 'without'])->directive(),
        AllowedField::integer('limit')->directive()->min(1)->alias(
            '@me',
            function () use (&$calls): int {
                $calls++;

                return 25;
            },
        ),
        AllowedField::boolean('archived')->directive()->default('false'),
    ]);

    expect($parser->build())->toBeNull()
        ->and($parser->getDirectives())->toBe([
            'mode' => 'only',
            'limit' => 25,
            'archived' => false,
        ])
        ->and($parser->getTypedDirectives())->toBe(['mode' => 'only', 'limit' => 25])
        ->and($calls)->toBe(1);
});

it('removes ignored directives after casting and aliases and suppresses their defaults', function (
    string $query,
): void {
    $parser = SearchParser::query($query)->allowedFields([
        AllowedField::integer('limit')
            ->directive()
            ->min(1)
            ->ignore(0)
            ->alias('any', 0)
            ->default(20),
    ]);

    expect($parser->build('string'))->toBeNull()
        ->and($parser->getDirectives())->toBeEmpty()
        ->and($parser->getTypedDirectives())->toBeEmpty();
})->with(['limit:0', 'limit:any']);

it('applies directive defaults without adding filter nodes', function (): void {
    $parser = SearchParser::query('')->allowedFields([
        AllowedField::in('mode', ['only', 'without'])
            ->directive()
            ->alias('normal', 'without')
            ->default('normal'),
    ]);

    expect($parser->parse())->toBeNull()
        ->and($parser->getDirectives())->toBe(['mode' => 'without'])
        ->and($parser->getTypedDirectives())->toBeEmpty()
        ->and($parser->build('string'))->toBeNull();
});

it('validates directives at their value position', function (): void {
    try {
        SearchParser::query('é limit:zero')->allowedFields([
            AllowedField::integer('limit')->directive()->min(1),
        ])->parse();

        test()->fail('Expected an invalid directive value.');
    } catch (InvalidFieldValueException $invalidFieldValueException) {
        expect($invalidFieldValueException->getField())
            ->toBe('limit')
            ->and($invalidFieldValueException->getPosition())
            ->toBe(8);
    }
});

it('keeps explicit usage restrictions ahead of directive aliases', function (): void {
    SearchParser::query('mode:@me')->allowedFields([
        AllowedField::string('mode')->directive()->operators('!=')->alias(
            '@me',
            static function (): never {
                throw new LogicException('Disallowed operators must not resolve aliases.');
            },
        ),
    ])->parse();
})->throws(InvalidFieldOperatorException::class, "Operator '='");

it('allows literal special characters and negative scalar directive values', function (): void {
    $parser = SearchParser::query('mode:"a*,b..c" offset:-3')->allowedFields([
        AllowedField::string('mode')->directive(),
        AllowedField::integer('offset')->directive(),
    ]);

    $parser->parse();

    expect($parser->getDirectives())->toBe(['mode' => 'a*,b..c', 'offset' => -3]);
});

it('rejects collection and pattern directive declarations and non-scalar defaults in either order', function (
    Closure $configure,
): void {
    $configure();
})->throws(InvalidArgumentException::class)->with([
    fn (): AllowedField => AllowedField::array('mode')->directive(),
    fn (): AllowedField => AllowedField::string('meta.*')->keys('/.*/')->directive(),
    fn (): AllowedField => AllowedField::string('mode')->directive()->default(null),
    fn (): AllowedField => AllowedField::string('mode')->directive()->default(['only']),
    fn (): AllowedField => AllowedField::string('mode')->default(null)->directive(),
    fn (): AllowedField => AllowedField::string('mode')->default(['only'])->directive(),
]);

it('rejects invalid directive defaults as configuration errors', function (): void {
    SearchParser::query('')->allowedFields([
        AllowedField::integer('limit')->directive()->min(1)->default('bad'),
    ])->parse();
})->throws(InvalidArgumentException::class, "Invalid default for field 'limit'.");

it('does not invoke custom adapters for a directive-only query', function (): void {
    $parser = SearchParser::query('mode:only')->allowedFields([
        AllowedField::string('mode')->directive(),
    ]);

    $parser->extend(
        'unused',
        static fn (): QueryAdapterInterface => new class () implements QueryAdapterInterface {
            #[Override]
            public function build(NodeInterface $ast): never
            {
                throw new LogicException('A null filter tree must not call custom adapters.');
            }
        },
    );

    expect($parser->build('unused'))->toBeNull()
        ->and($parser->getDirectives())->toBe(['mode' => 'only'])
        ->and($parser->build('string'))->toBe('mode:only');
});

it('round trips directives whose public names become numeric PHP array keys', function (): void {
    $field = AllowedField::integer('123')->directive();
    $parser = SearchParser::query('123:7')->allowedFields([$field]);
    $text = $parser->build('string');
    $reparsed = SearchParser::query($text)->allowedFields([$field]);

    expect($text)->toBe('123:7')
        ->and($reparsed->parse())->toBeNull()
        ->and($reparsed->getDirectives())->toBe([123 => 7]);
});

it('trusts directive defaults despite ignored values and usage restrictions', function (): void {
    $parser = SearchParser::query('')->allowedFields([
        AllowedField::string('mode')
            ->directive()
            ->operators('!=')
            ->ignore('without')
            ->default('without'),
    ]);

    expect($parser->parse())
        ->toBeNull()
        ->and($parser->getDirectives())
        ->toBe(['mode' => 'without']);
});
