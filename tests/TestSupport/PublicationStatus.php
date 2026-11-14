<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Tests\TestSupport;

enum PublicationStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}
