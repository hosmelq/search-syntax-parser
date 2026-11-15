# Upgrade Guide

## Upgrading to 3.0 from 2.x

### Runtime requirements

Enable the `mbstring` extension and allow brick/math 0.14.4 or newer in your dependency constraints.
PHP 8.4 remains the minimum supported version.

### Removed dependencies

The package no longer requires doctrine/lexer, archtechx/enums, or thecodingmachine/safe. Declare
these dependencies in your own application if you use them directly.

### Field classes

`Validation\AllowedField` is now `Validation\Field`, and `Validation\AllowedFieldItemRules` is now
`Validation\FieldItemRules`. Update imports, type declarations, and subclasses. Parser methods keep
their names, including `allowedFields()` and `getAllowedField()`.

### Field permissions

Field queries are rejected until their names are configured with `allowedFields()`. Bare terms
remain available without configuration:

```php
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\Field;

$parser = SearchParser::query('status:open')->allowedFields([
    Field::string('status'),
]);
```

Use `allowAnyField()` when your application intentionally accepts arbitrary field names.
Configured fields still use their validation and casting rules.

### Empty queries

`parse()` and `build()` return null when no filter or default remains. Replace handlers for
`ParseException::emptyQuery()` with a null check. Custom adapters are not called for an empty tree.

```php
$ast = SearchParser::query('')->parse();

if ($ast === null) {
    // Apply the application's behavior for an empty search.
}
```

### Field defaults

Defaults now become AST clauses when the field is absent. Remove any adapter code that applies
those defaults a second time. A null default produces a negated existence clause.

Pass null, a scalar, or a non-empty scalar list to `default()`. String defaults are cast and
validated as query values; other defaults must already have the field's type. Invalid defaults
throw `InvalidArgumentException` when applied. Decimal defaults should be strings:

```php
$field = Field::decimal('price', 2)->default('10.50');
```

Default clauses expose `isDefault()` and are omitted from string serialization. Preserve that
origin when transforming an AST.

### Ignored values

`ignore()` now removes matching clauses after casting and alias resolution. It filters list items
and turns ignored range bounds into open bounds. A removed mention still suppresses its field's
default. Review searches that previously relied on ignored values reaching an adapter.

Direct calls to `validate()` no longer bypass validation for ignored values. Use `isIgnored()`
when checking whether a value should be omitted.

### Typed values

Field and array-item rules now cast raw query text, including quoted values. Update custom
validation callbacks to accept the cast value.

### Decimal output

Decimal fields return strings with their decimal places preserved. Update adapter bindings and
strict comparisons that expect floats.

### Untyped values

Untyped values retain leading-zero identifiers, overflowing integers, exponent notation, and
incomplete decimals as strings. Standalone terms retain their written text. Use a typed field
when numeric conversion is required.

### Numeric validation

`integer()` rejects decimal notation such as `1.0`, and `decimal()` rejects exponent notation such
as `1e2`. Update query producers to send the notation required by the field.

### Membership and ignored strings

`in()` and `ignore()` compare strings without regard to case. Membership fields return the
canonical configured value. Remove allowed strings that differ only in letter case; ambiguous
configuration now throws `InvalidArgumentException`. Use a callback rule if matching must remain
case-sensitive.

### Size validation

Size rules on `string()` fields measure Unicode character length, including numeric-looking text.
Numeric fields compare numeric values. Shared array-item size rules use the effective type of each
position. Review rules that relied on numeric strings being measured by value in string fields.

Booleans fail size rules.

### Finite size bounds

Bounds passed to `min()`, `max()`, `between()`, and `size()` must be finite;
`INF`, `-INF`, and `NAN` throw `InvalidArgumentException`. Omit an unneeded bound instead of using
infinity.

### Wildcard visitors

Unquoted wildcard values now produce `WildcardNode` objects. Add `visitWildcard(WildcardNode $node)`
to custom visitors and handle the `wildcard` type in array consumers. Literal segments are available
through `getSegments()`; quoted asterisks remain literal text.

Lists containing patterns expand into OR groups for equality and AND groups for inequality.
Traverse those groups instead of assuming every comma-separated value produces an `InNode`.
Patterns are rejected in ordering comparisons and range bounds.

### Leading wildcards

Leading wildcards are rejected by default. Enable them on each configured field that needs them:

```php
$field = Field::string('title')->allowLeadingWildcards();
```

Use `SearchParser::allowLeadingWildcards()` for bare terms and unconfigured fields. That setting
does not override a configured field's permissions.

### Open ranges

Open range bounds are null in `RangeNode` and array output, replacing the string `'*'`. Update
adapters and code that constructs range nodes. A quoted `"*"` is a literal bound and must pass the
field's casting and validation rules. Both written bounds are now validated.

### Negation and missing values

Treat inequality as a set complement. For a multi-valued target, `status:!=sold,expired` excludes
records matching either member; it is not an OR of separate inequalities. Complements include
missing stored values when the field is `nullable()`.

Update custom adapters to preserve this behavior. `NegationNormalFormTransformer` and
`Query\ClauseCompiler` can prepare positive leaves and their complements without replacing a
negated comparison with a plain database predicate that drops null values.

### Query limits

Queries are limited to 10,000 characters, 32 levels of group or negation nesting, and 1,024 leaf
clauses. Set `maxLength()`, `maxDepth()`, and `maxClauses()` explicitly if your application needs
higher limits. Leading and trailing whitespace count toward the length limit.

### Query syntax

Write `AND`, `OR`, `NOT`, and the range separator `TO` in uppercase. Other casing is ordinary text.
Queries containing only connectives, repeated connectives, a bare `*`, invalid UTF-8, unterminated
quotes, or invalid value syntax now fail instead of being partially accepted. Update query
producers and stored queries that use those forms.

### Custom AST nodes

Implement `getSpan(): ?Span` on custom `NodeInterface` classes. Return null for nodes without a
source location. Offsets and lengths use Unicode characters in the original, untrimmed query.

### Array output metadata

Array output adds `default` to comparison, existence, membership, and unary nodes, `quoted` to
terms, comparisons, and membership items, and `match` to comparisons and membership items. Update
strict array schemas and preserve quote flags when rebuilding nodes.

### Query exceptions

Catch `SearchSyntaxException` for all query failures. Field exceptions no longer extend
`InvalidArgumentException`; that exception now identifies configuration errors.

```php
use HosmelQ\SearchSyntaxParser\Exception\SearchSyntaxException;

try {
    $result = $parser->build();
} catch (SearchSyntaxException $exception) {
    $reason = $exception->getReason()->value;
    $parameters = $exception->getParameters();
    $position = $exception->getPosition();
}
```

Use dotted reason codes such as `field.invalid_value` and `syntax.expected_value` for error
handling and translations. Update custom exception construction to supply the new reason,
parameters, and position arguments. `ParseException::emptyQuery()` and `expectedIdentifier()`
have been removed.

### Dynamic field names

Names ending in `.*` now configure dynamic fields. Supply a `keys()` rule and keep any internal
mapping's `.*` suffix. Pattern fields cannot have defaults. Rename existing literal fields that
end in `.*` if they are not intended to match dynamic keys.

### Field subclasses

`Field` has a final constructor, and its factories and fluent methods return `static`.
Remove constructor overrides and update overridden return types. The protected validator arrays
have been replaced with `FieldItemRules`; configure rules through the field's public methods.

### Validation helpers

`FieldItemRules::getValidators()` has been removed. Use `validate()` to evaluate item rules.
The `ValidatesAttributes` trait no longer has `requireParameterCount()` or `trim()`, its validation
methods no longer take an attribute argument, and `getSize()` returns `BigNumber` or null. Update
custom validators that use those protected helpers.

### Lexer and parser internals

Use `SearchParser` as the parsing entry point. `SearchLexer`, `TokenType`, `Tokens`, and `Parser`
are internal; their token model, constructors, and methods no longer follow the v2 API. Remove
direct integrations with the old lexer and token cases.
