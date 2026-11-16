<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Query;

enum Operator: string
{
    case Between = 'between';
    case Equal = '=';
    case Exists = 'exists';
    case Greater = '>';
    case GreaterOrEqual = '>=';
    case In = 'in';
    case Less = '<';
    case LessOrEqual = '<=';
    case Like = 'like';
    case NotBetween = 'not between';
    case NotEqual = '!=';
    case NotExists = 'not exists';
    case NotIn = 'not in';
    case NotLike = 'not like';

    public function complement(): self
    {
        return match ($this) {
            self::Equal => self::NotEqual,
            self::NotEqual => self::Equal,
            self::Greater => self::LessOrEqual,
            self::GreaterOrEqual => self::Less,
            self::Less => self::GreaterOrEqual,
            self::LessOrEqual => self::Greater,
            self::In => self::NotIn,
            self::NotIn => self::In,
            self::Between => self::NotBetween,
            self::NotBetween => self::Between,
            self::Exists => self::NotExists,
            self::NotExists => self::Exists,
            self::Like => self::NotLike,
            self::NotLike => self::Like,
        };
    }
}
