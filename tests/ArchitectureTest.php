<?php

declare(strict_types=1);

arch()->preset()->php();
arch()->preset()->security();

arch('annotations')
    ->expect('HosmelQ\SearchSyntaxParser')
    ->toHaveMethodsDocumented()
    ->toHavePropertiesDocumented();

arch('strict types')
    ->expect('HosmelQ\SearchSyntaxParser')
    ->toUseStrictTypes();
