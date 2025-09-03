<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Validation\Validators;

use Closure;
use HosmelQ\SearchSyntaxParser\Validation\ValidatorInterface;

readonly class CallbackValidator implements ValidatorInterface
{
    /**
     * Create a callback validator with the given closure.
     */
    public function __construct(private Closure $callback)
    {
    }

    /**
     * Validate that the value passes the custom validation callback.
     */
    public function __invoke(mixed $value, string $attribute): bool
    {
        return (bool) ($this->callback)($value, $attribute);
    }
}
