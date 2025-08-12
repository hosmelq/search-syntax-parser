<?php

declare(strict_types=1);

use Doctrine\Common\Lexer\Token;
use HosmelQ\SearchSyntaxParser\Lexer\SearchLexer;
use HosmelQ\SearchSyntaxParser\Lexer\TokenType;

function lex(string $input): array
{
    $lexer = new SearchLexer();

    $lexer->setInput($input);
    $lexer->moveNext();

    $tokens = [];

    while ($lexer->lookahead instanceof Token) {
        $lexer->moveNext();

        if ($lexer->token instanceof Token) {
            $type = $lexer->token->type;

            if ($type instanceof TokenType) {
                $tokens[] = [$type->name, $lexer->token->value];
            }
        }
    }

    return $tokens;
}

it('tokenizes identifiers and dots', function (): void {
    $tokens = lex('user.name:john');

    expect($tokens)->toBe([
        ['Identifier', 'user.name'],
        ['Colon', ':'],
        ['Identifier', 'john'],
    ]);
});

it('tokenizes numbers', function (): void {
    $tokens = lex('123 45.67');

    expect($tokens)->toBe([
        ['Number', '123'],
        ['Number', '45.67'],
    ]);
});

it('tokenizes dates', function (): void {
    $tokens = lex('2024-01-01 2024-01-01T12:34:56Z');

    expect($tokens)->toBe([
        ['Date', '2024-01-01'],
        ['Date', '2024-01-01T12:34:56Z'],
    ]);
});

it('tokenizes strings with escaping', function (): void {
    $tokens = lex('"He said \"hi\""');

    expect($tokens)->toBe([
        ['String', 'He said "hi"'],
    ]);

    $tokens = lex("'It\\'s ok'");

    expect($tokens)->toBe([
        ['String', "It's ok"],
    ]);
});

it('tokenizes operators with longest match', function (): void {
    $tokens = lex('>= <= != > < :');

    expect($tokens)->toBe([
        ['GreaterEqual', '>='],
        ['LessEqual', '<='],
        ['NotEqual', '!='],
        ['Greater', '>'],
        ['Less', '<'],
        ['Colon', ':'],
    ]);
});

it('tokenizes brackets, parentheses, and minus', function (): void {
    $tokens = lex('( ) [ ] -');

    expect($tokens)->toBe([
        ['OpenParenthesis', '('],
        ['CloseParenthesis', ')'],
        ['OpenBracket', '['],
        ['CloseBracket', ']'],
        ['Minus', '-'],
    ]);
});

it('tokenizes wildcard', function (): void {
    $tokens = lex('*');

    expect($tokens)->toBe([
        ['Wildcard', '*'],
    ]);
});

it('tokenizes boolean keywords case-insensitively', function (): void {
    $tokens = lex('AND or Not');

    expect($tokens)->toBe([
        ['And', 'AND'],
        ['Or', 'or'],
        ['Not', 'Not'],
    ]);
});

it('tokenizes TO keyword', function (): void {
    $tokens = lex('[2024-01-01 TO 2024-12-31]');

    expect($tokens)->toBe([
        ['OpenBracket', '['],
        ['Date', '2024-01-01'],
        ['To', 'TO'],
        ['Date', '2024-12-31'],
        ['CloseBracket', ']'],
    ]);
});

it('handles empty input correctly', function (): void {
    $tokens = lex('');

    expect($tokens)->toBe([]);
});

it('handles whitespace-only input', function (): void {
    $tokens = lex('   ');

    expect($tokens)->toBe([]);
});

it('handles complex escaped strings', function (): void {
    $tokens = lex('"Path: C:\\\\Program Files\\\\App"');

    expect($tokens)->toBe([
        ['String', 'Path: C:\\Program Files\\App'],
    ]);
});

it('tokenizes mixed complex query', function (): void {
    $tokens = lex('(status:active OR status:pending) AND price:>=100');

    expect($tokens)->toBe([
        ['OpenParenthesis', '('],
        ['Identifier', 'status'],
        ['Colon', ':'],
        ['Identifier', 'active'],
        ['Or', 'OR'],
        ['Identifier', 'status'],
        ['Colon', ':'],
        ['Identifier', 'pending'],
        ['CloseParenthesis', ')'],
        ['And', 'AND'],
        ['Identifier', 'price'],
        ['Colon', ':'],
        ['GreaterEqual', '>='],
        ['Number', '100'],
    ]);
});
