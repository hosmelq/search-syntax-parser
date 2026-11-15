---
title: "Directives and Sorting"
description: "Extract typed query settings and mapped sort keys separately from filters, with placement rules and optional defaults."
weight: 5
---

Directives hold settings for the whole search. They are separate from the filter tree; your application or adapter decides how to apply them.

## Declare a directive

Call `directive()` on an exact scalar field:

```php
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\Field;

$parser = SearchParser::query('coffee trashed:ONLY limit:25')->allowedFields([
    Field::in('trashed', ['only', 'with', 'without'])->directive()->default('without'),
    Field::integer('limit')->directive()->min(1)->max(100),
]);

$filters = $parser->build();
$settings = $parser->getDirectives();
```

`$filters` contains the term `coffee`. `$settings` contains `['trashed' => 'only', 'limit' => 25]`.

Aliases, casts, validation, and ignored values work as they do on filter fields. Results use public field names and cast values; internal names and storage conversions do not change them. Numeric names follow PHP's array-key conversion rules.

`getDirectives()` returns resolved values, including defaults. `getTypedDirectives()` returns explicitly supplied, non-ignored values in configuration order. Both read the latest parse or build result without parsing again.

Directive defaults must be scalar. Array fields, pattern fields, null defaults, and list defaults are configuration errors. An ignored explicit value suppresses its default. See [field defaults](defaults#set-directive-and-sort-defaults).

## Place directives in the query

Each directive may appear once and must use a single equality value, such as `limit:25`. Lists, ranges, existence checks, wildcard patterns, and other comparison operators are rejected before value rules run. A quoted asterisk is literal text and is allowed if the field accepts it.

Every ancestor of a directive must be AND. Parentheses around AND groups are allowed:

| Query | Result |
| --- | --- |
| `(coffee AND (limit:25))` | Accepted. |
| `(coffee OR tea) limit:25` | Accepted. |
| `coffee OR limit:25` | Rejected. |
| `NOT limit:25` | Rejected. |
| `limit:10 limit:25` | Rejected. |

Ignored directives still count toward placement and duplicate checks. Double negation and ignored operands do not make an invalid placement valid.

Placement, duplicate, and clause-shape errors use `InvalidFieldOperatorException` with `directive.not_top_level`, `directive.duplicate`, or `directive.invalid_clause`. Invalid values use `InvalidFieldValueException`. See [error handling](error-handling).

## Allow sort keys

Declare sorts separately with `AllowedSort`. A string is shorthand for a sort whose public and internal names are equal:

```php
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Sorting\AllowedSort;

$parser = SearchParser::query('coffee sort:CREATED-AT-desc')->allowedSorts([
    AllowedSort::field('created-at', 'created_at')->defaultDirection('desc'),
    'title',
])->defaultSort('title');

$parser->parse();
$sort = $parser->getSort();
```

The sort has public name `created-at`, internal name `created_at`, and direction `desc`. Its `isDefault()` flag is false.

Use `sort:created-at` for the declaration's default direction, or append `-asc` or `-desc`. Keys and directions ignore case. The initial default direction is `asc`.

Only the final hyphen can separate a direction. A valid direction suffix takes precedence over a full key, so declaring both `created` and `created-desc` is a configuration error. Empty names, duplicate names ignoring case, and invalid configured directions are also rejected.

`getSort()` returns a `Sort` or null. Read `getName()`, `getInternalName()`, `getDirection()`, and `isDefault()` after parsing. `defaultSort()` uses the same key-and-direction syntax; an invalid default throws `InvalidArgumentException` when applied.

## Use one sort per query

`sort:` follows the same placement and scalar equality rules as directives. Unknown keys or directions throw `InvalidFieldValueException` for `sort`, positioned at its value.

Configuring sorts reserves the field name `sort`, ignoring case. Conflicting field declarations are rejected in either configuration order. Without configured sorts, `sort` is an ordinary field subject to field permissions.

Directive values and sorting resolve after the filter tree has been parsed. A filter error can therefore be reported before an invalid setting elsewhere in the query.

## Serialize settings

Array output and `parse()` contain only filters. A query with only settings has a null filter tree, while its directives and sort remain available through their accessors. Custom adapters are not called for that tree.

The string adapter writes explicit directives in configuration order, followed by the sort. It joins them with AND and groups an OR filter root when needed:

```php
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Sorting\AllowedSort;
use HosmelQ\SearchSyntaxParser\Validation\Field;

$parser = SearchParser::query('sort:created-desc trashed:only')
    ->allowedFields([Field::string('trashed')->directive()->default('without')])
    ->allowedSorts([AllowedSort::field('created', 'created_at')]);

$text = $parser->build('string');
```

The result is `trashed:only AND sort:created-desc`. Reapply the same declarations when parsing this text.

Default settings and ignored mentions are omitted. Parsing the serialized text with defaults can therefore restore a default that an ignored directive originally suppressed.

## Describe available settings

`AllowedSort` implements `JsonSerializable` and exports its public `name` and default `direction`. Internal names stay private. `getAllowedSorts()` returns the configured sort definitions; `getAllowedFields()` returns field definitions keyed by public name, including directives and normalized string declarations.

```php
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Sorting\AllowedSort;
use HosmelQ\SearchSyntaxParser\Validation\Field;

$parser = SearchParser::query('')
    ->allowedFields([Field::in('mode', ['active', 'all'])->directive()])
    ->allowedSorts([AllowedSort::field('created', 'created_at')->defaultDirection('desc')]);

$json = json_encode([
    'fields' => $parser->getAllowedFields(),
    'sorts' => $parser->getAllowedSorts(),
], JSON_THROW_ON_ERROR);
```

The `created` sort serializes as `{"direction":"desc","name":"created"}`. These accessors describe configuration without parsing. See [field descriptions](field-validation-and-value-types#describe-fields) for the field export keys.
