<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Validation\Validators;

use DateTimeImmutable;
use DateTimeZone;
use HosmelQ\SearchSyntaxParser\Date\DateExpression;
use HosmelQ\SearchSyntaxParser\Date\DateValue;
use HosmelQ\SearchSyntaxParser\Validation\ValidatorInterface;
use InvalidArgumentException;
use Override;
use ValueError;

final readonly class DateValidator implements ValidatorInterface
{
    public function __construct(
        private null|string $format = null,
        private bool $allowPartialDates = false,
        private bool $withoutTime = false,
    ) {}

    public function withoutTime(): self
    {
        if ($this->format !== null) {
            throw new InvalidArgumentException(
                'Dates without time require a date rule without a format.',
            );
        }

        return new self(allowPartialDates: $this->allowPartialDates, withoutTime: true);
    }

    public function withPartialDates(): self
    {
        if ($this->format !== null) {
            throw new InvalidArgumentException(
                'Partial dates require a date rule without a format.',
            );
        }

        return new self(allowPartialDates: true, withoutTime: $this->withoutTime);
    }

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

        $parts = DateExpression::absolute($value);

        if ($parts !== null) {
            if ($this->withoutTime && isset($parts['hour'])) {
                return false;
            }

            return $this->allowPartialDates || isset($parts['day']);
        }

        $relative = DateExpression::relative($value);

        // Without time, only day anchors resolve to whole days; now and bare offsets are instants.
        if ($relative === null || ($this->withoutTime && $relative['anchor'] === 'now')) {
            return false;
        }

        // Accept a relative value only when it resolves to a supported year right now.
        try {
            DateValue::parse($value, new DateTimeZone('UTC'));
        } catch (InvalidArgumentException) {
            return false;
        }

        return true;
    }
}
