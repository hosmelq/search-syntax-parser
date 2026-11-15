<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Validation\Validators;

use HosmelQ\SearchSyntaxParser\Validation\Concerns\ValidatesAttributes;
use HosmelQ\SearchSyntaxParser\Validation\ValidatorInterface;
use Override;

class SizeValidator implements ValidatorInterface
{
    use ValidatesAttributes;

    public function __construct(private readonly float|int $size) {}

    #[Override]
    public function __invoke(mixed $value, string $attribute, bool $length = false): bool
    {
        return $this->validateSize($value, [$this->size], $length);
    }
}
