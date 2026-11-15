<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Parser;

use HosmelQ\SearchSyntaxParser\AST\Node\BinaryOperatorNode;
use HosmelQ\SearchSyntaxParser\AST\Node\ComparisonNode;
use HosmelQ\SearchSyntaxParser\AST\Node\ExistsNode;
use HosmelQ\SearchSyntaxParser\AST\Node\InNode;
use HosmelQ\SearchSyntaxParser\AST\Node\NodeInterface;
use HosmelQ\SearchSyntaxParser\AST\Node\RangeNode;
use HosmelQ\SearchSyntaxParser\AST\Node\TermNode;
use HosmelQ\SearchSyntaxParser\AST\Node\UnaryOperatorNode;
use HosmelQ\SearchSyntaxParser\AST\Node\WildcardNode;
use HosmelQ\SearchSyntaxParser\AST\Span;
use HosmelQ\SearchSyntaxParser\Exception\InvalidFieldException;
use HosmelQ\SearchSyntaxParser\Exception\InvalidFieldOperatorException;
use HosmelQ\SearchSyntaxParser\Exception\InvalidFieldValueException;
use HosmelQ\SearchSyntaxParser\Exception\ParseException;
use HosmelQ\SearchSyntaxParser\Lexer\SearchLexer;
use HosmelQ\SearchSyntaxParser\Lexer\Tokens;
use HosmelQ\SearchSyntaxParser\Lexer\TokenType;
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;
use HosmelQ\SearchSyntaxParser\Validation\AllowedFieldItemRules;

/**
 * @internal
 */
class Parser
{
    private readonly bool $anyField;

    private int $clauses = 0;

    /**
     * Token index extent of the node last returned by parseTerm() or parseExpression().
     */
    private int $extentEnd = 0;

    private int $extentStart = 0;

    /**
     * Whether the query contains `..` at all, so clauses can skip compact range checks.
     */
    private bool $hasDotRanges = false;

    /**
     * Whether the query contains `*` at all, so clauses can skip pattern checks.
     */
    private bool $hasStars = false;

    private int $index = 0;

    private readonly bool $leadingWildcards;

    /**
     * Field mentions are tracked only when defaults need them.
     *
     * @var null|array<string, true>
     */
    private null|array $referencedFields = null;

    /**
     * Literal segments of the most recently parsed value, or null for a literal value.
     *
     * @var null|list<string>
     */
    private null|array $segments = null;

    private Tokens $tokens;

    public function __construct(
        private readonly SearchParser $searchParser,
        private readonly int $maxDepth,
        private readonly int $maxClauses,
    ) {
        $this->anyField = $searchParser->allowsAnyField();
        $this->leadingWildcards = $searchParser->allowsLeadingWildcards();
    }

    /**
     * Return field mentions, including clauses removed by ignored values.
     *
     * @return array<string, true>
     */
    public function getReferencedFields(): array
    {
        return $this->referencedFields ?? [];
    }

    /**
     * Parse input into an AST, bounding recursion and tree size.
     *
     * @throws ParseException
     */
    public function parse(string $input, bool $hasDefaults = false): null|NodeInterface
    {
        $this->tokens = new SearchLexer()->tokenize($input);
        $this->hasStars = str_contains($input, '*');
        $this->hasDotRanges = str_contains($input, '..');
        $this->index = 0;
        $this->clauses = 0;
        $this->referencedFields = $hasDefaults ? [] : null;

        if ($this->tokens->types === []) {
            return null;
        }

        $ast = $this->parseExpression(0);
        $remaining = $this->tokens->values[$this->index] ?? null;

        if ($remaining !== null) {
            throw ParseException::unexpectedToken($remaining, $this->position());
        }

        return $ast;
    }

    /**
     * Check a field pattern's shape: leading wildcards and the minimum literal length.
     */
    private function assertPattern(
        string $field,
        null|AllowedField $allowed,
        string $pattern,
        bool $quoted,
        int $start,
    ): void {
        // A quoted pattern is its literal text plus a trailing `*`; an unquoted one may hold
        // several `*`. Counting characters avoids splitting the pattern into segments.
        $leading = $quoted ? mb_strlen($pattern) === 1 : str_starts_with($pattern, '*');

        if ($allowed instanceof AllowedField) {
            $allowed->assertWildcardAllowed(
                $leading,
                mb_strlen($pattern) - ($quoted ? 1 : mb_substr_count($pattern, '*')),
                $this->position($start),
                $field,
            );
        } elseif ($leading && ! $this->leadingWildcards) {
            throw InvalidFieldOperatorException::leadingWildcardNotAllowed(
                $field,
                $this->position($start),
            );
        }
    }

    /**
     * Join operands, skipping removed ones. Extents are token indexes of the surviving operands,
     * so a binary node's span never covers removed clauses at its edges.
     */
    private function combine(
        string $operator,
        null|NodeInterface $left,
        int &$leftStart,
        int &$leftEnd,
        null|NodeInterface $right,
        int $rightStart,
        int $rightEnd,
    ): null|NodeInterface {
        if (! $left instanceof NodeInterface) {
            $leftStart = $rightStart;
            $leftEnd = $rightEnd;

            return $right;
        }

        if (! $right instanceof NodeInterface) {
            return $left;
        }

        $leftEnd = $rightEnd;
        $offset = $this->tokens->offsets[$leftStart];

        return new BinaryOperatorNode(
            $operator,
            $left,
            $right,
            new Span($offset, $this->tokens->ends[$rightEnd] - $offset),
        );
    }

    /**
     * Tell cheaply whether the current value token, after a leading minus, contains `..`.
     * Whether the minus is adjacent is checked by rangeBounds().
     */
    private function hasDots(): bool
    {
        $value = $this->tokens->values[$this->index] ?? '';

        return str_contains(
            $value === '-' ? ($this->tokens->values[$this->index + 1] ?? '') : $value,
            '..',
        );
    }

    /**
     * @phpstan-impure
     */
    private function match(TokenType $expected): void
    {
        $actual = $this->tokens->types[$this->index] ?? null;

        if ($actual !== $expected) {
            throw ParseException::expectedToken(
                $expected->name,
                $actual->name ?? 'NULL',
                $this->position(),
            );
        }

        $this->index++;
    }

    /**
     * @phpstan-impure
     */
    private function parseExpression(int $depth): null|NodeInterface
    {
        $left = $this->parseTerm($depth);
        $leftStart = $this->extentStart;
        $leftEnd = $this->extentEnd;

        while (($this->tokens->types[$this->index] ?? null) === TokenType::Or) {
            $this->index++;

            $right = $this->parseTerm($depth);
            $left = $this->combine(
                'OR',
                $left,
                $leftStart,
                $leftEnd,
                $right,
                $this->extentStart,
                $this->extentEnd,
            );
        }

        $this->extentStart = $leftStart;
        $this->extentEnd = $leftEnd;

        return $left;
    }

    /**
     * @phpstan-impure
     */
    private function parseFactor(int $depth): null|NodeInterface
    {
        $start = $this->index;
        $type = $this->tokens->types[$this->index] ?? null;

        if ($type === null) {
            throw ParseException::unexpectedEndOfInput($this->position());
        }

        if (match ($type) {
            TokenType::Not, TokenType::Minus, TokenType::OpenParenthesis => true,
            default => false,
        }) {
            if ($depth >= $this->maxDepth) {
                throw ParseException::maximumDepth($this->maxDepth, $this->position());
            }

            $this->index++;

            if ($type !== TokenType::OpenParenthesis) {
                $operand = $this->parseFactor($depth + 1);

                return $operand instanceof NodeInterface
                    ? new UnaryOperatorNode('NOT', $operand, $this->span($start))
                    : null;
            }

            $expression = $this->parseExpression($depth + 1);

            $this->match(TokenType::CloseParenthesis);

            return $expression;
        }

        $value = $this->tokens->values[$this->index] ?? '';

        if (
            ! match ($type) {
                TokenType::Word, TokenType::String, TokenType::Wildcard => true,
                default => false,
            }
            || ($type === TokenType::Word && $value === '*')
        ) {
            throw ParseException::expectedValue($this->position());
        }

        if (++$this->clauses > $this->maxClauses) {
            throw ParseException::maximumClauses($this->maxClauses, $this->position());
        }

        $this->index++;

        $next = $this->tokens->types[$this->index] ?? null;

        if ($type === TokenType::Word && match ($next) {
            TokenType::Colon, TokenType::Greater, TokenType::GreaterEqual, TokenType::Less, TokenType::LessEqual, TokenType::NotEqual => true,
            default => false,
        }) {
            return $this->parseFieldQuery($value, $start);
        }

        $segments = ($type !== TokenType::String && str_contains($value, '*'))
            ? $this->wildcardSegments($value, $type) : null;

        if (
            $segments !== null
            && $segments[0] === ''
            && ! $this->leadingWildcards
        ) {
            throw ParseException::leadingWildcardNotAllowed($this->position($start));
        }

        return $segments === null
            ? new TermNode($value, $type === TokenType::String, $this->span($start))
            : new WildcardNode(null, '=', $segments, $this->span($start));
    }

    /**
     * @phpstan-impure
     */
    private function parseFieldQuery(string $field, int $start): null|NodeInterface
    {
        $allowed = $this->searchParser->getAllowedField($field);

        if (! ($allowed?->accepts($field) ?? $this->anyField)) {
            throw InvalidFieldException::fieldNotAllowed(
                $field,
                $this->searchParser->getAllowedFieldNames(),
                $this->position($this->index - 1),
            );
        }

        if ($this->referencedFields !== null) {
            $this->referencedFields[$field] = true;
        }

        $hasColon = ($this->tokens->types[$this->index] ?? null) === TokenType::Colon;

        if ($hasColon) {
            $this->index++;

            $bounds = $this->hasDotRanges && $this->hasDots() ? $this->rangeBounds() : null;

            if (
                ($this->tokens->types[$this->index] ?? null) === TokenType::OpenBracket
                || $bounds !== null
            ) {
                $allowed?->assertClauseAllowed('ranges', $this->position(), $field);

                return $this->parseRange($field, $start, $allowed, $bounds);
            }
        }

        $operator = match ($this->tokens->types[$this->index] ?? null) {
            TokenType::Greater => '>',
            TokenType::GreaterEqual => '>=',
            TokenType::Less => '<',
            TokenType::LessEqual => '<=',
            TokenType::NotEqual => '!=',
            default => '=',
        };

        if ($operator !== '=') {
            $operatorIndex = $this->index;

            $this->index++;
        } else {
            $operatorIndex = $this->index;
        }

        // Inspect the whole clause before any alias, cast, or value validator runs. Usage
        // rules apply to fields that declare them; pattern shapes apply to every field, and
        // without usage rules only a pattern or a list can break them.
        $rules = $allowed instanceof AllowedField && $allowed->hasUsageRules() ? $allowed : null;
        $scan = $this->index;
        $valueAt = ($this->tokens->types[$scan] ?? null) === TokenType::Minus ? $scan + 1 : $scan;

        if (
            $rules instanceof AllowedField
            || (
                $this->hasStars
                && (
                    str_contains($this->tokens->values[$valueAt] ?? '', '*')
                    || (
                        $hasColon
                        && ($this->tokens->types[$valueAt + 1] ?? null) === TokenType::Comma
                    )
                )
            )
        ) {
            if (
                $hasColon
                && $operator === '='
                && ($this->tokens->types[$scan] ?? null) === TokenType::Word
                && $this->tokens->values[$scan] === '*'
            ) {
                $rules?->assertClauseAllowed('exists', $this->position($operatorIndex), $field);
            } else {
                $rules?->assertOperatorAllowed($operator, $this->position($operatorIndex), $field);

                do {
                    $type = $this->tokens->types[$scan] ?? null;
                    $patternIndex = $type === TokenType::Minus ? $scan + 1 : $scan;
                    $patternType = $this->tokens->types[$patternIndex] ?? null;
                    $text = $this->tokens->values[$patternIndex] ?? '';

                    if (
                        (
                            $patternType === TokenType::Wildcard
                            || (
                                $patternType === TokenType::Word
                                && ($type === TokenType::Minus || $text !== '*')
                                && str_contains($text, '*')
                            )
                        )
                        && (! str_contains($text, '..') || $this->rangeBounds($scan) === null)
                    ) {
                        $rules?->assertClauseAllowed(
                            'wildcards',
                            $this->position($operatorIndex),
                            $field,
                        );

                        // An ordering operator with a pattern, or a minus not directly followed by
                        // a word, is a syntax error reported later.
                        if (
                            ($operator === '=' || $operator === '!=')
                            && (
                                $type !== TokenType::Minus
                                || (
                                    $patternType === TokenType::Word
                                    && $this->tokens->offsets[$patternIndex] === $this->tokens->ends[$scan]
                                )
                            )
                        ) {
                            $this->assertPattern(
                                $field,
                                $allowed,
                                ($type === TokenType::Minus ? '-' : '').$text,
                                $patternType === TokenType::Wildcard,
                                $scan,
                            );
                        }
                    }

                    $scan += $type === TokenType::Minus ? 2 : 1;

                    if (($this->tokens->types[$scan] ?? null) !== TokenType::Comma || ! $hasColon) {
                        break;
                    }

                    $rules?->assertClauseAllowed('lists', $this->position($operatorIndex), $field);

                    $scan++;
                } while (true);
            }
        }

        if (
            ($this->tokens->types[$this->index] ?? null) === TokenType::Word
            && $this->tokens->values[$this->index] === '*'
        ) {
            if (! $hasColon || $operator !== '=') {
                throw ParseException::invalidWildcardUsage($field, $this->position());
            }

            $this->index++;

            return new ExistsNode($field, $this->span($start));
        }

        $valueIndex = $this->index;
        $value = $this->parseValue($field, $allowed, rangeChecked: $hasColon && $operator === '=');
        $segments = $this->segments;
        $quoted = ($this->tokens->types[$valueIndex] ?? null) === TokenType::String;

        if ($hasColon && ($this->tokens->types[$this->index] ?? null) === TokenType::Comma) {
            $values = [$value];
            $quotes = [$quoted];
            $patterns = [$segments];
            $starts = [$valueIndex];
            $hasPattern = $segments !== null;

            do {
                $this->index++;

                $starts[] = $this->index;
                $quotes[] = ($this->tokens->types[$this->index] ?? null) === TokenType::String;
                $values[] = $this->parseValue($field, $allowed, count($values));
                $patterns[] = $this->segments;
                $hasPattern = $hasPattern || $this->segments !== null;
            } while (($this->tokens->types[$this->index] ?? null) === TokenType::Comma);

            if ($hasPattern && $operator !== '=' && $operator !== '!=') {
                $patternIndex = array_find_key(
                    $patterns,
                    static fn (null|array $pattern): bool => $pattern !== null,
                );

                throw ParseException::invalidWildcardPattern(
                    $this->position($starts[$patternIndex ?? 0]),
                );
            }

            if ($allowed instanceof AllowedField) {
                foreach ($values as $index => $item) {
                    if ($patterns[$index] === null && $allowed->isIgnored($item)) {
                        unset($values[$index], $quotes[$index]);
                    }
                }
            }

            if ($values === []) {
                return null;
            }

            // Keep written positions so index rules still apply to the right items.
            $this->validateValue($field, $values, $valueIndex);

            if (! $hasPattern) {
                return new InNode(
                    $field,
                    $operator,
                    array_values($values),
                    $this->span($start),
                    quoted: array_values($quotes),
                );
            }

            $left = null;
            $span = $this->span($start);

            foreach ($patterns as $index => $pattern) {
                if ($index > 0) {
                    if (++$this->clauses > $this->maxClauses) {
                        throw ParseException::maximumClauses(
                            $this->maxClauses,
                            $this->position($starts[$index]),
                        );
                    }
                }

                if (! array_key_exists($index, $values)) {
                    continue;
                }

                $node = $pattern === null
                    ? new ComparisonNode(
                        $field,
                        $operator,
                        $values[$index],
                        $span,
                        quoted: $quotes[$index],
                    )
                    : new WildcardNode($field, $operator, $pattern, $span);
                $left = $left === null
                    ? $node
                    : new BinaryOperatorNode($operator === '=' ? 'OR' : 'AND', $left, $node, $span);
            }

            return $left;
        }

        if ($segments !== null && $operator !== '=' && $operator !== '!=') {
            throw ParseException::invalidWildcardPattern($this->position($valueIndex));
        }

        if ($segments === null && $allowed?->isIgnored($value) === true) {
            return null;
        }

        $this->validateValue($field, $value, $valueIndex);

        return $segments === null
            ? new ComparisonNode($field, $operator, $value, $this->span($start), quoted: $quoted)
            : new WildcardNode($field, $operator, $segments, $this->span($start));
    }

    /**
     * @param null|array{string, string} $bounds
     *
     * @phpstan-impure
     */
    private function parseRange(
        string $field,
        int $start,
        null|AllowedField $allowed,
        null|array $bounds = null,
    ): null|RangeNode {
        $valueIndex = $this->index;

        if ($bounds !== null) {
            $this->index += $this->tokens->types[$this->index] === TokenType::Minus ? 2 : 1;
            $position = $this->position($valueIndex);
            $from = $this->parseRangeBound($field, $allowed, $bounds[0], $position);
            $to = $this->parseRangeBound(
                $field,
                $allowed,
                $bounds[1],
                $position + mb_strlen($bounds[0]) + 2,
            );
        } else {
            $this->index++;

            $from = $this->parseRangeBound($field, $allowed);
            $separator = $this->tokens->types[$this->index] ?? null;

            if (
                $separator !== TokenType::Word
                || $this->tokens->values[$this->index] !== 'TO'
            ) {
                throw ParseException::expectedToken(
                    'TO',
                    $separator->name ?? 'NULL',
                    $this->position(),
                );
            }

            $this->index++;

            $to = $this->parseRangeBound($field, $allowed);

            $this->match(TokenType::CloseBracket);
        }

        $ignoreFrom = $from !== null && $allowed?->isIgnored($from) === true;
        $ignoreTo = $to !== null && $allowed?->isIgnored($to) === true;

        if ($ignoreFrom && $ignoreTo) {
            return null;
        }

        $from = $ignoreFrom ? null : $from;
        $to = $ignoreTo ? null : $to;

        foreach ([$from, $to] as $bound) {
            if ($bound !== null) {
                $this->validateValue($field, $bound, $valueIndex);
            }
        }

        return new RangeNode($field, $from, $to, $this->span($start));
    }

    /**
     * Parse a range bound, returning null for an open `*` bound, which is neither cast nor validated.
     *
     * @phpstan-impure
     */
    private function parseRangeBound(
        string $field,
        null|AllowedField $allowed,
        null|string $value = null,
        null|int $position = null,
    ): null|bool|float|int|string {
        if ($value !== null) {
            if ($value === '*') {
                return null;
            }

            if (str_contains($value, '*')) {
                throw ParseException::invalidWildcardPattern($position);
            }

            return $allowed?->cast($value, false) ?? AllowedFieldItemRules::infer($value, false);
        }

        if (
            ($this->tokens->types[$this->index] ?? null) === TokenType::Word
            && $this->tokens->values[$this->index] === '*'
        ) {
            $this->index++;

            return null;
        }

        $start = $this->index;
        $value = $this->parseValue($field, $allowed, rangeChecked: true);

        if ($this->segments !== null) {
            throw ParseException::invalidWildcardPattern($this->position($start));
        }

        return $value;
    }

    /**
     * @phpstan-impure
     */
    private function parseTerm(int $depth): null|NodeInterface
    {
        // A factor is contiguous: a clause, a NOT over a factor, or a group including its parentheses.
        $leftStart = $this->index;
        $left = $this->parseFactor($depth);
        $leftEnd = $this->index - 1;

        while (true) {
            $type = $this->tokens->types[$this->index] ?? null;

            if ($type === TokenType::And) {
                $this->index++;
            } elseif (! match ($type) {
                TokenType::Word, TokenType::String, TokenType::Wildcard, TokenType::Not, TokenType::Minus, TokenType::OpenParenthesis => true,
                default => false,
            }) {
                break;
            }

            $rightStart = $this->index;
            $right = $this->parseFactor($depth);
            $left = $this->combine(
                'AND',
                $left,
                $leftStart,
                $leftEnd,
                $right,
                $rightStart,
                $this->index - 1,
            );
        }

        $this->extentStart = $leftStart;
        $this->extentEnd = $leftEnd;

        return $left;
    }

    /**
     * @phpstan-impure
     */
    private function parseValue(
        string $field,
        null|AllowedField $allowed,
        int $index = 0,
        bool $rangeChecked = false,
    ): bool|float|int|string {
        $type = $this->tokens->types[$this->index] ?? null;

        if (
            $type === null
            || (
                ! $rangeChecked
                && $this->hasDotRanges
                && $this->hasDots()
                && $this->rangeBounds() !== null
            )
        ) {
            throw ParseException::expectedValue($this->position());
        }

        $value = $this->tokens->values[$this->index];

        if ($type === TokenType::Word && $value === '*') {
            throw ParseException::invalidWildcardUsage($field, $this->position());
        }

        $quoted = $type === TokenType::String;

        if ($type === TokenType::Minus) {
            $next = $this->index + 1;

            if (
                ($this->tokens->types[$next] ?? null) !== TokenType::Word
                || $this->tokens->offsets[$next] !== $this->tokens->ends[$this->index]
            ) {
                throw ParseException::expectedValue($this->position());
            }

            $value .= $this->tokens->values[$next];

            $this->index++;
        } elseif (! match ($type) {
            TokenType::Word, TokenType::String, TokenType::Wildcard, TokenType::And, TokenType::Or, TokenType::Not => true,
            default => false,
        }) {
            throw ParseException::expectedValue($this->position());
        }

        $this->index++;

        $this->segments = ($type !== TokenType::String && str_contains($value, '*'))
            ? $this->wildcardSegments($value, $type) : null;

        return $allowed?->cast($value, $quoted, $index)
            ?? AllowedFieldItemRules::infer($value, $quoted);
    }

    private function position(null|int $index = null): int
    {
        return $this->tokens->offsets[$index ?? $this->index] ?? $this->tokens->length;
    }

    /**
     * Read an unquoted a..b candidate without consuming it; a leading minus must be adjacent.
     *
     * @return null|array{string, string}
     */
    private function rangeBounds(null|int $index = null): null|array
    {
        $index ??= $this->index;
        $start = $index;
        $negative = ($this->tokens->types[$index] ?? null) === TokenType::Minus;

        if ($negative) {
            $index++;

            if (($this->tokens->offsets[$index] ?? null) !== $this->tokens->ends[$start]) {
                return null;
            }
        }

        if (($this->tokens->types[$index] ?? null) !== TokenType::Word) {
            return null;
        }

        $parts = DotRange::split($this->tokens->values[$index]);

        return $parts === null ? null : [($negative ? '-' : '').$parts[0], $parts[1]];
    }

    private function span(int $start): Span
    {
        $offset = $this->tokens->offsets[$start];

        return new Span($offset, $this->tokens->ends[$this->index - 1] - $offset);
    }

    /**
     * @param array<int, bool|float|int|string>|bool|float|int|string $value
     */
    private function validateValue(
        string $field,
        array|bool|float|int|string $value,
        int $start,
    ): void {
        if (! $this->searchParser->validateField($field, $value)) {
            throw InvalidFieldValueException::invalidValue($field, $this->position($start));
        }
    }

    /**
     * @return list<string>
     */
    private function wildcardSegments(string $value, TokenType $type): array
    {
        if ($type === TokenType::Wildcard) {
            return [mb_substr($value, 0, -1), ''];
        }

        $segments = preg_split('/\*+/', $value);

        if ($segments === false) {
            throw ParseException::tokenizationFailed();
        }

        return $segments;
    }
}
