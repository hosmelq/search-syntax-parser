# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

- Require the `mbstring` extension and brick/math 0.14.4 or newer, up to 1.x.
- Require explicit field permissions and return null when no filters or defaults apply.
- Apply validated defaults to the AST and remove ignored values from queries.
- Preserve typed values, decimal strings, quoted text, and Unicode source positions.
- Match allowed members and ignored strings without regard to case.
- Add wildcard nodes, leading wildcard permissions, compact ranges, and query limits.
- Add operator restrictions, aliases, dynamic fields, text matching, and storage conversions.
- Add date, enum, UUID, and ULID rules, including partial dates and relative date bounds.
- Add directives and sorting as separate parser results.
- Add string serialization, AST transformers, and shared clause compilation for adapters.
- Compile parsed inequalities with their complemented operators and preserve surrounding negation.
- Use `SearchSyntaxException` and grouped error reasons for query errors.
- Reject non-finite size bounds as configuration errors.
- Rename `AllowedField` to `Field` and `AllowedFieldItemRules` to `FieldItemRules`.
- Replace the lexer, mark lexer and parser classes as internal, and remove unused validation APIs.
- Remove the doctrine/lexer, archtechx/enums, and thecodingmachine/safe runtime dependencies.
- Align development tooling, reorganize tests, and remove the redundant framework CI job.
- Update the documentation and exclude `/docs` from distribution archives.

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
