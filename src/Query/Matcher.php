<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Query;

use ArrayAccess;
use BackedEnum;
use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use HosmelQ\SearchSyntaxParser\AST\Node\BinaryOperatorNode;
use HosmelQ\SearchSyntaxParser\AST\Node\ComparisonNode;
use HosmelQ\SearchSyntaxParser\AST\Node\ExistsNode;
use HosmelQ\SearchSyntaxParser\AST\Node\InNode;
use HosmelQ\SearchSyntaxParser\AST\Node\NodeInterface;
use HosmelQ\SearchSyntaxParser\AST\Node\RangeNode;
use HosmelQ\SearchSyntaxParser\AST\Node\TermNode;
use HosmelQ\SearchSyntaxParser\AST\Node\UnaryOperatorNode;
use HosmelQ\SearchSyntaxParser\AST\Node\WildcardNode;
use HosmelQ\SearchSyntaxParser\Date\DateBounds;
use HosmelQ\SearchSyntaxParser\Date\DatePrecision;
use HosmelQ\SearchSyntaxParser\Date\DateValue;
use HosmelQ\SearchSyntaxParser\Exception\InvalidFieldValueException;
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

    /**
     * @param list<string> $terms
     */
    public function __construct(
        SearchParser $parser,
        array $terms = [],
        null|DateTimeZone $timezone = null,
        null|DateTimeZone $storageTimezone = null,
        null|DateTimeInterface $now = null,
    ) {
        $this->timezone = $timezone ?? new DateTimeZone(date_default_timezone_get());
        $this->storageTimezone = $storageTimezone ?? $this->timezone;
        $this->now = $now instanceof DateTimeInterface
            ? DateTimeImmutable::createFromInterface($now)
            : new DateTimeImmutable('now', $this->timezone);
        $fields = $parser->getAllowedFields();

        foreach ($fields as $field) {
            if (! $field->isDirective() && $field->hasCustomDateFormat()) {
                throw new InvalidArgumentException(sprintf(
                    "Matcher does not support custom date formats for field '%s'.",
                    $field->getName(),
                ));
            }
        }

        $termFields = [];

        foreach ($terms as $name) {
            $field = $fields[$name] ?? null;

            if (
                $field === null
                || $field->isPattern()
                || $field->isDirective()
                || $field->getValueType() === 'date'
            ) {
                throw new InvalidArgumentException(sprintf(
                    "Term field '%s' must be an exact configured field without a date type or directive.",
                    $name,
                ));
            }

            $termFields[] = $field;
        }

        $node = $parser->parse();
        $paths = [];
        $this->predicate = $node instanceof NodeInterface
            ? $this->compile($node, $parser, $termFields, $paths)
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
        $operator = $clause->negated ? $clause->operator->complement() : $clause->operator;
        $exists = $operator === Operator::Exists || ($clause->value instanceof Bounds
            && $clause->value->from === null && $clause->value->to === null);
        $predicate = $exists ? $this->exists(...) : $this->positive($field, $clause, $operator);

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
        bool $numeric,
        bool $text,
    ): Closure {
        if (is_bool($expected)) {
            return static fn (mixed $value): null|int
                => in_array($value, [false, true, 0, 1, '0', '1'], true)
                    ? (int) $value <=> (int) $expected
                    : null;
        }

        if ($numeric || is_int($expected) || is_float($expected)) {
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

        if ($text) {
            $expected = mb_strtolower($expected);
        }

        return static function (mixed $value) use ($expected, $text): null|int {
            $value = $text ? self::text($value) : self::string($value);

            return $value === null ? null : strcmp($value, $expected);
        };
    }

    /**
     * @param list<Field> $terms
     * @param array<string, list<string>> $paths
     *
     * @return Closure(array<array-key, mixed>): bool
     */
    private function compile(
        NodeInterface $node,
        SearchParser $parser,
        array $terms,
        array &$paths,
    ): Closure {
        if ($node instanceof BinaryOperatorNode) {
            return $this->combine([
                $this->compile($node->getLeft(), $parser, $terms, $paths),
                $this->compile($node->getRight(), $parser, $terms, $paths),
            ], $node->getOperator() === 'AND');
        }

        if ($node instanceof UnaryOperatorNode) {
            $predicate = $this->compile($node->getOperand(), $parser, $terms, $paths);

            return static fn (array $values): bool => ! $predicate($values);
        }

        $compiler = new ClauseCompiler();

        if (
            $node instanceof TermNode
            || ($node instanceof WildcardNode && $node->getField() === null)
        ) {
            $predicates = [];

            foreach ($terms as $field) {
                $clause = $compiler->term($field, $node);

                if ($clause instanceof Clause) {
                    $predicates[] = $this->clause($field, $clause, $parser, $paths);
                }
            }

            return $this->combine($predicates);
        }

        /**
         * @var ComparisonNode|ExistsNode|InNode|RangeNode|WildcardNode $node
         * @var string $name
         */
        $name = $node->getField();
        $field = $parser->getAllowedField($name) ?? new Field($name);
        $clauses = $compiler->compile($field, $node);
        $predicates = [];

        foreach ($clauses as $clause) {
            $predicates[] = $this->clause($field, $clause, $parser, $paths);
        }

        return $this->combine($predicates, $clauses[0]->negated);
    }

    /**
     * @return Closure(mixed): bool
     */
    private function dates(Field $field, Clause $clause, Operator $operator): Closure
    {
        $parse = function (bool|float|int|string $value) use ($clause): DateValue {
            try {
                return DateValue::parse((string) $value, $this->timezone, $this->now);
            } catch (InvalidArgumentException) {
                throw InvalidFieldValueException::invalidValue($clause->field, $clause->position);
            }
        };
        $value = $clause->value;

        if ($value instanceof Bounds) {
            $intervals = [DateBounds::range(
                $value->from === null ? null : $parse($value->from),
                $value->to === null ? null : $parse($value->to),
            )];
        } elseif (is_array($value)) {
            $intervals = array_map(
                static fn (bool|float|int|string $item): DateBounds => $parse($item)->bounds(),
                $value,
            );
        } else {
            /** @var bool|float|int|string $value */
            $intervals = [$parse($value)->bounds($operator->value)];
        }

        $calendar = ($field->getDateOptions()['time'] ?? null) === false;

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
    private function like(string $pattern): Closure
    {
        $segments = [''];
        $last = 0;
        $escaped = false;

        foreach (mb_str_split($pattern) as $character) {
            if (! $escaped && $character === '!') {
                $escaped = true;

                continue;
            }

            if (! $escaped && $character === '%') {
                $segments[++$last] = '';
            } else {
                $segments[$last] .= $character;
            }

            $escaped = false;
        }

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
    private function positive(Field $field, Clause $clause, Operator $operator): Closure
    {
        if ($field->getValueType() === 'date') {
            return $this->dates($field, $clause, $operator);
        }

        if ($clause->fullText instanceof FullText) {
            return $this->words($clause->fullText);
        }

        $value = $clause->value;

        if ($operator === Operator::Like) {
            /** @var string $value */
            return $this->like($value);
        }

        $numeric = ! $field->storesText() && ! $clause->text
            && in_array($field->getValueType(), ['integer', 'numeric', 'decimal'], true);

        if ($value instanceof Bounds) {
            $from = $value->from === null
                ? null
                : $this->comparison($value->from, $numeric, $clause->text);
            $to = $value->to === null
                ? null
                : $this->comparison($value->to, $numeric, $clause->text);

            return static function (mixed $stored) use ($from, $to): bool {
                $lower = $from instanceof Closure ? $from($stored) : 0;
                $upper = $to instanceof Closure ? $to($stored) : 0;

                return $lower !== null && $upper !== null && $lower >= 0 && $upper <= 0;
            };
        }

        if (is_array($value)) {
            $predicates = array_map(function (bool|float|int|string $item) use (
                $numeric,
                $clause,
            ): Closure {
                $compare = $this->comparison($item, $numeric, $clause->text);

                return static fn (mixed $stored): bool => $compare($stored) === 0;
            }, $value);

            return $this->combine($predicates);
        }

        /** @var bool|float|int|string $value */
        $compare = $this->comparison($value, $numeric, $clause->text);

        return static function (mixed $stored) use ($compare, $operator): bool {
            $order = $compare($stored);

            return $order !== null && match ($operator) {
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

        return function (mixed $value) use ($needles, $words): bool {
            $haystack = $this->haystack($value);

            if ($haystack === null || $words === []) {
                return false;
            }

            return array_all(
                $needles,
                static fn (string $needle): bool => str_contains($haystack, $needle),
            );
        };
    }
}
