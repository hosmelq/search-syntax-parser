<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Date;

enum DatePrecision: string
{
    case Day = 'day';
    case Fraction = 'fraction';
    case Hour = 'hour';
    case Instant = 'instant';
    case Minute = 'minute';
    case Month = 'month';
    case Second = 'second';
    case Year = 'year';
}
