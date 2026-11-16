---
title: "Installation"
description: "Install Search Syntax Parser with Composer, check its PHP requirements, and parse your first search query."
weight: 2
---

Install the package with Composer. It needs no framework configuration, service provider, or database.

## Requirements

Search Syntax Parser requires PHP 8.4 or later with the `mbstring` extension.

## Install the package

Install the package as a regular dependency:

```bash
composer require hosmelq/search-syntax-parser
```

Framework applications already load Composer's autoloader. In a standalone script, load it before using the parser:

```php
require_once 'vendor/autoload.php';

use HosmelQ\SearchSyntaxParser\SearchParser;

$result = SearchParser::query('coffee')->build();
```

The query returns:

```php
[
    'quoted' => false,
    'type' => 'term',
    'value' => 'coffee',
]
```

## Next steps

Read the [query syntax](basic-usage/query-syntax), then choose the [fields and value rules](basic-usage/field-validation-and-value-types) your application accepts.
