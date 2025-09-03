<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Exception;

use InvalidArgumentException;

class InvalidFieldException extends InvalidArgumentException
{
    /**
     * Field is not in the allowed list.
     *
     * @param list<string> $allowedFields
     */
    public static function fieldNotAllowed(string $field, array $allowedFields): self
    {
        return new self(sprintf(
            "Field '%s' is not allowed. Allowed fields are: %s.",
            $field,
            implode(', ', $allowedFields)
        ));
    }
}
