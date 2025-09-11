<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Validation;

use Closure;
use HosmelQ\SearchSyntaxParser\Validation\Validators\BetweenValidator;
use HosmelQ\SearchSyntaxParser\Validation\Validators\BooleanValidator;
use HosmelQ\SearchSyntaxParser\Validation\Validators\CallbackValidator;
use HosmelQ\SearchSyntaxParser\Validation\Validators\DecimalValidator;
use HosmelQ\SearchSyntaxParser\Validation\Validators\IntegerValidator;
use HosmelQ\SearchSyntaxParser\Validation\Validators\InValidator;
use HosmelQ\SearchSyntaxParser\Validation\Validators\MaxValidator;
use HosmelQ\SearchSyntaxParser\Validation\Validators\MinValidator;
use HosmelQ\SearchSyntaxParser\Validation\Validators\NumericValidator;
use HosmelQ\SearchSyntaxParser\Validation\Validators\SizeValidator;
use HosmelQ\SearchSyntaxParser\Validation\Validators\StringValidator;

/**
 * Builder for per-item validation rules on array fields.
 *
 * Use with AllowedField::array(...)->each(fn ($rules) => $rules->string()->max(20)).
 */
final class AllowedFieldItemRules
{
    /**
     * @var list<ValidatorInterface>
     */
    private array $validators = [];

    /**
     * Require size/value between min and max for each item.
     */
    public function between(float|int $min, float|int $max): self
    {
        $this->validators[] = new BetweenValidator($min, $max);

        return $this;
    }

    /**
     * Require each item to be boolean or boolean-like.
     */
    public function boolean(): self
    {
        $this->validators[] = new BooleanValidator();

        return $this;
    }

    /**
     * Validate each item using a custom callback.
     *
     * @param callable(mixed $value, string $attribute): bool $callback
     */
    public function callback(callable $callback): self
    {
        $this->validators[] = new CallbackValidator(Closure::fromCallable($callback));

        return $this;
    }

    /**
     * Require each numeric item to have decimal places within the range.
     */
    public function decimal(int $min, null|int $max = null): self
    {
        $this->validators[] = new DecimalValidator($min, $max);

        return $this;
    }

    /**
     * @return list<ValidatorInterface>
     */
    public function getValidators(): array
    {
        return $this->validators;
    }

    /**
     * Require each item to be in the given list.
     *
     * @param list<mixed> $values
     */
    public function in(array $values): self
    {
        $this->validators[] = new InValidator($values);

        return $this;
    }

    /**
     * Require each item to be an integer.
     */
    public function integer(): self
    {
        $this->validators[] = new IntegerValidator();

        return $this;
    }

    /**
     * Require each item to have size/value <= given max.
     */
    public function max(float|int $value): self
    {
        $this->validators[] = new MaxValidator($value);

        return $this;
    }

    /**
     * Require each item to have size/value >= given min.
     */
    public function min(float|int $value): self
    {
        $this->validators[] = new MinValidator($value);

        return $this;
    }

    /**
     * Require each item to be numeric.
     */
    public function numeric(): self
    {
        $this->validators[] = new NumericValidator();

        return $this;
    }

    /**
     * Require each item to have size/value equal to given size.
     */
    public function size(float|int $value): self
    {
        $this->validators[] = new SizeValidator($value);

        return $this;
    }

    /**
     * Require each item to be a string.
     */
    public function string(): self
    {
        $this->validators[] = new StringValidator();

        return $this;
    }
}
