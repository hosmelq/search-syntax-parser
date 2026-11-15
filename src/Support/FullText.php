<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Support;

use HosmelQ\SearchSyntaxParser\Exception\ParseException;

final readonly class FullText
{
    /**
     * @var list<string>
     */
    public array $words;

    public function __construct(
        string $value,
        public bool $phrase = false,
        public bool $prefix = false,
    ) {
        $words = preg_split('/[^\p{L}\p{N}\p{M}]+/u', $value, flags: PREG_SPLIT_NO_EMPTY);

        if ($words === false) {
            throw ParseException::invalidUtf8();
        }

        $this->words = $words;
    }
}
