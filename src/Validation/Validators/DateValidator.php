<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Validation\Validators;

use DateTimeImmutable;
use HosmelQ\SearchSyntaxParser\Validation\ValidatorInterface;
use Override;
use ValueError;

final readonly class DateValidator implements ValidatorInterface
{
    public function __construct(private null|string $format = null) {}

    #[Override]
    public function __invoke(mixed $value, string $attribute): bool
    {
        if (! is_string($value)) {
            return false;
        }

        if ($this->format !== null) {
            try {
                $date = DateTimeImmutable::createFromFormat('!'.$this->format, $value);
            } catch (ValueError) {
                return false;
            }

            return $date !== false && DateTimeImmutable::getLastErrors() === false
                && $date->format($this->format) === $value;
        }

        if (preg_match(
            '/^(\d{4})-(\d{2})-(\d{2})(?:T(\d{2}):(\d{2})(?::(\d{2})(?:\.\d+)?)?(?:Z|[+-](\d{2})(?::(\d{2}))?)?)?$/D',
            $value,
            $parts,
        ) !== 1) {
            return false;
        }

        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
            && (int) ($parts[4] ?? 0) < 24
            && (int) ($parts[5] ?? 0) < 60
            && (int) ($parts[6] ?? 0) < 60
            && (int) ($parts[7] ?? 0) < 24
            && (int) ($parts[8] ?? 0) < 60;
    }
}
