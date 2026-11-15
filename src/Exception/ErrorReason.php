<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Exception;

enum ErrorReason: string
{
    case ExpectedToken = 'expected_token';
    case ExpectedValue = 'expected_value';
    case FieldNotAllowed = 'field_not_allowed';
    case InvalidFieldValue = 'invalid_field_value';
    case InvalidUtf8 = 'invalid_utf8';
    case InvalidWildcardPattern = 'invalid_wildcard_pattern';
    case InvalidWildcardUsage = 'invalid_wildcard_usage';
    case MaximumClauses = 'maximum_clauses';
    case MaximumDepth = 'maximum_depth';
    case MaximumLength = 'maximum_length';
    case OperatorNotAllowed = 'operator_not_allowed';
    case TokenizationFailed = 'tokenization_failed';
    case UnexpectedEndOfInput = 'unexpected_end_of_input';
    case UnexpectedToken = 'unexpected_token';
    case Unsupported = 'unsupported';
    case UnterminatedQuote = 'unterminated_quote';
}
