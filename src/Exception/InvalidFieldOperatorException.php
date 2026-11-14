<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Exception;

class InvalidFieldOperatorException extends SearchSyntaxException
{
    /**
     * @param array<string, string> $parameters
     */
    public function __construct(
        string $message,
        private readonly string $field,
        array $parameters = [],
        null|int $position = null,
    ) {
        parent::__construct(
            $message,
            ErrorReason::OperatorNotAllowed,
            ['field' => $field, ...$parameters],
            $position,
        );
    }

    public static function clauseNotAllowed(string $field, string $clause, int $position): self
    {
        return new self(
            sprintf(
                "%s are not allowed for field '%s'.",
                $clause === 'exists' ? 'Exists queries' : ucfirst($clause),
                $field,
            ),
            $field,
            ['clause' => $clause],
            $position,
        );
    }

    public static function operatorNotAllowed(string $field, string $operator, int $position): self
    {
        return new self(
            sprintf("Operator '%s' is not allowed for field '%s'.", $operator, $field),
            $field,
            ['operator' => $operator],
            $position,
        );
    }

    public function getField(): string
    {
        return $this->field;
    }
}
