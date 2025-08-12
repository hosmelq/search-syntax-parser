<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Lexer;

use ArchTech\Enums\Comparable;
use ArchTech\Enums\Names;

enum TokenType: string
{
    use Comparable;
    use Names;

    case And = 'and';
    case CloseBracket = 'close_bracket';
    case CloseParenthesis = 'close_parenthesis';
    case Colon = 'colon';
    case Date = 'date';
    case Greater = 'greater';
    case GreaterEqual = 'greater_equal';
    case Identifier = 'identifier';
    case Less = 'less';
    case LessEqual = 'less_equal';
    case Minus = 'minus';
    case None = 'none';
    case Not = 'not';
    case NotEqual = 'not_equal';
    case Number = 'number';
    case OpenBracket = 'open_bracket';
    case OpenParenthesis = 'open_parenthesis';
    case Or = 'or';
    case String = 'string';
    case To = 'to';
    case Wildcard = 'wildcard';

    /**
     * Create TokenType from numeric value.
     */
    public static function fromValue(int $value): null|self
    {
        $cases = self::cases();

        return $cases[$value] ?? null;
    }

    /**
     * Get numeric value for Doctrine Lexer compatibility.
     */
    public function getValue(): int
    {
        $result = array_search($this, self::cases(), true);

        return $result !== false ? $result : 0;
    }
}
