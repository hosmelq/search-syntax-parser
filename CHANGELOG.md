# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

This release includes breaking changes to field permissions, ignored values, operators, wildcard AST nodes, exception inheritance, value typing, validation, default origin and serialization, nullable AST results, case-insensitive values, quoted field metadata, text match modes, storage conversions, query limits, leading wildcard permissions, dynamic field patterns, compact ranges, directives, query sorting, and the lexer and parser APIs.

### Added

- Add `AllowedField::directive()` for unique top-level scalar equality settings, resolved through aliases, casting, validation, and ignored values, with scalar defaults and separate `getDirectives()` and `getTypedDirectives()` results.
- Add framework-independent `Sorting\AllowedSort` and `Sorting\Sort`, `allowedSorts()`, `defaultSort()`, and `getSort()` for case-insensitive query sort keys, internal mappings, explicit directions, and default origin.
- Add positioned `DirectiveNotTopLevel`, `DuplicateDirective`, and `DirectiveInvalidClause` error reasons through `InvalidFieldOperatorException`; reserve `sort` when sorts are configured and report invalid sort values through `InvalidFieldValueException`.
- Add `AllowedField::allowLeadingWildcards()` and `minWildcardLength()` plus `SearchParser::allowLeadingWildcards()` for free text and unconfigured fields, checked before casting with positioned `LeadingWildcardNotAllowed` and `WildcardTooShort` error reasons.
- Add the framework-independent `Support\LikePattern` helper for escaping SQL LIKE literals, wildcard segments, and exact, prefix, or contains text modes with an explicit escape character.
- Add dynamic `.*` fields with mandatory regex or closure `keys()` rules, exact-name and longest-pattern precedence, full typed AST names, and suffix-preserving internal mappings; reject pattern defaults and invalid configuration.
- Accept inclusive `field:a..b` ranges with open `*` ends, numeric and date bounds, existing field rules, and bracket-form string output.
- Add opt-in `allowPartialDates()` for format-less date fields and array items, preserving whole-year and whole-month text.
- Add `withoutTime()` for format-less date fields and array items that store calendar dates, rejecting times, `now`, and bare relative offsets.
- Accept relative date anchors and single calendar or time offsets on every format-less date field, including comparisons, lists, ranges, and text defaults.
- Add framework-independent `DateValue`, `DatePrecision`, and `DateBounds` for precision intervals, request-time relative resolution, explicit offsets, DST-aware calendar boundaries, and half-open adapter bounds.
- Add `AllowedField::prefix()`, `contains()`, and `words()` with `TextMatch` metadata and per-value quoted overrides to exact matching.
- Add storage conversions through `AllowedField::scale()`, `map()`, and `toStorage()`, applied to comparison, list, and range values by the array adapter while preserving the AST and string output. Maps that store a string convert every value to exact text, reported by `storesText()`.
- Add `uuid()` and `ulid()` field factories and item rules with format validation and canonical letter case.
- Expose comparison and per-item list quotes with `isQuoted()`, `quoted` in array output, and preserved quotes in string output; add effective `match` metadata to every comparison and list array.
- Add `AllowedField::tryCast()` to probe typed values with alias resolution and validation, returning null for invalid input.
- Add `NodeTransformer` for selective AST rewriting and opt-in `NegationNormalFormTransformer` for set complements around positive leaves.
- Expose `isDefault()` on comparison, exists, list, and unary nodes, and `default: bool` on their array output.
- Add `allowAnyField()` to explicitly accept unconfigured fields while retaining rules, aliases, casting, and defaults for configured fields.
- Expose optional Unicode source spans on every AST node; parsed clauses retain their exact source extent and default-generated nodes have null spans.
- Add per-field comparison operator restrictions, clause restrictions, and scalar or deferred value aliases validated after resolution.
- Add `InvalidFieldOperatorException` and machine-readable `ErrorReason` values and parameters on all query exceptions, including an extensible constructor for adapters.
- Apply field defaults to the AST when their external field is absent, including null defaults for missing fields, scalar comparisons, and non-empty lists. Empty queries can build defaults alone.
- Add the built-in `string` adapter to serialize ASTs with explicit operators, quoting, external field names, wildcard patterns, and preserved grouping.
- Add `SearchSyntaxException` as the common runtime exception base, Unicode character positions on query errors, and external field accessors on field exceptions.
- Add `date()` field and array-item rules with strict calendar/time validation and optional exact formats, and `enum()` rules using backed enum values and types.
- Publish complete usage documentation under `docs/`, including adapter output, visitors, wildcard-to-LIKE conversion, defaults, errors, and query limits.

### Changed

- Keep directives and sorting out of filter ASTs and array output. Append explicit directives in configuration order and then the typed sort to string output, omitting defaults and supporting a null filter tree only for the string adapter when explicit settings remain.
- Reject leading wildcards by default, including list items and free-text terms; field violations use `InvalidFieldOperatorException` with the complete external name.
- Accept hour-precision ISO datetimes such as `2024-03-15T10` on format-less date fields.
- Require brick/math 0.14.4 or newer, up to 1.x.
- Match `in()` strings and `ignore()` values case-insensitively, returning canonical allowed members and rejecting duplicate allowed strings ignoring case; alias tokens remain case-sensitive.
- Accept backed enum values or case names ignoring case, prefer backing values over overlapping names, and reject ambiguous backing values or case names at configuration time.
- Reject wildcard patterns on fields with a storage conversion before alias resolution or value validation.
- Return null from `SearchParser::parse()` and `build()` when no constraint or default applies; `build()` still resolves the adapter but does not call it.
- Preserve field subclasses through late-bound factories and fluent `static` returns, with a final field constructor.
- Read string defaults like typed query text, resolving aliases and casting, and validate defaults like the equivalent query clause, reporting invalid defaults as configuration errors naming the field. Non-string defaults must already have the field's cast type.
- Omit default-origin clauses from string output; a tree containing only defaults serializes to an empty string.
- Define `nullable()` as allowing missing stored values, with complements including those values; document multi-valued inequality and list semantics.
- Reject field queries by default unless the field is configured with `allowedFields()` or `allowAnyField()` is enabled; bare terms remain available without configuration.
- Remove constraints matching `ignore()` values after aliases and casting, filter list items, and open ignored range bounds. Removed field mentions suppress defaults; queries with no constraints or applicable defaults return null.
- Represent open range bounds as null in AST and array output; quoted asterisks are literal bounds that are cast and validated.
- Preserve the quoted flag on terms through the AST, array output, and string round trips.
- Store token starts and ends in Unicode code points with one ASCII check per query, keeping negative value adjacency in the same coordinates.
- Recognize `AND`, `OR`, `NOT`, and the range separator `TO` only in uppercase; other casing is ordinary query text.
- Represent unquoted wildcard terms and field patterns with `WildcardNode`, add `visitWildcard()` to visitors, collapse wildcard runs, and keep quoted asterisks literal. A quoted prefix followed by `*` is a pattern.
- Expand lists containing patterns into OR for equality or AND for inequality, counting every expanded leaf toward the clause limit. Reject patterns in ordering comparisons and range bounds.
- Make `ParseException`, `InvalidFieldException`, and `InvalidFieldValueException` extend `SearchSyntaxException`; field exceptions no longer extend `InvalidArgumentException`.
- Preserve the raw query in `query()` and count its whitespace toward `maxLength()`, so error positions refer to the caller's exact input.
- Validate `default()` arguments immediately and reject anything other than null, a scalar, or a non-empty scalar list.
- Reduce the README to installation and project links, and exclude `docs/` from distribution archives.
- Replace the lexer with a single-pass tokenizer. `SearchLexer`, `Tokens`, `TokenType` and `Parser` are now internal, and `Parser::__construct()` takes the depth and clause limits.
- Preserve standalone term text and infer field values without converting leading-zero identifiers, overflowing integers, exponent notation, or wildcard text to numbers.
- Cast typed fields and array items from raw text, regardless of quotes. Decimal fields now output strings with their decimal places preserved, and `in()` fields return the matching allowed value.
- Validate typed values as written: `integer()` rejects decimal notation such as `1.0`, `decimal()` rejects exponent notation such as `1e2`, and untyped incomplete decimals such as `5.` and `.5` stay strings.
- Measure `string()` values by character length in size rules, including numeric-looking text; numbers and numeric strings in other fields are still compared by value. Values without a size, such as booleans, fail validation instead of throwing a `TypeError`.
- Limit queries to 10,000 characters, 32 levels of group/negation nesting and 1024 leaf clauses by default, configurable with `maxLength()`, `maxDepth()` and `maxClauses()`.
- Reject queries made only of connectives, repeated connectives such as `a OR OR b`, a bare `*`, and values starting with reserved punctuation such as `title:(a OR b)`.

### Fixed

- Preserve Unicode datetime-like field names when serializing query text so their colon cannot merge with the value during reparsing.
- Preserve Unicode, hyphenated words, punctuation and embedded wildcards.
- Support negative field values, list items and range bounds, including coordinate tuples.
- Validate both range bounds using the field rules; an unquoted `*` bound stays open-ended and is not validated.
- Apply `each()` size rules using each item's effective type, regardless of rule order.
- Treat `TO` as a separator only inside ranges and allow logical keywords as field values.
- Reject unterminated quotes, invalid UTF-8 and invalid value syntax with parse errors.
- Unescape quoted strings in a single pass, so an escaped backslash before a quote keeps the backslash, and accept escaped line breaks.
- Parse ISO datetimes without timezones and with fractional seconds.
- Prevent long, deeply nested or clause-heavy queries from crashing the process under the default limits.
- Correct the documented equivalence of not-equal lists to use AND.

### Removed

- Remove `ErrorReason::EmptyQuery` and `ParseException::emptyQuery()`.
- Remove unused token cases and `ParseException::expectedIdentifier()`.
- Remove the doctrine/lexer, archtechx/enums and thecodingmachine/safe runtime dependencies.

## v2.0.0 - 2026-09-23

- Require PHP 8.4 or newer.
- Require brick/math 0.14.2 or newer, up to 1.x.
- Cast floating-point validation inputs to string for brick/math 0.14.
- Reject unsupported membership values instead of raising casting errors.
- Update the Pint, Rector, PHPStan, and Pest configuration.
- Rework the GitHub Actions workflows and add GitHub Actions security analysis.

## v1.0.0 - 2025-09-11

- Initial release.
