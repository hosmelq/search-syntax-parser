<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Sorting;

use InvalidArgumentException;

final class AllowedSort
{
    /**
     * @var 'asc'|'desc'
     */
    private string $direction = 'asc';

    private readonly string $internalName;

    public function __construct(private readonly string $name, null|string $internalName = null)
    {
        if ($name === '') {
            throw new InvalidArgumentException('A sort name cannot be empty.');
        }

        $this->internalName = $internalName ?? $name;
    }

    public static function field(string $name, null|string $internalName = null): self
    {
        return new self($name, $internalName);
    }

    public function defaultDirection(string $direction): self
    {
        $direction = mb_strtolower($direction);

        if (! in_array($direction, ['asc', 'desc'], true)) {
            throw new InvalidArgumentException('A sort direction must be asc or desc.');
        }

        $this->direction = $direction;

        return $this;
    }

    /**
     * @return 'asc'|'desc'
     */
    public function getDefaultDirection(): string
    {
        return $this->direction;
    }

    public function getInternalName(): string
    {
        return $this->internalName;
    }

    public function getName(): string
    {
        return $this->name;
    }
}
