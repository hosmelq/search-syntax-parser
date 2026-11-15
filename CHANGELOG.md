# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

- Require the `mbstring` extension and brick/math 0.14.4 or newer, up to 1.x, and drop doctrine/lexer, archtechx/enums, and thecodingmachine/safe.
- Rename `AllowedField` to `Field` and `AllowedFieldItemRules` to `FieldItemRules`.
- Require configured fields, return null when no filter or default remains, apply validated defaults, and remove ignored values.
- Preserve typed values, decimal strings, quoted text, and Unicode source positions, and compare members and ignored strings ignoring case.
- Add operator restrictions, aliases, dynamic fields, text matching, storage conversions, leading wildcard permissions, and query limits.
- Add date, enum, UUID, and ULID rules, including partial and relative dates.
- Add wildcard nodes, compact ranges, string serialization, and directives and sorts outside the filter tree.
- Add AST transformers and a shared clause compiler for adapters, which complements inequalities and keeps surrounding negation.
- Add `Query\Matcher` for in-memory matching, JSON descriptions of parsers, fields, and sorts, and `getAllowedFields()`.
- Throw `SearchSyntaxException` with grouped error reasons, and reject non-finite size bounds.
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
