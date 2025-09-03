<?php

declare(strict_types=1);

use Doctrine\Common\Lexer\Token;
use HosmelQ\SearchSyntaxParser\Lexer\SearchLexer;
use HosmelQ\SearchSyntaxParser\Lexer\TokenType;

function lexTokens(string $input): array
{
    $lexer = new SearchLexer();

    $lexer->setInput($input);
    $lexer->moveNext();

    $tokens = [];

    while (($type = $lexer->getLookaheadType()) instanceof TokenType) {
        $value = $lexer->lookahead instanceof Token ? $lexer->lookahead->value : null;
        $tokens[] = [$type, $value];

        $lexer->moveNext();
    }

    return $tokens;
}

it('tokenizes identifiers', function (): void {
    $tokens = lexTokens('title category_1 1field nested.path');

    expect($tokens)
        ->toHaveCount(4)
        ->{0}->{0}->toBe(TokenType::Identifier)
        ->{0}->{1}->toBe('title')
        ->{1}->{0}->toBe(TokenType::Identifier)
        ->{1}->{1}->toBe('category_1')
        ->{2}->{0}->toBe(TokenType::Identifier)
        ->{2}->{1}->toBe('1field')
        ->{3}->{0}->toBe(TokenType::Identifier)
        ->{3}->{1}->toBe('nested.path');
});

it('tokenizes numbers and dates', function (): void {
    $tokens = lexTokens('10 10.5 2025-01-01 2025-01-01T12:30:45Z');

    expect($tokens)
        ->toHaveCount(4)
        ->{0}->{0}->toBe(TokenType::Number)
        ->{0}->{1}->toBe('10')
        ->{1}->{0}->toBe(TokenType::Number)
        ->{1}->{1}->toBe('10.5')
        ->{2}->{0}->toBe(TokenType::Date)
        ->{2}->{1}->toBe('2025-01-01')
        ->{3}->{0}->toBe(TokenType::Date)
        ->{3}->{1}->toBe('2025-01-01T12:30:45Z');
});

it('tokenizes strings and unescapes quotes', function (): void {
    $input = "\"hello\\\"world\" \"back\\\\slash\" 'single\\'quote'";
    $tokens = lexTokens($input);

    expect($tokens)
        ->toHaveCount(3)
        ->{0}->{0}->toBe(TokenType::String)
        ->{0}->{1}->toBe('hello"world')
        ->{1}->{0}->toBe(TokenType::String)
        ->{1}->{1}->toBe('back\slash')
        ->{2}->{0}->toBe(TokenType::String)
        ->{2}->{1}->toBe("single'quote");
});

it('tokenizes operators and punctuation', function (): void {
    $tokens = lexTokens('>= <= != > < : * , - ( ) [ ]');

    $types = array_map(fn (array $token) => $token[0], $tokens);

    expect($types)->toBe([
        TokenType::GreaterEqual,
        TokenType::LessEqual,
        TokenType::NotEqual,
        TokenType::Greater,
        TokenType::Less,
        TokenType::Colon,
        TokenType::Wildcard,
        TokenType::Comma,
        TokenType::Minus,
        TokenType::OpenParenthesis,
        TokenType::CloseParenthesis,
        TokenType::OpenBracket,
        TokenType::CloseBracket,
    ]);
});

it('tokenizes logical keywords and range operator', function (): void {
    $tokens = lexTokens('AND Or not TO');

    $types = array_map(fn (array $token) => $token[0], $tokens);

    expect($types)->toBe([
        TokenType::And,
        TokenType::Or,
        TokenType::Not,
        TokenType::To,
    ]);
});

it('returns null lookahead when input is consumed', function (): void {
    $lexer = new SearchLexer();

    $lexer->setInput('title');
    $lexer->moveNext();

    expect($lexer->getLookaheadType())->toBe(TokenType::Identifier);

    $lexer->moveNext();

    expect($lexer->getLookaheadType())->toBeNull();
});
