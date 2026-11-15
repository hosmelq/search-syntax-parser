<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Query;

/**
 * Written range bounds are inclusive; null denotes an open end.
 */
final readonly class Bounds
{
    public function __construct(
        public null|bool|float|int|string $from,
        public null|bool|float|int|string $to,
    ) {}
}
