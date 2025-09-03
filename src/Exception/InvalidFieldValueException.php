<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Exception;

use InvalidArgumentException;

class InvalidFieldValueException extends InvalidArgumentException
{
    /**
     * Field value failed validation.
     */
    public static function invalidValue(string $field): self
    {
        return new self(sprintf("Invalid value for field '%s'.", $field));
    }
}
