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
