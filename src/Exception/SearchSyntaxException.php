<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Exception;

use RuntimeException;

abstract class SearchSyntaxException extends RuntimeException
{
    public function __construct(string $message, private readonly null|int $position = null)
    {
        parent::__construct($message);
    }

    public function getPosition(): null|int
    {
        return $this->position;
    }
}
