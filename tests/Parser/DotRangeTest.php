<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\AST\Node\ComparisonNode;
use HosmelQ\SearchSyntaxParser\SearchParser;

it('keeps ellipses, repeated dots and paths as literal text', function (
    string $query,
    string $value,
): void {
    $node = SearchParser::query($query)->allowAnyField()->parse();

    expect($node)->toBeInstanceOf(ComparisonNode::class)->and($node->getValue())->toBe($value);
})->with([
    'trailing ellipsis' => ['title:wait...', 'wait...'],
    'inner ellipsis' => ['title:a...b', 'a...b'],
    'decimal ellipsis' => ['v:1...5', '1...5'],
    'two separators' => ['x:a..b..c', 'a..b..c'],
    'relative path' => ['path:src/../lib', 'src/../lib'],
    'dot path' => ['path:./../x', './../x'],
]);
