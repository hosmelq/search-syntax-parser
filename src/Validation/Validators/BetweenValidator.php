<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Validation\Validators;

use HosmelQ\SearchSyntaxParser\Validation\Concerns\ValidatesAttributes;
use HosmelQ\SearchSyntaxParser\Validation\ValidatorInterface;
use Override;

readonly class BetweenValidator implements ValidatorInterface
{
    use ValidatesAttributes;

    public function __construct(private float|int $min, private float|int $max) {}

    #[Override]
    public function __invoke(mixed $value, string $attribute, bool $length = false): bool
    {
        return $this->validateBetween($value, [$this->min, $this->max], $length);
    }
}
