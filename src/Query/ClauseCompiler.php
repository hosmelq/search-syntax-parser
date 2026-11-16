<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Query;

use HosmelQ\SearchSyntaxParser\AST\Node\ComparisonNode;
use HosmelQ\SearchSyntaxParser\AST\Node\ExistsNode;
use HosmelQ\SearchSyntaxParser\AST\Node\InNode;
use HosmelQ\SearchSyntaxParser\AST\Node\RangeNode;
use HosmelQ\SearchSyntaxParser\AST\Node\TermNode;
use HosmelQ\SearchSyntaxParser\AST\Node\UnaryOperatorNode;
use HosmelQ\SearchSyntaxParser\AST\Node\WildcardNode;
use HosmelQ\SearchSyntaxParser\AST\Visitor\NegationNormalFormTransformer;
use HosmelQ\SearchSyntaxParser\Exception\InvalidFieldOperatorException;
use HosmelQ\SearchSyntaxParser\Support\FullText;
use HosmelQ\SearchSyntaxParser\Validation\Field;
use HosmelQ\SearchSyntaxParser\Validation\TextMatch;

final readonly class ClauseCompiler
{
    /**
     * Compile parsed leaves; $negated represents a surrounding NOT and combines with leaf inequality.
     * Multiple clauses form an OR group, or an AND group when their negated flag is true.
     *
     * @return list<Clause|ClauseGroup>
     */
    public function compile(
        Field $field,
        ComparisonNode|ExistsNode|InNode|RangeNode|WildcardNode $node,
        bool $negated = false,
    ): array {
        $node = new NegationNormalFormTransformer()->transform($node);

        if ($node instanceof UnaryOperatorNode) {
            $node = $node->getOperand();
            $negated = ! $negated;
        }

        /** @var ComparisonNode|ExistsNode|InNode|RangeNode|WildcardNode $node */
        if ($node instanceof InNode && ($field->getTextMatch() === TextMatch::Words || array_any(
            array_keys($node->getValues()),
            static fn (int $index): bool
                => $field->getTextMatch($node, $index) !== TextMatch::Exact,
        ))) {
            $clauses = [];

            foreach ($node->getValues() as $index => $value) {
                $clauses[] = $this->clause($field, new ComparisonNode(
                    $node->getField(),
                    '=',
                    $value,
                    $node->getSpan(),
                    $node->isDefault(),
                    $node->isQuoted($index),
                ), $negated, $node->getPositions()[$index]);
            }

            return $clauses;
        }

        return [$this->clause($field, $node, $negated)];
    }

    /**
     * A term field participates only when its own rules accept the equivalent field clause.
     * Combine eligible fields with OR, or AND when negated; no matches means false, or true when negated.
     */
    public function term(
        Field $field,
        TermNode|WildcardNode $node,
        bool $negated = false,
    ): null|Clause|ClauseGroup {
        $name = $field->getName();
        $position = $node->getSpan()?->offset;

        try {
            $field->assertOperatorAllowed('=', $position ?? 0, $name);
        } catch (InvalidFieldOperatorException) {
            return null;
        }

        if ($node instanceof WildcardNode) {
            if (! $field->isText()) {
                return null;
            }

            $segments = $node->getSegments();

            try {
                $field->assertClauseAllowed('wildcards', $position ?? 0, $name);
                $field->assertWildcardAllowed(
                    $segments[0] === '',
                    mb_strlen(implode('', $segments)),
                    $position ?? 0,
                    $name,
                    count($segments) === 2 && $segments[0] !== '' && $segments[1] === '',
                );
            } catch (InvalidFieldOperatorException) {
                return null;
            }

            return $this->normalize(new Clause(
                $name,
                $negated ? Operator::NotLike : Operator::Like,
                TextPattern::fromWildcard($node),
                $position,
                $negated,
                true,
                $field->getTextMatch() === TextMatch::Words
                    ? new FullText(implode(' ', $segments), prefix: true)
                    : null,
            ));
        }

        $value = $field->tryCast($node->getValue(), $node->isQuoted());

        if ($value === null) {
            return null;
        }

        if ($field->isPresence()) {
            return $this->clause(
                $field,
                new ComparisonNode($name, '=', $value, $node->getSpan()),
                $negated,
            );
        }

        $value = $field->toStorage($value);
        $text = $field->comparesText($value);
        $words = $field->getTextMatch() === TextMatch::Words;
        $operator = $text && ! $words ? Operator::Like : Operator::Equal;

        return $this->normalize(new Clause(
            $name,
            $negated ? $operator->complement() : $operator,
            $text && ! $words
                ? TextPattern::forMatch((string) $value, TextMatch::Contains)
                : $value,
            $position,
            $negated,
            $text,
            $words ? new FullText((string) $value, $node->isQuoted()) : null,
        ));
    }

    private function clause(
        Field $field,
        ComparisonNode|ExistsNode|InNode|RangeNode|WildcardNode $node,
        bool $negated,
        int $index = 0,
    ): Clause|ClauseGroup {
        if ($field->isPresence() && ($node instanceof ComparisonNode || $node instanceof InNode)) {
            return $this->presence($node, $negated);
        }

        $text = false;
        $fullText = null;

        if ($node instanceof ComparisonNode) {
            $operator = Operator::from($node->getOperator());
            $value = $field->toStorage($node->getValue(), $index);
            $text = $field->comparesText($value);
            $match = $field->getTextMatch($node);

            if ($field->getTextMatch() === TextMatch::Words && $operator === Operator::Equal) {
                $fullText = new FullText((string) $value, $node->isQuoted());
            } elseif ($text && $operator === Operator::Equal && $match !== TextMatch::Exact) {
                $operator = Operator::Like;
                $value = TextPattern::forMatch((string) $value, $match);
            }
        } elseif ($node instanceof InNode) {
            $operator = Operator::In;
            $value = array_map($field->toStorage(...), $node->getValues(), $node->getPositions());
            $text = array_all($value, $field->comparesText(...));
        } elseif ($node instanceof RangeNode) {
            $operator = $node->getFrom() === null && $node->getTo() === null
                ? Operator::Exists
                : Operator::Between;
            $from = $node->getFrom() === null ? null : $field->toStorage($node->getFrom());
            $to = $node->getTo() === null ? null : $field->toStorage($node->getTo());
            $value = $operator === Operator::Exists ? null : new Bounds($from, $to);
            $text = $field->comparesText($from ?? $to ?? 0);
        } elseif ($node instanceof WildcardNode) {
            $operator = Operator::Like;
            $value = TextPattern::fromWildcard($node);
            $text = true;

            if ($field->getTextMatch() === TextMatch::Words) {
                $fullText = new FullText(implode(' ', $node->getSegments()), prefix: true);
            }
        } else {
            $operator = Operator::Exists;
            $value = null;
        }

        return $this->normalize(new Clause(
            $node->getField() ?? $field->getName(),
            $negated ? $operator->complement() : $operator,
            $value,
            $node->getSpan()?->offset,
            $negated,
            $text,
            $fullText,
        ));
    }

    private function normalize(Clause $clause): Clause|ClauseGroup
    {
        return $clause->fullText?->words === []
            ? new ClauseGroup($clause->negated, [])
            : $clause;
    }

    private function presence(ComparisonNode|InNode $node, bool $negated): Clause|ClauseGroup
    {
        $values = $node instanceof InNode ? $node->getValues() : [$node->getValue()];

        if (in_array(true, $values, true) && in_array(false, $values, true)) {
            return new ClauseGroup(! $negated, []);
        }

        $negated = $negated !== ($values[0] === false);

        return new Clause(
            $node->getField(),
            $negated ? Operator::NotExists : Operator::Exists,
            null,
            $node->getSpan()?->offset,
            $negated,
        );
    }
}
