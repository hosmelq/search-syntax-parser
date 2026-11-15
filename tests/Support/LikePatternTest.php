<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\AST\Node\WildcardNode;
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Support\LikePattern;
use HosmelQ\SearchSyntaxParser\Validation\TextMatch;

it('escapes SQL pattern metacharacters and the chosen escape once', function (): void {
    expect(LikePattern::escape('50%!_off\\*'))->toBe('50!%!!!_off\\*')
        ->and(LikePattern::escape('50%#_!', '#'))->toBe('50#%###_!')
        ->and(LikePattern::escape('é%_é', 'é'))->toBe('ééé%é_éé');
});

it('uses wildcard segments without interpreting quoted literal asterisks', function (): void {
    $node = SearchParser::query('"50%_*"*')->parse();

    expect(LikePattern::fromWildcard($node))->toBe('50!%!_*%')
        ->and(
            LikePattern::fromWildcard(new WildcardNode('name', '=', ['', 'a!_', 'b%', ''])),
        )->toBe(
            '%a!!!_%b!%%',
        );
});

it('builds LIKE patterns for the effective text match mode', function (
    TextMatch $match,
    string $expected,
): void {
    expect(LikePattern::forMatch('a%_!', $match))->toBe($expected);
})->with([
    [TextMatch::Exact, 'a!%!_!!'],
    [TextMatch::Prefix, 'a!%!_!!%'],
    [TextMatch::Contains, '%a!%!_!!%'],
]);

it('rejects whole word matching instead of treating it as a substring', function (): void {
    LikePattern::forMatch('coffee', TextMatch::Words);
})->throws(InvalidArgumentException::class);

it('rejects invalid escapes from every entry point', function (string $escape): void {
    expect([
        fn (): string => LikePattern::escape('value', $escape),
        fn (): string => LikePattern::fromWildcard(new WildcardNode(null, '=', []), $escape),
        fn (): string => LikePattern::forMatch('value', TextMatch::Exact, $escape),
    ])->each->toThrow(InvalidArgumentException::class);
})->with(['', '!!', '%', '_', 'ab']);
