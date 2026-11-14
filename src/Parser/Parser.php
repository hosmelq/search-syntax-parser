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
    private int $clauses = 0;

    private int $index = 0;

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
    ) {}

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
    public function parse(string $input, bool $allowEmpty = false): null|NodeInterface
    {
        $this->tokens = new SearchLexer()->tokenize($input);
        $this->index = 0;
        $this->clauses = 0;
        $this->referencedFields = $allowEmpty ? [] : null;

        if ($this->tokens->types === []) {
            if ($allowEmpty) {
                return null;
            }

            throw ParseException::emptyQuery();
        }

        $ast = $this->parseExpression(0);
        $remaining = $this->tokens->values[$this->index] ?? null;

        if ($remaining !== null) {
            throw ParseException::unexpectedToken($remaining, $this->position());
        }

        return $ast;
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
        $start = $this->index;
        $left = $this->parseTerm($depth);

        while (($this->tokens->types[$this->index] ?? null) === TokenType::Or) {
            $this->index++;

            $right = $this->parseTerm($depth);
            $left = match (true) {
                ! $left instanceof NodeInterface => $right,
                ! $right instanceof NodeInterface => $left,
                default => new BinaryOperatorNode('OR', $left, $right, $this->span($start)),
            };
        }

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

        return $segments === null
            ? new TermNode($value, $type === TokenType::String, $this->span($start))
            : new WildcardNode(null, '=', $segments, $this->span($start));
    }

    /**
     * @phpstan-impure
     */
    private function parseFieldQuery(string $field, int $start): null|NodeInterface
    {
        if (! $this->searchParser->isFieldAllowed($field)) {
            throw InvalidFieldException::fieldNotAllowed(
                $field,
                $this->searchParser->getAllowedFieldNames(),
                $this->position($this->index - 1),
            );
        }

        if ($this->referencedFields !== null) {
            $this->referencedFields[$field] = true;
        }

        $allowed = $this->searchParser->getAllowedField($field);
        $hasColon = ($this->tokens->types[$this->index] ?? null) === TokenType::Colon;

        if ($hasColon) {
            $this->index++;

            if (($this->tokens->types[$this->index] ?? null) === TokenType::OpenBracket) {
                $allowed?->assertClauseAllowed('ranges', $this->position());

                return $this->parseRange($field, $start, $allowed);
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

        if ($allowed instanceof AllowedField && $allowed->hasUsageRules()) {
            $rulePosition = $this->position($operatorIndex);

            // Inspect the whole clause before any alias, cast, or value validator runs.
            $scan = $this->index;
            $exists = $hasColon
                && $operator === '='
                && ($this->tokens->types[$scan] ?? null) === TokenType::Word
                && $this->tokens->values[$scan] === '*';

            if ($exists) {
                $allowed->assertClauseAllowed('exists', $rulePosition);
            } else {
                $allowed->assertOperatorAllowed($operator, $rulePosition);

                do {
                    $type = $this->tokens->types[$scan] ?? null;
                    $patternIndex = $type === TokenType::Minus ? $scan + 1 : $scan;
                    $patternType = $this->tokens->types[$patternIndex] ?? null;

                    if ($patternType === TokenType::Wildcard
                        || (
                            $patternType === TokenType::Word
                            && (
                                $type === TokenType::Minus
                                || $this->tokens->values[$patternIndex] !== '*'
                            )
                            && str_contains($this->tokens->values[$patternIndex], '*')
                        )) {
                        $allowed->assertClauseAllowed('wildcards', $rulePosition);
                    }

                    $scan += $type === TokenType::Minus ? 2 : 1;

                    if (($this->tokens->types[$scan] ?? null) !== TokenType::Comma || ! $hasColon) {
                        break;
                    }

                    $allowed->assertClauseAllowed('lists', $rulePosition);

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
        $value = $this->parseValue($field, $allowed);
        $segments = $this->segments;

        if ($hasColon && ($this->tokens->types[$this->index] ?? null) === TokenType::Comma) {
            $values = [$value];
            $patterns = [$segments];
            $starts = [$valueIndex];
            $hasPattern = $segments !== null;

            do {
                $this->index++;

                $starts[] = $this->index;
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
                        unset($values[$index]);
                    }
                }
            }

            if ($values === []) {
                return null;
            }

            $this->validateValue($field, array_values($values), $valueIndex);

            if (! $hasPattern) {
                return new InNode($field, $operator, array_values($values), $this->span($start));
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
                    ? new ComparisonNode($field, $operator, $values[$index], $span)
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
            ? new ComparisonNode($field, $operator, $value, $this->span($start))
            : new WildcardNode($field, $operator, $segments, $this->span($start));
    }

    /**
     * @phpstan-impure
     */
    private function parseRange(
        string $field,
        int $start,
        null|AllowedField $allowed,
    ): null|RangeNode {
        $valueIndex = $this->index;

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
    ): null|bool|float|int|string {
        if (
            ($this->tokens->types[$this->index] ?? null) === TokenType::Word
            && $this->tokens->values[$this->index] === '*'
        ) {
            $this->index++;

            return null;
        }

        $start = $this->index;
        $value = $this->parseValue($field, $allowed);

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
        $start = $this->index;
        $left = $this->parseFactor($depth);

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

            $right = $this->parseFactor($depth);
            $left = match (true) {
                ! $left instanceof NodeInterface => $right,
                ! $right instanceof NodeInterface => $left,
                default => new BinaryOperatorNode('AND', $left, $right, $this->span($start)),
            };
        }

        return $left;
    }

    /**
     * @phpstan-impure
     */
    private function parseValue(
        string $field,
        null|AllowedField $allowed,
        int $index = 0,
    ): bool|float|int|string {
        $type = $this->tokens->types[$this->index] ?? null;

        if ($type === null) {
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

    private function span(int $start): Span
    {
        $offset = $this->tokens->offsets[$start];

        return new Span($offset, $this->tokens->ends[$this->index - 1] - $offset);
    }

    /**
     * @param bool|float|int|list<bool|float|int|string>|string $value
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
