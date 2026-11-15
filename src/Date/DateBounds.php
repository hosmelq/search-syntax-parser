<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Date;

use DateTimeImmutable;

/**
 * Half-open adapter bounds: from is inclusive and to is exclusive.
 */
final readonly class DateBounds
{
    public function __construct(
        public null|DateTimeImmutable $from,
        public null|DateTimeImmutable $to,
    ) {}

    public static function range(null|DateValue $from, null|DateValue $to): self
    {
        return new self($from?->start(), $to?->end());
    }
}
