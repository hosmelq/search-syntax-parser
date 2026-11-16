<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\AST\Node\WildcardNode;
use HosmelQ\SearchSyntaxParser\Query\TextPattern;
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\TextMatch;

it('rejects invalid LIKE escape characters', function (string $escape): void {
    new TextPattern(['value'])->toLike($escape);
})->throws(InvalidArgumentException::class)->with(['', '!!', '%', '_', 'ab']);

it('rejects patterns without a literal segment', function (): void {
    new TextPattern([]);
})->throws(InvalidArgumentException::class);

it('rejects whole word matching instead of treating it as a substring', function (): void {
    TextPattern::forMatch('coffee', TextMatch::Words);
})->throws(InvalidArgumentException::class);

it('builds LIKE patterns for the effective text match mode', function (
    TextMatch $match,
    string $expected,
): void {
    expect(TextPattern::forMatch('a%_!', $match)->toLike())->toBe($expected);
})->with([
    [TextMatch::Exact, 'a!%!_!!'],
    [TextMatch::Prefix, 'a!%!_!!%'],
    [TextMatch::Contains, '%a!%!_!!%'],
]);

it('escapes SQL pattern metacharacters and the chosen escape once', function (): void {
    expect(new TextPattern(['50%!_off\\*'])->toLike())->toBe('50!%!!!_off\\*')
        ->and(new TextPattern(['50%#_!'])->toLike('#'))->toBe('50#%###_!')
        ->and(new TextPattern(['é%_é'])->toLike('é'))->toBe('ééé%é_éé');
});

it('uses wildcard segments without interpreting quoted literal asterisks', function (): void {
    $node = SearchParser::query('"50%_*"*')->parse();

    expect(TextPattern::fromWildcard($node)->toLike())->toBe('50!%!_*%')
        ->and(
            TextPattern::fromWildcard(new WildcardNode('name', '=', ['', 'a!_', 'b%', '']))
                ->toLike(),
        )->toBe(
            '%a!!!_%b!%%',
        );
});
