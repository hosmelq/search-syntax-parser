<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\Lexer\TokenType;

it('has correct enum case count', function (): void {
    $expectedCount = 21;
    expect(TokenType::names())->toHaveCount($expectedCount);
});

it('has expected string values for key token types', function (): void {
    expect(TokenType::Identifier->value)->toBe('identifier')
        ->and(TokenType::String->value)->toBe('string')
        ->and(TokenType::Number->value)->toBe('number')
        ->and(TokenType::And->value)->toBe('and')
        ->and(TokenType::Or->value)->toBe('or')
        ->and(TokenType::Not->value)->toBe('not');
});

it('round-trips getValue/fromValue for all cases', function (): void {
    foreach (TokenType::cases() as $case) {
        expect(TokenType::fromValue($case->getValue()))->toBe($case);
    }
});

it('returns null for invalid values', function (): void {
    expect(TokenType::fromValue(-1))->toBeNull()
        ->and(TokenType::fromValue(999))->toBeNull();
});

it('has unique getValue results for all cases', function (): void {
    $values = [];

    foreach (TokenType::cases() as $case) {
        $value = $case->getValue();
        expect($values)->not->toContain($value, 'Duplicate getValue() result: '.$value);
        $values[] = $value;
    }
});
