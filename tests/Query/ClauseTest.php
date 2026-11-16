<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\Query\Clause;
use HosmelQ\SearchSyntaxParser\Query\Operator;
use HosmelQ\SearchSyntaxParser\Query\TextPattern;
use HosmelQ\SearchSyntaxParser\Support\FullText;

it('preserves metadata while recovering the positive clause', function (): void {
    $pattern = new TextPattern(['coff', '']);
    $words = new FullText('coff', prefix: true);
    $negative = new Clause('title', Operator::NotLike, $pattern, 3, true, true, $words);
    $positive = $negative->positive();

    expect($positive->operator)->toBe(Operator::Like)->and($positive->negated)->toBeFalse()
        ->and($positive->field)->toBe('title')->and($positive->position)->toBe(3)
        ->and($positive->value)->toBe($pattern)->and($positive->text)->toBeTrue()
        ->and($positive->fullText)->toBe($words)->and($positive->positive())->toBe($positive)
        ->and($negative->operator)->toBe(Operator::NotLike)->and($negative->negated)->toBeTrue();
});
