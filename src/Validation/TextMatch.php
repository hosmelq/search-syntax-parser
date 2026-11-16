<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Validation;

enum TextMatch: string
{
    case Contains = 'contains';
    case Exact = 'exact';
    case Prefix = 'prefix';
    case Words = 'words';
}
