<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\AST\Node\BinaryOperatorNode;
use HosmelQ\SearchSyntaxParser\AST\Node\ComparisonNode;
use HosmelQ\SearchSyntaxParser\AST\Node\ExistsNode;
use HosmelQ\SearchSyntaxParser\AST\Node\RangeNode;
use HosmelQ\SearchSyntaxParser\AST\Node\TermNode;
use HosmelQ\SearchSyntaxParser\AST\Node\UnaryOperatorNode;
use HosmelQ\SearchSyntaxParser\Configuration\SearchConfiguration;
use HosmelQ\SearchSyntaxParser\Exception\ParseException;
use HosmelQ\SearchSyntaxParser\Parser\Parser;

it('casts numeric values', function (): void {
    $parser = new Parser(new SearchConfiguration());

    $ast1 = $parser->parse('price:>=123');
    $ast2 = $parser->parse('price:<45.67');

    expect($ast1)->getValue()->toBe(123)
        ->and($ast2)->getValue()->toBe(45.67);
});

it('enforces max_conditions limit', function (): void {
    $parser = new Parser((new SearchConfiguration())->setLimit('max_conditions', 2));

    $parser->parse('a b c');
})->throws(ParseException::class, 'Maximum number of conditions (2) exceeded.');

it('enforces max_nesting_depth limit', function (): void {
    $parser = new Parser((new SearchConfiguration())->setLimit('max_nesting_depth', 1));

    $parser->parse('((title:shoes))');
})->throws(ParseException::class, 'Maximum nesting depth of 1 exceeded.');

it('enforces max_query_length limit', function (): void {
    $parser = new Parser((new SearchConfiguration())->setLimit('max_query_length', 5));

    $parser->parse('123456');
})->throws(ParseException::class, 'Query exceeds maximum length of 5 characters.');

it('handles complex boolean expressions correctly', function (): void {
    $parser = new Parser(new SearchConfiguration());

    $ast = $parser->parse('NOT (status:active OR status:pending) AND price:>=100');

    expect($ast)
        ->toBeInstanceOf(BinaryOperatorNode::class)
        ->getOperator()->toBe('AND')
        ->and($ast->getLeft())
        ->toBeInstanceOf(UnaryOperatorNode::class)
        ->getOperator()->toBe('NOT')
        ->and($ast->getRight())
        ->toBeInstanceOf(ComparisonNode::class);
});

it('handles wildcard values', function (): void {
    $parser = new Parser(new SearchConfiguration());

    $ast = $parser->parse('title:Nike*');

    expect($ast)
        ->toBeInstanceOf(ComparisonNode::class)
        ->getValue()->toBe('Nike*');
});

it('parses implicit AND', function (): void {
    $parser = new Parser(new SearchConfiguration());

    $ast = $parser->parse('title:shoes price:>50');

    expect($ast)
        ->toBeInstanceOf(BinaryOperatorNode::class)
        ->and($ast->getLeft())->toBeInstanceOf(ComparisonNode::class)
        ->and($ast->getOperator())->toBe('AND')
        ->and($ast->getRight())->toBeInstanceOf(ComparisonNode::class);
});

it('parses NOT and minus negation', function (): void {
    $parser = new Parser(new SearchConfiguration());

    $ast1 = $parser->parse('NOT title:shoes');
    $ast2 = $parser->parse('-title:shoes');

    expect($ast1)->toBeInstanceOf(UnaryOperatorNode::class)
        ->getOperator()->toBe('NOT')
        ->and($ast2)->toBeInstanceOf(UnaryOperatorNode::class)
        ->getOperator()->toBe('NOT');
});

it('parses OR precedence lower than AND', function (): void {
    $parser = new Parser(new SearchConfiguration());

    $ast = $parser->parse('a AND b OR c AND d');

    expect($ast)
        ->toBeInstanceOf(BinaryOperatorNode::class)
        ->and($ast->getLeft())->toBeInstanceOf(BinaryOperatorNode::class)
        ->and($ast->getLeft()->getOperator())->toBe('AND')
        ->and($ast->getOperator())->toBe('OR')
        ->and($ast->getRight())->toBeInstanceOf(BinaryOperatorNode::class)
        ->and($ast->getRight()->getOperator())->toBe('AND');
});

it('parses parentheses grouping', function (): void {
    $parser = new Parser(new SearchConfiguration());

    $ast = $parser->parse('(title:shoes OR title:boots) AND price:>50');

    expect($ast)
        ->toBeInstanceOf(BinaryOperatorNode::class)
        ->and($ast->getLeft())->toBeInstanceOf(BinaryOperatorNode::class)
        ->and($ast->getLeft()->getOperator())->toBe('OR')
        ->and($ast->getOperator())->toBe('AND')
        ->and($ast->getRight())->toBeInstanceOf(ComparisonNode::class)
        ->and($ast->getRight()->getField())->toBe('price');
});

it('parses exists queries', function (): void {
    $parser = new Parser(new SearchConfiguration());

    $ast = $parser->parse('published_at:*');

    expect($ast)
        ->toBeInstanceOf(ExistsNode::class)
        ->getField()->toBe('published_at');
});

it('parses multiple exists queries with boolean operators', function (): void {
    $parser = new Parser(new SearchConfiguration());

    $ast = $parser->parse('published_at:* AND discount:*');

    expect($ast)
        ->toBeInstanceOf(BinaryOperatorNode::class)
        ->getLeft()->toBeInstanceOf(ExistsNode::class)
        ->getLeft()->getField()->toBe('published_at')
        ->getOperator()->toBe('AND')
        ->getRight()->toBeInstanceOf(ExistsNode::class)
        ->getRight()->getField()->toBe('discount');
});

it('parses NOT exists queries', function (): void {
    $parser = new Parser(new SearchConfiguration());

    $ast1 = $parser->parse('NOT published_at:*');
    $ast2 = $parser->parse('-discount:*');

    expect($ast1)
        ->toBeInstanceOf(UnaryOperatorNode::class)
        ->getOperand()->toBeInstanceOf(ExistsNode::class)
        ->getOperand()->getField()->toBe('published_at')
        ->getOperator()->toBe('NOT')
        ->and($ast2)
        ->toBeInstanceOf(UnaryOperatorNode::class)
        ->getOperand()->toBeInstanceOf(ExistsNode::class)
        ->getOperand()->getField()->toBe('discount')
        ->getOperator()->toBe('NOT');
});

it('parses field comparisons with colon equals default', function (): void {
    $parser = new Parser(new SearchConfiguration());

    $ast = $parser->parse('title:shoes');

    expect($ast)
        ->toBeInstanceOf(ComparisonNode::class)
        ->getField()->toBe('title')
        ->getOperator()->toBe('=')
        ->getValue()->toBe('shoes');
});

it('parses field comparisons with colon plus explicit operator', function (): void {
    $parser = new Parser(new SearchConfiguration());

    $ast = $parser->parse('price:>50');

    expect($ast)
        ->toBeInstanceOf(ComparisonNode::class)
        ->getField()->toBe('price')
        ->getOperator()->toBe('>')
        ->getValue()->toBe(50);
});

it('parses field comparisons with direct operator', function (): void {
    $parser = new Parser(new SearchConfiguration());

    $ast = $parser->parse('price>50');

    expect($ast)
        ->toBeInstanceOf(ComparisonNode::class)
        ->getField()->toBe('price')
        ->getOperator()->toBe('>')
        ->getValue()->toBe(50);
});

it('parses range syntax', function (): void {
    $parser = new Parser(new SearchConfiguration());

    $ast = $parser->parse('created_at:[2024-01-01 TO 2024-12-31]');

    expect($ast)
        ->toBeInstanceOf(RangeNode::class)
        ->getField()->toBe('created_at')
        ->getFrom()->toBe('2024-01-01')
        ->getTo()->toBe('2024-12-31');
});

it('parses range with case-insensitive TO', function (): void {
    $parser = new Parser(new SearchConfiguration());

    $ast = $parser->parse('price:[10 to 20]');

    expect($ast)
        ->toBeInstanceOf(RangeNode::class)
        ->getField()->toBe('price')
        ->getFrom()->toBe(10)
        ->getTo()->toBe(20);
});

it('parses term nodes', function (): void {
    $parser = new Parser((new SearchConfiguration())
        ->setSearchableFields(['title']));

    $ast1 = $parser->parse('shoes');
    $ast2 = $parser->parse('"running shoes"');

    expect($ast1)
        ->toBeInstanceOf(TermNode::class)
        ->getValue()->toBe('shoes')
        ->and($ast2)->toBeInstanceOf(TermNode::class)
        ->getValue()->toBe('running shoes');
});

it('preserves prefix query functionality', function (): void {
    $parser = new Parser((new SearchConfiguration())->setAllowedFields(['title']));

    $ast = $parser->parse('title:Nike*');

    expect($ast)
        ->toBeInstanceOf(ComparisonNode::class)
        ->getField()->toBe('title')
        ->getOperator()->toBe('=')
        ->getValue()->toBe('Nike*');
});

it('throws exception for disallowed fields', function (): void {
    $parser = new Parser((new SearchConfiguration())->setAllowedFields(['title']));

    $parser->parse('invalid:value');
})->throws(ParseException::class, "Field 'invalid' is not allowed.");

it('throws exception for empty input', function (): void {
    $parser = new Parser(new SearchConfiguration());

    $parser->parse('');
})->throws(ParseException::class, 'Empty search query.');

it('throws exception for missing closing parenthesis', function (): void {
    $parser = new Parser(new SearchConfiguration());

    $parser->parse('(title:shoes');
})->throws(ParseException::class, 'Unexpected token, expected CloseParenthesis but got NULL.');

it('throws exception for missing value after operator', function (): void {
    $parser = new Parser(new SearchConfiguration());

    $parser->parse('title:');
})->throws(ParseException::class, 'Expected value.');

it('throws exception for trailing invalid tokens', function (): void {
    $parser = new Parser(new SearchConfiguration());

    $parser->parse('title:shoes :');
})->throws(ParseException::class, 'Unexpected token after query: :.');

it('throws exception for wildcard with direct greater than operator', function (): void {
    $parser = new Parser(new SearchConfiguration());

    $parser->parse('published_at>*');
})->throws(ParseException::class, "Wildcard is only supported with ':' for exists queries; use NOT published_at:* for negation.");

it('throws exception for wildcard with direct not equals operator', function (): void {
    $parser = new Parser(new SearchConfiguration());

    $parser->parse('published_at!=*');
})->throws(ParseException::class, "Wildcard is only supported with ':' for exists queries; use NOT published_at:* for negation.");

it('throws exception for wildcard with colon and operator', function (): void {
    $parser = new Parser(new SearchConfiguration());

    $parser->parse('field:>*');
})->throws(ParseException::class, "Wildcard is only supported with ':' for exists queries; use NOT field:* for negation.");

it('throws exception for invalid field value with colon syntax', function (): void {
    $config = new SearchConfiguration();

    $config->addFieldValidator('price', fn ($value): bool => is_numeric($value) && $value > 0);

    $parser = new Parser($config);

    $parser->parse('price:invalid');
})->throws(ParseException::class, "Invalid value for field 'price'");

it('throws exception for invalid field value with direct operator', function (): void {
    $config = new SearchConfiguration();

    $config->addFieldValidator('price', fn ($value): bool => is_numeric($value) && $value > 0);

    $parser = new Parser($config);

    $parser->parse('price>invalid');
})->throws(ParseException::class, "Invalid value for field 'price'");

it('throws exception for unexpected end of input in expression', function (): void {
    $parser = new Parser(new SearchConfiguration());

    $parser->parse('title:shoes AND');
})->throws(ParseException::class, 'Unexpected end of input.');
