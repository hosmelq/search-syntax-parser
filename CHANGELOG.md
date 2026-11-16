# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## v3.0.0 - 2026-11-15

- Require the `mbstring` extension and brick/math 0.14.4 or newer, up to 1.x, and drop doctrine/lexer, archtechx/enums, and thecodingmachine/safe.
- Rename `AllowedField` to `Field` and `AllowedFieldItemRules` to `FieldItemRules`.
- Require uniquely named configured fields, return null when no filter or default remains, apply validated defaults, and remove ignored values.
- Preserve typed values, canonical decimal strings, quoted text, and Unicode source positions, and compare members and ignored strings ignoring case.
- Add field authorization, operator restrictions, aliases, dynamic keys, presence fields, text matching, storage conversions, leading wildcard permissions, and query limits.
- Add date, enum, UUID, and ULID rules, including partial and relative dates, calendar-date semantics, clause intervals, and storage bounds.
- Add wildcard nodes, compact ranges, string serialization, and directives and sorts outside the filter tree.
- Add AST transformers, a shared clause compiler, and `compile()` clause trees with term targets, text patterns, directive clauses, and set complements for adapters.
- Add `Query\Matcher` for in-memory matching, JSON descriptions of parsers, fields, and sorts, and field metadata for adapters.
- Accept raw query input, throw `SearchSyntaxException` with grouped reasons, translation keys and parameters, and reject non-finite size bounds.
- Replace the lexer, make the lexer and parser internal, and remove unused validation APIs.
- Move the documentation from the README to `docs/`, excluded from archives, align the tooling, reorganize the tests, and drop the redundant framework CI job.

See [UPGRADE.md](UPGRADE.md) for upgrade instructions.

## v2.0.0 - 2026-09-23

- Require PHP 8.4 or newer.
- Require brick/math 0.14.2 or newer, up to 1.x.
- Cast floating-point validation inputs to string for brick/math 0.14.
- Reject unsupported membership values instead of raising casting errors.
- Update the Pint, Rector, PHPStan, and Pest configuration.
- Rework the GitHub Actions workflows and add GitHub Actions security analysis.

## v1.0.0 - 2025-09-11

- Initial release.
