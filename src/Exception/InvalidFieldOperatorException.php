<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Exception;

class InvalidFieldOperatorException extends SearchSyntaxException
{
    /**
     * @param array<string, int|string> $parameters
     */
    public function __construct(
        string $message,
        private readonly string $field,
        ErrorReason $reason,
        array $parameters = [],
        null|int $position = null,
    ) {
        parent::__construct(
            $message,
            $reason,
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
            ErrorReason::OperatorNotAllowed,
            ['clause' => $clause],
            $position,
        );
    }

    public static function directiveInvalidClause(string $field, int $position): self
    {
        return new self(
            sprintf("Directive '%s' requires a single '=' comparison.", $field),
            $field,
            ErrorReason::DirectiveInvalidClause,
            position: $position,
        );
    }

    public static function directiveNotTopLevel(string $field, int $position): self
    {
        return new self(
            sprintf("Directive '%s' must be a top-level conjunct.", $field),
            $field,
            ErrorReason::DirectiveNotTopLevel,
            position: $position,
        );
    }

    public static function duplicateDirective(string $field, int $position): self
    {
        return new self(
            sprintf("Directive '%s' may appear only once.", $field),
            $field,
            ErrorReason::DuplicateDirective,
            position: $position,
        );
    }

    public static function leadingWildcardNotAllowed(string $field, int $position): self
    {
        return new self(
            sprintf("Leading wildcards are not allowed for field '%s'.", $field),
            $field,
            ErrorReason::LeadingWildcardNotAllowed,
            position: $position,
        );
    }

    public static function operatorNotAllowed(string $field, string $operator, int $position): self
    {
        return new self(
            sprintf("Operator '%s' is not allowed for field '%s'.", $operator, $field),
            $field,
            ErrorReason::OperatorNotAllowed,
            ['operator' => $operator],
            $position,
        );
    }

    public static function wildcardTooShort(string $field, int $minimum, int $position): self
    {
        return new self(
            sprintf(
                "Wildcard patterns for field '%s' require at least %d literal characters.",
                $field,
                $minimum,
            ),
            $field,
            ErrorReason::WildcardTooShort,
            ['minimum' => $minimum],
            $position,
        );
    }

    public static function wordWildcardNotAllowed(string $field, int $position): self
    {
        return new self(
            sprintf(
                "Wildcards for whole-word field '%s' may only end a word, as in 'coff*'.",
                $field,
            ),
            $field,
            ErrorReason::WordWildcardNotAllowed,
            position: $position,
        );
    }

    public function getField(): string
    {
        return $this->field;
    }
}
