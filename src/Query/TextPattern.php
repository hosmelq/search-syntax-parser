<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Query;

use HosmelQ\SearchSyntaxParser\AST\Node\WildcardNode;
use HosmelQ\SearchSyntaxParser\Validation\TextMatch;
use InvalidArgumentException;

final readonly class TextPattern
{
    /**
     * @var non-empty-list<string>
     */
    public array $segments;

    /**
     * @param list<string> $segments
     */
    public function __construct(array $segments)
    {
        if ($segments === []) {
            throw new InvalidArgumentException(
                'A text pattern requires at least one literal segment.',
            );
        }

        $this->segments = $segments;
    }

    public static function forMatch(string $value, TextMatch $match): self
    {
        return new self(match ($match) {
            TextMatch::Exact => [$value],
            TextMatch::Prefix => [$value, ''],
            TextMatch::Contains => ['', $value, ''],
            TextMatch::Words => throw new InvalidArgumentException(
                'Whole-word matching cannot be expressed with LIKE.',
            ),
        });
    }

    public static function fromWildcard(WildcardNode $node): self
    {
        return new self($node->getSegments());
    }

    public function toLike(string $escape = '!'): string
    {
        if (mb_strlen($escape) !== 1 || $escape === '%' || $escape === '_') {
            throw new InvalidArgumentException(
                'A LIKE escape must be one character other than % or _.',
            );
        }

        $replacements = [$escape => $escape.$escape, '%' => $escape.'%', '_' => $escape.'_'];

        return implode('%', array_map(
            static fn (string $segment): string => strtr($segment, $replacements),
            $this->segments,
        ));
    }
}
