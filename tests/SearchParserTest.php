<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\Adapter\ArrayAdapter;
use HosmelQ\SearchSyntaxParser\Adapter\QueryAdapterInterface;
use HosmelQ\SearchSyntaxParser\AST\Node\NodeInterface;
use HosmelQ\SearchSyntaxParser\AST\Node\TermNode;
use HosmelQ\SearchSyntaxParser\Configuration\SearchConfiguration;
use HosmelQ\SearchSyntaxParser\SearchParser;

it('builds query using default adapter when none specified', function (): void {
    $parser = new SearchParser();

    $result = $parser->build('test');

    expect($result)->toBe([
        'fields' => [],
        'type' => 'term',
        'value' => 'test',
    ]);
});

it('builds query using specified adapter', function (): void {
    $parser = new SearchParser();

    $result = $parser->build('test', 'array');

    expect($result)->toBe([
        'fields' => [],
        'type' => 'term',
        'value' => 'test',
    ]);
});

it('caches adapter instances for subsequent calls', function (): void {
    $parser = new SearchParser();
    $customAdapter = new ArrayAdapter();

    $parser->extend('custom', fn (): ArrayAdapter => $customAdapter);

    $first = $parser->adapter('array');
    $second = $parser->adapter('array');
    $custom = $parser->adapter('custom');

    expect($first)->toBe($second)
        ->and($custom)->toBe($customAdapter)
        ->and($first)->not->toBe($custom);
});

it('can extend with custom adapter', function (): void {
    $parser = new SearchParser();
    $customAdapter = new ArrayAdapter();

    $parser->extend('custom', fn (): ArrayAdapter => $customAdapter);

    $result = $parser->adapter('custom');

    expect($result)->toBe($customAdapter);
});

it('can extend with multiple adapters', function (): void {
    $parser = new SearchParser();
    $customAdapter = new ArrayAdapter();

    $testAdapter = new class () implements QueryAdapterInterface {
        public function build(NodeInterface $ast): mixed
        {
            return ['test' => true];
        }
    };

    $parser->extend('custom', fn (): ArrayAdapter => $customAdapter);
    $parser->extend('test', fn (): object => $testAdapter);

    expect($parser)
        ->adapter('custom')->toBe($customAdapter)
        ->adapter('test')->toBe($testAdapter);
});

it('converts AST to array using ArrayAdapter', function (): void {
    $parser = new SearchParser();

    $result = $parser->build('title:shoes AND price:>50', 'array');

    expect($result)->toBeArray()
        ->left->type->toBe('comparison')
        ->operator->toBe('AND')
        ->right->operator->toBe('>')
        ->right->type->toBe('comparison')
        ->type->toBe('binary');
});

it('creates default ArrayAdapter when not extended', function (): void {
    $parser = new SearchParser();

    $adapter = $parser->adapter();

    expect($adapter)->toBeInstanceOf(ArrayAdapter::class);
});

it('creates parser using factory method', function (): void {
    $parser = SearchParser::create(new SearchConfiguration());

    expect($parser)->toBeInstanceOf(SearchParser::class);
});

it('creates parser with custom configuration', function (): void {
    $parser = new SearchParser(new SearchConfiguration());

    expect($parser)->toBeInstanceOf(SearchParser::class);
});

it('creates parser with default configuration', function (): void {
    $parser = new SearchParser();

    expect($parser)->toBeInstanceOf(SearchParser::class)
        ->getDefaultAdapter()->toBe('array');
});

it('handles complex boolean expressions with correct precedence', function (): void {
    $parser = new SearchParser();

    $result = $parser->build('(title:shoes OR title:boots) AND price:>50', 'array');

    expect($result)
        ->toBeArray()
        ->left->operator->toBe('OR')
        ->left->type->toBe('binary')
        ->operator->toBe('AND')
        ->type->toBe('binary');
});

it('handles exists queries with ArrayAdapter', function (): void {
    $parser = new SearchParser();

    $result = $parser->build('published_at:* AND NOT discount:*', 'array');

    expect($result)
        ->toBeArray()
        ->left->field->toBe('published_at')
        ->left->type->toBe('exists')
        ->operator->toBe('AND')
        ->right->operand->field->toBe('discount')
        ->right->operand->type->toBe('exists')
        ->right->operator->toBe('NOT')
        ->right->type->toBe('unary')
        ->type->toBe('binary');
});

it('parses query string into AST', function (): void {
    $parser = new SearchParser();
    $ast = $parser->parse('test');

    expect($ast)
        ->toBeInstanceOf(TermNode::class)
        ->getValue()->toBe('test');
});

it('parses simple field search into Comparison node', function (): void {
    $parser = new SearchParser();
    $ast = $parser->parse('title:shoes');

    expect($ast->getType())->toBe('Comparison');
});

it('resolves adapter creators using studly names', function (): void {
    $parser = new class () extends SearchParser {
        protected function createFooBarAdapter(): ArrayAdapter
        {
            return new ArrayAdapter();
        }
    };

    $adapter = $parser->adapter('foo-bar');

    expect($adapter)->toBeInstanceOf(ArrayAdapter::class);
});

it('returns correct default adapter', function (): void {
    $parser = new SearchParser();

    expect($parser->getDefaultAdapter())->toBe('array');
});

it('throws exception when building with unsupported adapter', function (): void {
    $parser = new SearchParser();

    $parser->build('test', 'nonexistent');
})->throws(InvalidArgumentException::class, "Adapter 'nonexistent' not supported.");

it('throws exception when getting unsupported adapter', function (): void {
    $parser = new SearchParser();

    $parser->adapter('missing');
})->throws(InvalidArgumentException::class, "Adapter 'missing' not supported.");
