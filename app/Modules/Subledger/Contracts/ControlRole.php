<?php

namespace App\Modules\Subledger\Contracts;

/**
 * The control account roles of a posting profile (SL-FR-02). The keys are fixed by the product;
 * which account plays a role is data per profile (P-01).
 */
enum ControlRole: string
{
    case Receivables = 'receivables_control';
    case Payables = 'payables_control';

    /** @return string the key of the ledger account class the role needs */
    public function accountClass(): string
    {
        return match ($this) {
            self::Receivables => 'asset',
            self::Payables => 'liability',
        };
    }
}
