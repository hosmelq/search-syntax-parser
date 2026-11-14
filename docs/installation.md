---
title: "Installation"
description: "Install Search Syntax Parser with Composer on PHP 8.4 or newer and build your first structured search query."
weight: 2
---

The package requires PHP 8.4 or newer and installs through Composer.

```bash
composer require hosmelq/search-syntax-parser
```

Load Composer's autoloader in a standalone script. Framework applications normally load it for you.

```php
require_once 'vendor/autoload.php';

use HosmelQ\SearchSyntaxParser\SearchParser;

$result = SearchParser::query('coffee')->build();

assert($result === ['quoted' => false, 'type' => 'term', 'value' => 'coffee']);
```

Continue with [query syntax](basic-usage/query-syntax) or configure [field validation and value types](basic-usage/field-validation-and-value-types).
