<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Validation\Validators;

use Closure;
use HosmelQ\SearchSyntaxParser\Validation\ValidatorInterface;
use Override;

readonly class CallbackValidator implements ValidatorInterface
{
    public function __construct(private Closure $callback) {}

    #[Override]
    public function __invoke(mixed $value, string $attribute): bool
    {
        return (bool) ($this->callback)($value, $attribute);
    }
}
