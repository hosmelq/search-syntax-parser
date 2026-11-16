<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Validation\Validators;

use HosmelQ\SearchSyntaxParser\Validation\Concerns\ValidatesAttributes;
use HosmelQ\SearchSyntaxParser\Validation\ValidatorInterface;
use Override;

readonly class DecimalValidator implements ValidatorInterface
{
    use ValidatesAttributes;

    public function __construct(private int $min, private null|int $max = null) {}

    #[Override]
    public function __invoke(mixed $value, string $attribute): bool
    {
        return $this->validateDecimal($value, [$this->min, $this->max ?? $this->min]);
    }
}
