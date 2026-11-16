<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Query;

final readonly class ClauseGroup
{
    /**
     * @param list<Clause|ClauseGroup> $children
     */
    public function __construct(
        public bool $and,
        public array $children,
        public null|string $field = null,
    ) {}
}
