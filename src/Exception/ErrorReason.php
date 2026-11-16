<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Exception;

enum ErrorReason: string
{
    case DirectiveInvalidClause = 'directive.invalid_clause';
    case DirectiveNotTopLevel = 'directive.not_top_level';
    case DuplicateDirective = 'directive.duplicate';
    case ExpectedToken = 'syntax.expected_token';
    case ExpectedValue = 'syntax.expected_value';
    case FieldNotAllowed = 'field.not_allowed';
    case InvalidFieldValue = 'field.invalid_value';
    case InvalidType = 'query.invalid_type';
    case InvalidUtf8 = 'query.invalid_utf8';
    case InvalidWildcardPattern = 'wildcard.invalid_pattern';
    case InvalidWildcardUsage = 'wildcard.invalid_usage';
    case LeadingWildcardNotAllowed = 'wildcard.leading_not_allowed';
    case MaximumClauses = 'query.maximum_clauses';
    case MaximumDepth = 'query.maximum_depth';
    case MaximumLength = 'query.maximum_length';
    case OperatorNotAllowed = 'field.operator_not_allowed';
    case TokenizationFailed = 'query.tokenization_failed';
    case UnexpectedEndOfInput = 'syntax.unexpected_end_of_input';
    case UnexpectedToken = 'syntax.unexpected_token';
    case UnterminatedQuote = 'syntax.unterminated_quote';
    case WildcardTooShort = 'wildcard.too_short';
    case WordWildcardNotAllowed = 'wildcard.word_not_allowed';
}
