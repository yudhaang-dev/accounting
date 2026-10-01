<?php

namespace App\Modules\GeneralLedger\Contracts;

/**
 * The five classes of the chart of accounts. A parent and its children share one class (GL-FR-01).
 */
enum AccountClass: string
{
    case Asset = 'asset';
    case Liability = 'liability';
    case Equity = 'equity';
    case Income = 'income';
    case Expense = 'expense';

    /** Normal balance of the class; a contra account may carry the opposite one. */
    public function defaultNormalBalance(): string
    {
        return match ($this) {
            self::Asset, self::Expense => 'debit',
            self::Liability, self::Equity, self::Income => 'credit',
        };
    }
}
