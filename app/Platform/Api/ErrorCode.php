<?php

namespace App\Platform\Api;

/**
 * Stable error codes of the API (API-TR-12). Every code must be listed in the table of
 * .docs/API-CONTRACT.md before use, and has a message in lang/{en,id}/errors.php under `codes.<CODE>`.
 */
enum ErrorCode: string
{
    case SourceTransactionConflict = 'SOURCE_TRANSACTION_CONFLICT';
    case IdempotencyKeyReused = 'IDEMPOTENCY_KEY_REUSED';
    case RequestInProgress = 'REQUEST_IN_PROGRESS';
    case VersionConflict = 'VERSION_CONFLICT';
    case InvalidStateTransition = 'INVALID_STATE_TRANSITION';
    case PeriodNotOpen = 'PERIOD_NOT_OPEN';
    case AccountNotPostable = 'ACCOUNT_NOT_POSTABLE';
    case PostingSourceNotAllowed = 'POSTING_SOURCE_NOT_ALLOWED';
    case DimensionRequired = 'DIMENSION_REQUIRED';
    case DimensionInvalid = 'DIMENSION_INVALID';
    case UnbalancedEntry = 'UNBALANCED_ENTRY';
    case ExchangeRateNotFound = 'EXCHANGE_RATE_NOT_FOUND';
    case TransactionTypeVersionRetired = 'TRANSACTION_TYPE_VERSION_RETIRED';
    case MappingRequired = 'MAPPING_REQUIRED';
    case TransactionTypeNotAllowed = 'TRANSACTION_TYPE_NOT_ALLOWED';
    case SegregationOfDuties = 'SEGREGATION_OF_DUTIES';
    case TwoFactorRequired = 'TWO_FACTOR_REQUIRED';
    case Unauthenticated = 'UNAUTHENTICATED';
    case Forbidden = 'FORBIDDEN';
    case NotFound = 'NOT_FOUND';
    case CsrfTokenMismatch = 'CSRF_TOKEN_MISMATCH';
    case TooManyRequests = 'TOO_MANY_REQUESTS';
    case ServerError = 'SERVER_ERROR';

    public function status(): int
    {
        return match ($this) {
            self::SourceTransactionConflict => 409,
            self::IdempotencyKeyReused => 409,
            self::RequestInProgress => 409,
            self::VersionConflict => 409,
            self::InvalidStateTransition => 409,
            self::PeriodNotOpen => 422,
            self::AccountNotPostable => 422,
            self::PostingSourceNotAllowed => 422,
            self::DimensionRequired => 422,
            self::DimensionInvalid => 422,
            self::UnbalancedEntry => 422,
            self::ExchangeRateNotFound => 422,
            self::TransactionTypeVersionRetired => 422,
            self::MappingRequired => 422,
            self::TransactionTypeNotAllowed => 403,
            self::SegregationOfDuties => 403,
            self::TwoFactorRequired => 403,
            self::Unauthenticated => 401,
            self::Forbidden => 403,
            self::NotFound => 404,
            self::CsrfTokenMismatch => 419,
            self::TooManyRequests => 429,
            self::ServerError => 500,
        };
    }

    public function message(): string
    {
        return __('errors.codes.'.$this->value);
    }
}
