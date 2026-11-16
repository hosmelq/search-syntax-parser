<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Query;

use HosmelQ\SearchSyntaxParser\Support\FullText;

final readonly class Clause
{
    /**
     * @param null|bool|Bounds|float|int|list<bool|float|int|string>|string|TextPattern $value
     */
    public function __construct(
        public string $field,
        public Operator $operator,
        public null|array|bool|Bounds|float|int|string|TextPattern $value,
        public null|int $position = null,
        public bool $negated = false,
        public bool $text = false,
        public null|FullText $fullText = null,
    ) {}

    public function positive(): self
    {
        return $this->negated ? new self(
            $this->field,
            $this->operator->complement(),
            $this->value,
            $this->position,
            text: $this->text,
            fullText: $this->fullText,
        ) : $this;
    }
}
