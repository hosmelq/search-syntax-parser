# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

This release includes breaking changes to value typing, validation, query limits and the lexer and parser APIs.

### Changed

- Replace the lexer with a single-pass tokenizer. `SearchLexer`, `Tokens`, `TokenType` and `Parser` are now internal, and `Parser::__construct()` takes the depth and clause limits.
- Preserve standalone term text and infer field values without converting leading-zero identifiers, overflowing integers, exponent notation, or wildcard text to numbers.
- Cast typed fields and array items from raw text, regardless of quotes. Decimal fields now output strings with their decimal places preserved, and `in()` fields return the matching allowed value.
- Validate typed values as written: `integer()` rejects decimal notation such as `1.0`, `decimal()` rejects exponent notation such as `1e2`, and untyped incomplete decimals such as `5.` and `.5` stay strings.
- Measure `string()` values by character length in size rules, including numeric-looking text; numbers and numeric strings in other fields are still compared by value. Values without a size, such as booleans, fail validation instead of throwing a `TypeError`.
- Limit queries to 10,000 characters, 32 levels of group/negation nesting and 1024 leaf clauses by default, configurable with `maxLength()`, `maxDepth()` and `maxClauses()`.
- Reject queries made only of connectives, repeated connectives such as `a OR OR b`, a bare `*`, and values starting with reserved punctuation such as `title:(a OR b)`.

### Fixed

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

- Remove the unused `AllowedField::default()`, `getDefault()`, and `hasDefault()` methods. Use `nullable()` explicitly when needed.
- Remove unused token cases and `ParseException::expectedIdentifier()`.
- Remove the doctrine/lexer and archtechx/enums runtime dependencies.

## v2.0.0 - 2026-09-23

- Require PHP 8.4 or newer.
- Require brick/math 0.14.2 or newer, up to 1.x.
- Cast floating-point validation inputs to string for brick/math 0.14.
- Reject unsupported membership values instead of raising casting errors.
- Update the Pint, Rector, PHPStan, and Pest configuration.
- Rework the GitHub Actions workflows and add GitHub Actions security analysis.

## v1.0.0 - 2025-09-11

- Initial release.
