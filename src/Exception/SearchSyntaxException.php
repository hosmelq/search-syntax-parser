<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Exception;

use RuntimeException;

abstract class SearchSyntaxException extends RuntimeException
{
    /**
     * @param array<string, null|bool|float|int|list<string>|string> $parameters
     */
    public function __construct(
        string $message,
        private readonly ErrorReason $reason,
        private readonly array $parameters = [],
        private readonly null|int $position = null,
    ) {
        parent::__construct($message);
    }

    public function getMessageKey(): string
    {
        return $this->reason === ErrorReason::FieldNotAllowed && (
            $this->parameters['allowed']
            ?? null
        ) === []
            ? 'field.not_allowed_none'
            : $this->reason->value;
    }

    /**
     * @return array<string, string>
     */
    public function getMessageParameters(): array
    {
        return array_map(
            static fn (null|array|bool|float|int|string $value): string
                => is_array($value) ? implode(', ', $value) : (string) $value,
            $this->parameters,
        );
    }

    /**
     * @return array<string, null|bool|float|int|list<string>|string>
     */
    public function getParameters(): array
    {
        return $this->parameters;
    }

    public function getPosition(): null|int
    {
        return $this->position;
    }

    public function getReason(): ErrorReason
    {
        return $this->reason;
    }
}
