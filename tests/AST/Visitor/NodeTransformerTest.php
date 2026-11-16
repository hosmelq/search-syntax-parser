<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\Adapter\ArrayAdapter;
use HosmelQ\SearchSyntaxParser\Adapter\StringAdapter;
use HosmelQ\SearchSyntaxParser\AST\Node\NodeInterface;
use HosmelQ\SearchSyntaxParser\AST\Node\TermNode;
use HosmelQ\SearchSyntaxParser\AST\Visitor\NodeTransformer;
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\Field;

it('allows one visit override to rewrite leaves anywhere in a tree', function (): void {
    $transformer = new class () extends NodeTransformer {
        #[Override]
        public function visitTerm(TermNode $node): NodeInterface
        {
            return new TermNode(
                mb_strtoupper($node->getValue()),
                $node->isQuoted(),
                $node->getSpan(),
            );
        }
    };
    $node = SearchParser::query('coffee OR NOT ("cold brew" AND tea)')->parse();
    $transformed = $transformer->transform($node);

    expect(new StringAdapter()->build($transformed))->toBe('COFFEE OR NOT ("COLD BREW" AND TEA)')
        ->and($transformed->getSpan())->toBe($node->getSpan())
        ->and($transformed->getRight()->getOperand()->getLeft()->getSpan())
        ->toBe($node->getRight()->getOperand()->getLeft()->getSpan());
});

it('preserves the complete tree including spans quoted terms and default origins', function (): void {
    $node = SearchParser::query(
        'NOT (price:>10 OR status:open,closed) title:[a TO z] tag:* title:mu* "coffee"',
    )
        ->allowAnyField()->allowedFields([
            Field::string('region')->default('north'),
            Field::array('labels')->default(['one', 'two']),
            Field::string('deleted')->default(null),
        ])->parse();
    $transformed = new NodeTransformer()->transform($node);

    expect($transformed)->toEqual($node)
        ->and(new ArrayAdapter()->build($transformed))->toBe(new ArrayAdapter()->build($node))
        ->and(new StringAdapter()->build($transformed))->toBe(new StringAdapter()->build($node))
        ->and($transformed->getRight()->isDefault())->toBeTrue()
        ->and($transformed->getLeft()->getRight()->isDefault())->toBeTrue()
        ->and($transformed->getLeft()->getLeft()->getRight()->isDefault())->toBeTrue();
});
