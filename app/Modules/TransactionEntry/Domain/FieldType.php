<?php

namespace App\Modules\TransactionEntry\Domain;

/** Types a field of a `structured` version can have (TE-FR-04). */
enum FieldType: string
{
    case Amount = 'amount';
    case Date = 'date';
    case Text = 'text';
    case Reference = 'reference';
    case Account = 'account';
    case Party = 'party';
    case CashAccount = 'cash_account';
    case Dimension = 'dimension';
    case Allocations = 'allocations';
}
