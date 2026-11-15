<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Tests\TestSupport;

enum AmbiguousEnumValues: string
{
    case First = 'Été';
    case Second = 'été';
}
