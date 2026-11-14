<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Exception;

class InvalidFieldValueException extends SearchSyntaxException
{
    public function __construct(
        string $message,
        private readonly string $field,
        null|int $position = null,
    ) {
        parent::__construct(
            $message,
            ErrorReason::InvalidFieldValue,
            ['field' => $field],
            $position,
        );
    }

    /**
     * Field value failed validation.
     */
    public static function invalidValue(string $field, null|int $position = null): self
    {
        return new self(sprintf("Invalid value for field '%s'.", $field), $field, $position);
    }

    public function getField(): string
    {
        return $this->field;
    }
}
