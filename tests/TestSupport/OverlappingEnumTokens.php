<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Tests\TestSupport;

enum OverlappingEnumTokens: string
{
    case Active = 'enabled';
    case Enabled = 'open';
}
