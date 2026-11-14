<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Exception;

class InvalidFieldException extends SearchSyntaxException
{
    public function __construct(
        string $message,
        private readonly string $field,
        null|int $position = null,
    ) {
        parent::__construct($message, $position);
    }

    /**
     * Field is not in the allowed list.
     *
     * @param list<string> $allowedFields
     */
    public static function fieldNotAllowed(
        string $field,
        array $allowedFields,
        null|int $position = null,
    ): self {
        return new self(sprintf(
            "Field '%s' is not allowed. Allowed fields are: %s.",
            $field,
            implode(', ', $allowedFields),
        ), $field, $position);
    }

    public function getField(): string
    {
        return $this->field;
    }
}
