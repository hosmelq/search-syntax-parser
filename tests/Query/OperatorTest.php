<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\Query\Operator;

it('complements every operator in both directions', function (
    Operator $operator,
    Operator $opposite,
): void {
    expect($operator->complement())->toBe($opposite)->and($opposite->complement())->toBe($operator);
})->with([
    [Operator::Equal, Operator::NotEqual],
    [Operator::Greater, Operator::LessOrEqual],
    [Operator::GreaterOrEqual, Operator::Less],
    [Operator::In, Operator::NotIn],
    [Operator::Between, Operator::NotBetween],
    [Operator::Exists, Operator::NotExists],
    [Operator::Like, Operator::NotLike],
]);
