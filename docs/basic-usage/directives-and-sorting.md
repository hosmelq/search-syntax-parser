---
title: "Directives and sorting"
description: "Extract typed query settings and mapped sort keys separately from filters, with strict placement, defaults, and normalized text output."
weight: 5
---

Directives describe settings for the whole search. They are separate from the filter AST and have no framework-specific behavior. An adapter or application decides how to apply them.

## Declaring directives

Call `directive()` on an exact scalar `AllowedField`. The usual aliases, casts, validation, and ignored values apply. Public field names identify the results; internal field mappings and storage conversions do not change directive values.

```php
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;

$parser = SearchParser::query('coffee trashed:ONLY limit:25')->allowedFields([
    AllowedField::in('trashed', ['only', 'with', 'without'])->directive()->default('without'),
    AllowedField::integer('limit')->directive()->min(1)->max(100),
]);

$filters = $parser->build();
assert($filters['type'] === 'term');
assert($filters['value'] === 'coffee');
assert($parser->getDirectives() === ['trashed' => 'only', 'limit' => 25]);
assert($parser->getTypedDirectives() === ['trashed' => 'only', 'limit' => 25]);
```

`getDirectives()` returns resolved scalar values, including defaults. `getTypedDirectives()` returns only explicitly supplied, non-ignored directives, in configuration order. Both read the latest parse/build result without parsing or resolving aliases again. Values can be booleans, floats, integers, or strings. Numeric public names become integer array keys according to PHP's array rules.

A directive default must be a scalar. Null and list defaults, array fields, and dynamic pattern fields cannot be directives and throw `InvalidArgumentException`. Defaults use the same alias, cast, and validation rules as field defaults, bypassing usage restrictions and ignored values. A typed value removed by `ignore()` still suppresses its default.

```php
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;

$field = AllowedField::in('trashed', ['only', 'without'])
    ->directive()->alias('all', 'without')->ignore('any')->default('without');
$parser = SearchParser::query('')->allowedFields([$field]);
assert($parser->parse() === null);
assert($parser->getDirectives() === ['trashed' => 'without']);
assert($parser->getTypedDirectives() === []);

$ignored = SearchParser::query('trashed:any')->allowedFields([$field]);
assert($ignored->parse() === null);
assert($ignored->getDirectives() === []);
```

## Placement and clause rules

Every ancestor of a directive must be `AND`. Parenthesized AND groups are allowed, for example `(coffee AND (trashed:only))`. A directive cannot occur below `OR`, `NOT`, or `-`, including double negation or an OR whose other operand is ignored. A filter-only OR group beside a directive is valid: `(coffee OR tea) trashed:only`.

Each directive may occur once and must be a single equality comparison, written `field:value`. Lists, ranges, wildcard patterns, existence checks, `!=`, and ordering comparisons are rejected before aliases, casts, or value validators run. Quotes can make syntax characters literal: `trashed:"*"` is a scalar string if its field rules accept it. Ignored directives still count toward duplicates and placement rules.

These errors use `InvalidFieldOperatorException`: `DirectiveNotTopLevel`, `DuplicateDirective`, and `DirectiveInvalidClause`. Placement and duplicate positions point to the field token; invalid clause positions point to the operator or value start. Invalid resolved values use `InvalidFieldValueException` at the value token. See [error handling](error-handling).

## Sort keys and directions

Configure sorts independently of filter fields with `Sorting\AllowedSort`. A string declaration is shorthand for a sort with the same public and internal name. `defaultDirection()` accepts `asc` or `desc`, ignoring case; the initial direction is `asc`.

```php
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Sorting\AllowedSort;

$parser = SearchParser::query('coffee sort:CREATED-AT-desc')->allowedSorts([
    AllowedSort::field('created-at', 'created_at')->defaultDirection('desc'),
    'title',
])->defaultSort('title');

assert($parser->build('string') === 'coffee AND sort:created-at-desc');
$sort = $parser->getSort();
assert($sort->getName() === 'created-at');
assert($sort->getInternalName() === 'created_at');
assert($sort->getDirection() === 'desc');
assert($sort->isDefault() === false);
```

`sort:created-at` uses that declaration's default direction. `sort:created-at-asc` and `sort:created-at-desc` set an explicit direction. Keys and directions match ignoring case, and only the final hyphen separates a direction from a hyphenated key. Because a valid direction suffix always takes precedence, declaring both `created` and `created-desc` throws `InvalidArgumentException`: `sort:created-desc` could never reach the second one with its own default direction. Directive values and the sort are resolved after the whole query is parsed, so an error in a filter elsewhere in the query can be reported before an invalid directive value or sort.

There may be one `sort:` clause, subject to the same placement and scalar equality rules as directives. Unknown keys and directions throw `InvalidFieldValueException` with field `sort` and the value's position. Configuring sorts reserves the field name `sort`, ignoring case: `allowedFields()` and `allowedSorts()` reject a conflicting field declaration in either configuration order. Without configured sorts, `sort` follows ordinary field permissions.

`defaultSort('created-at-desc')` applies only if no sort was typed. Its argument uses the same key/direction syntax, and a bare key uses its configured default direction. `getSort()` returns a `Sorting\Sort` or null; the result exposes `getName()`, `getInternalName()`, `getDirection()`, and `isDefault()`. Invalid default sorts throw `InvalidArgumentException` when applied. Duplicate sort declarations ignoring case and invalid configured default directions are configuration errors too.

## Output and empty filters

`parse()` and array output contain only filters. A query containing only directives or a sort returns a null tree while its settings remain available. Custom adapters are not called for a null tree. The string adapter is the exception: it accepts a null filter tree and renders explicit settings, appending directives in configuration order and then `sort:key-direction` with top-level AND joins. It groups an OR filter root before appending settings.

```php
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Sorting\AllowedSort;
use HosmelQ\SearchSyntaxParser\Validation\AllowedField;

$configure = static fn (string $query): SearchParser => SearchParser::query($query)
    ->allowedFields([AllowedField::string('trashed')->directive()->default('without')])
    ->allowedSorts([AllowedSort::field('created', 'created_at')]);
$parser = $configure('sort:created-desc trashed:only');
$text = $parser->build('string');
assert($text === 'trashed:only AND sort:created-desc');
assert($parser->build() === null);

$reparsed = $configure($text);
assert($reparsed->parse() === null);
assert($reparsed->getDirectives() === $parser->getDirectives());
assert($reparsed->getSort() == $parser->getSort());
```

Directive defaults and the default sort are omitted from string output. With only default settings and no filters, `build('string')` returns null; an existing filter tree containing only default filters still serializes to `''`. Reapply the same schema when reparsing. As with ignored filters, serialization omits ignored directive mentions, so reparsing can restore a default that an ignored mention originally suppressed.
