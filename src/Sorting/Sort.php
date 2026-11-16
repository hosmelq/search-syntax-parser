<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Sorting;

final readonly class Sort
{
    /**
     * @param 'asc'|'desc' $direction
     */
    public function __construct(
        private string $name,
        private string $internalName,
        private string $direction,
        private bool $default = false,
    ) {}

    /**
     * @return 'asc'|'desc'
     */
    public function getDirection(): string
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

    public function isDefault(): bool
    {
        return $this->default;
    }
}
