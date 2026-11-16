<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Tests\Fixtures;

enum AmbiguousEnumNames: int
{
    case Active = 1;
    case ACTIVE = 2;
}
