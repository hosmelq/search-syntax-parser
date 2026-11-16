<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Query;

use ArrayAccess;
use BackedEnum;
use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use HosmelQ\SearchSyntaxParser\Date\DateBounds;
use HosmelQ\SearchSyntaxParser\Date\DatePrecision;
use HosmelQ\SearchSyntaxParser\Date\DateValue;
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Support\Decimal;
use HosmelQ\SearchSyntaxParser\Support\FullText;
use HosmelQ\SearchSyntaxParser\Validation\Field;
use InvalidArgumentException;
use Stringable;
use Traversable;
use WeakMap;

final class Matcher
{
    /**
     * @var WeakMap<object, list<mixed>>
     */
    private WeakMap $lists;

    private readonly DateTimeImmutable $now;

    /**
     * @var array<string, list<string>>
     */
    private readonly array $paths;

    /**
     * @var Closure(array<array-key, mixed>): bool
     */
    private readonly Closure $predicate;

    private readonly DateTimeZone $storageTimezone;

    private readonly DateTimeZone $timezone;

    private null|string $wordHaystack = null;

    private mixed $wordValue = null;

    public function __construct(
        SearchParser $parser,
        null|DateTimeZone $timezone = null,
        null|DateTimeZone $storageTimezone = null,
        null|DateTimeInterface $now = null,
    ) {
        $this->timezone = $timezone ?? new DateTimeZone(date_default_timezone_get());
        $this->storageTimezone = $storageTimezone ?? $this->timezone;
        $this->now = $now instanceof DateTimeInterface
            ? DateTimeImmutable::createFromInterface($now)
            : new DateTimeImmutable('now', $this->timezone);
        $fields = $parser->getConfiguredFields();

        foreach ($fields as $field) {
            if (! $field->isDirective() && $field->hasCustomDateFormat()) {
                throw new InvalidArgumentException(sprintf(
                    "Matcher does not support custom date formats for field '%s'.",
                    $field->getName(),
                ));
            }
        }

        $group = $parser->compile();
        $paths = [];
        $this->predicate = $group instanceof ClauseGroup
            ? $this->compile($group, $parser, $paths)
            : static fn (array $values): bool => true;
        $this->paths = $paths;
    }

    /**
     * @param array<array-key, mixed>|object $record
     */
    public function matches(array|object $record): bool
    {
        $this->lists = new WeakMap();
        $values = [];

        foreach ($this->paths as $name => $segments) {
            if (
                (is_array($record) && array_key_exists($name, $record))
                || ($record instanceof ArrayAccess && $record->offsetExists($name))
            ) {
                $value = $record[$name];
            } else {
                $value = $record;

                foreach ($segments as $segment) {
                    $value = $this->read($value, $segment);
                }
            }

            $value = $this->normalize($value);
            $values[$name] = is_array($value) && array_is_list($value)
                ? array_map($this->normalize(...), $value)
                : $value;
        }

        return ($this->predicate)($values);
    }

    private static function string(mixed $value): null|string
    {
        return match (true) {
            is_string($value) => $value,
            is_int($value) => (string) $value,
            is_float($value) && is_finite($value) => (string) Decimal::from($value),
            default => null,
        };
    }

    private static function text(mixed $value): null|string
    {
        $value = self::string($value);

        return $value === null ? null : mb_strtolower($value);
    }

    /**
     * @param array<string, list<string>> $paths
     *
     * @return Closure(array<array-key, mixed>): bool
     */
    private function clause(
        Field $field,
        Clause $clause,
        SearchParser $parser,
        array &$paths,
    ): Closure {
        $name = $parser->getInternalFieldName($clause->field);
        $paths[$name] = explode('.', $name);
        $positive = $clause->positive();
        $exists = $positive->operator === Operator::Exists;
        $predicate = $exists ? $this->exists(...) : $this->positive($field, $positive);

        return static function (array $values) use ($name, $exists, $predicate, $clause): bool {
            $value = $values[$name];
            $matched = false;

            if (! $exists && is_array($value) && array_is_list($value)) {
                foreach ($value as $item) {
                    if ($predicate($item)) {
                        $matched = true;

                        break;
                    }
                }
            } else {
                $matched = $predicate($value);
            }

            return $clause->negated ? ! $matched : $matched;
        };
    }

    /**
     * @template T
     *
     * @param list<Closure(T): bool> $predicates
     *
     * @return Closure(T): bool
     */
    private function combine(array $predicates, bool $and = false): Closure
    {
        return static fn (mixed $value): bool => $and
            ? array_all($predicates, static fn (Closure $predicate): bool => $predicate($value))
            : array_any($predicates, static fn (Closure $predicate): bool => $predicate($value));
    }

    /**
     * @return Closure(mixed): (null|int)
     */
    private function comparison(
        bool|float|int|string $expected,
        Field $field,
        bool $text,
    ): Closure {
        if (is_bool($expected)) {
            return static fn (mixed $value): null|int
                => in_array($value, [false, true, 0, 1, '0', '1'], true)
                    ? (int) $value <=> (int) $expected
                    : null;
        }

        if ($field->comparisonType($expected) === 'number') {
            $number = Decimal::from($expected);

            return static function (mixed $value) use ($number): null|int {
                // Exact decimals write out every digit of an exponent, so stored exponents use floats.
                if (is_string($value) && is_numeric($value) && mb_stripos($value, 'e') !== false) {
                    $value = (float) $value;
                }

                return is_int($value)
                    || (is_float($value) && is_finite($value))
                    || (is_string($value) && is_numeric($value))
                    ? Decimal::from($value)->compareTo($number)
                    : null;
            };
        }

        /** @var string $expected */
        if ($text) {
            $expected = mb_strtolower($expected);
        }

        return static function (mixed $value) use ($expected, $text): null|int {
            $value = $text ? self::text($value) : self::string($value);

            return $value === null ? null : strcmp($value, $expected);
        };
    }

    /**
     * @param array<string, list<string>> $paths
     *
     * @return Closure(array<array-key, mixed>): bool
     */
    private function compile(
        Clause|ClauseGroup $clause,
        SearchParser $parser,
        array &$paths,
    ): Closure {
        if ($clause instanceof Clause) {
            return $this->clause(
                $parser->getAllowedField($clause->field) ?? new Field($clause->field),
                $clause,
                $parser,
                $paths,
            );
        }

        $predicates = [];

        foreach ($clause->children as $child) {
            $predicates[] = $this->compile($child, $parser, $paths);
        }

        return $this->combine($predicates, $clause->and);
    }

    /**
     * @return Closure(mixed): bool
     */
    private function dates(Field $field, Clause $clause): Closure
    {
        $intervals = DateBounds::forClause($clause, $this->timezone, $this->now);
        $calendar = $field->isCalendarDate();

        return function (mixed $value) use ($intervals, $calendar): bool {
            if ($calendar && $value instanceof DateTimeInterface) {
                $value = $value->format('Y-m-d');
            }

            if (is_string($value)) {
                try {
                    $stored = DateValue::parse(
                        str_replace(' ', 'T', $value),
                        $this->storageTimezone,
                    );

                    if (
                        $stored->isRelative()
                        || in_array(
                            $stored->precision(),
                            [DatePrecision::Year, DatePrecision::Month],
                            true,
                        )
                    ) {
                        return false;
                    }

                    $value = $calendar
                        ? DateValue::parse(mb_substr($value, 0, 10), $this->timezone)->start()
                        : $stored->start();
                } catch (InvalidArgumentException) {
                    return false;
                }
            }

            return $value instanceof DateTimeInterface && array_any(
                $intervals,
                static fn (DateBounds $bounds): bool
                    => (! $bounds->from instanceof DateTimeImmutable || $value >= $bounds->from)
                        && (! $bounds->to instanceof DateTimeImmutable || $value < $bounds->to),
            );
        };
    }

    private function exists(mixed $value): bool
    {
        return is_array($value) && array_is_list($value) ? $value !== [] : $value !== null;
    }

    /**
     * Tokens never contain spaces, so spaces delimit whole words. Clauses on one value reuse its tokens.
     */
    private function haystack(mixed $value): null|string
    {
        if ($value !== $this->wordValue) {
            $text = self::text($value);
            $this->wordValue = $value;
            $this->wordHaystack = $text === null
                ? null
                : ' '.implode(' ', new FullText($text)->words).' ';
        }

        return $this->wordHaystack;
    }

    /**
     * @return Closure(mixed): bool
     */
    private function like(TextPattern $pattern): Closure
    {
        $segments = $pattern->segments;
        $last = count($segments) - 1;
        $segments = array_map(mb_strtolower(...), $segments);

        return static function (mixed $value) use ($segments, $last): bool {
            $text = self::text($value);

            if ($text === null) {
                return false;
            }

            if (! str_starts_with($text, $segments[0])) {
                return false;
            }

            $offset = mb_strlen($segments[0], '8bit');

            for ($index = 1; $index < $last; $index++) {
                $position = mb_strpos($text, $segments[$index], $offset, '8bit');

                if ($position === false) {
                    return false;
                }

                $offset = $position + mb_strlen($segments[$index], '8bit');
            }

            return mb_strlen($text, '8bit') - mb_strlen($segments[$last], '8bit') >= $offset
                && str_ends_with($text, $segments[$last]);
        };
    }

    private function normalize(mixed $value): mixed
    {
        return match (true) {
            $value instanceof BackedEnum => $value->value,
            $value instanceof DateTimeInterface => $value,
            $value instanceof Traversable => $this->lists[$value] ??= iterator_to_array(
                $value,
                false,
            ),
            $value instanceof Stringable => (string) $value,
            default => $value,
        };
    }

    /**
     * @return Closure(mixed): bool
     */
    private function positive(Field $field, Clause $clause): Closure
    {
        if ($field->isDate()) {
            return $this->dates($field, $clause);
        }

        if ($clause->fullText instanceof FullText) {
            return $this->words($clause->fullText);
        }

        $value = $clause->value;

        if ($clause->operator === Operator::Like) {
            /** @var TextPattern $value */
            return $this->like($value);
        }

        if ($value instanceof Bounds) {
            $from = $value->from === null
                ? null
                : $this->comparison($value->from, $field, $clause->text);
            $to = $value->to === null
                ? null
                : $this->comparison($value->to, $field, $clause->text);

            return static function (mixed $stored) use ($from, $to): bool {
                $lower = $from instanceof Closure ? $from($stored) : 0;
                $upper = $to instanceof Closure ? $to($stored) : 0;

                return $lower !== null && $upper !== null && $lower >= 0 && $upper <= 0;
            };
        }

        if (is_array($value)) {
            $predicates = array_map(function (bool|float|int|string $item) use (
                $field,
                $clause,
            ): Closure {
                $compare = $this->comparison($item, $field, $clause->text);

                return static fn (mixed $stored): bool => $compare($stored) === 0;
            }, $value);

            return $this->combine($predicates);
        }

        /** @var bool|float|int|string $value */
        $compare = $this->comparison($value, $field, $clause->text);

        return static function (mixed $stored) use ($compare, $clause): bool {
            $order = $compare($stored);

            return $order !== null && match ($clause->operator) {
                Operator::Equal => $order === 0,
                Operator::Greater => $order > 0,
                Operator::GreaterOrEqual => $order >= 0,
                Operator::Less => $order < 0,
                Operator::LessOrEqual => $order <= 0,
                default => false,
            };
        };
    }

    private function read(mixed $value, string $key): mixed
    {
        if ($value instanceof ArrayAccess && $value->offsetExists($key)) {
            return $value[$key];
        }

        if ($value instanceof Traversable) {
            $value = $this->normalize($value);
        }

        if (! is_array($value)) {
            return is_object($value) ? get_object_vars($value)[$key] ?? null : null;
        }

        if (array_key_exists($key, $value)) {
            return $value[$key];
        }

        if (! array_is_list($value) || preg_match('/^-?\d+$/D', $key) === 1) {
            return null;
        }

        $values = [];

        foreach ($value as $item) {
            $item = $this->normalize($this->read($item, $key));

            if ($item === null) {
                continue;
            }

            if (is_array($item) && array_is_list($item)) {
                array_push($values, ...$item);
            } else {
                $values[] = $item;
            }
        }

        return $values;
    }

    /**
     * @return Closure(mixed): bool
     */
    private function words(FullText $match): Closure
    {
        $words = array_map(mb_strtolower(...), $match->words);
        $last = array_key_last($words);
        $needles = $match->phrase
            ? [' '.implode(' ', $words).($match->prefix ? '' : ' ')]
            : array_map(
                static fn (string $word, int $index): string
                    => ' '.$word.($match->prefix && $index === $last ? '' : ' '),
                $words,
                array_keys($words),
            );

        return function (mixed $value) use ($needles): bool {
            $haystack = $this->haystack($value);

            if ($haystack === null) {
                return false;
            }

            return array_all(
                $needles,
                static fn (string $needle): bool => str_contains($haystack, $needle),
            );
        };
    }
}
