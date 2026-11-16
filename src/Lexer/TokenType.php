<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Lexer;

/**
 * @internal
 */
enum TokenType: string
{
    case And = 'and';
    case CloseBracket = 'close_bracket';
    case CloseParenthesis = 'close_parenthesis';
    case Colon = 'colon';
    case Comma = 'comma';
    case Greater = 'greater';
    case GreaterEqual = 'greater_equal';
    case Less = 'less';
    case LessEqual = 'less_equal';
    case Minus = 'minus';
    case Not = 'not';
    case NotEqual = 'not_equal';
    case OpenBracket = 'open_bracket';
    case OpenParenthesis = 'open_parenthesis';
    case Or = 'or';
    case String = 'string';
    case Wildcard = 'wildcard';
    case Word = 'word';
}
