<?php

namespace App\Modules\Subledger\Contracts;

/**
 * The kinds of document the subledger records (SL-FR-04). A mechanism of the system, not data a company
 * defines: an invoice creates an open item; the others settle open items, and what they leave
 * unsettled stays an open item of its own until it is allocated (SL-FR-05).
 */
enum DocumentKind: string
{
    case Invoice = 'invoice';
    case CreditNote = 'credit_note';
    case Payment = 'payment';
    case Advance = 'advance';

    /** Whether the document moves cash and so needs a cash account (SL-FR-11). */
    public function movesCash(): bool
    {
        return match ($this) {
            self::Payment, self::Advance => true,
            self::Invoice, self::CreditNote => false,
        };
    }

    /**
     * The side of the control account line of the document: an invoice raises the balance of the
     * control account and the other kinds lower it, so a receivable is debited by an invoice and
     * credited by the rest, and a payable the other way round.
     *
     * @return 'debit'|'credit'
     */
    public function controlSide(ControlRole $role): string
    {
        $raises = $this === self::Invoice;

        return match ($role) {
            ControlRole::Receivables => $raises ? 'debit' : 'credit',
            ControlRole::Payables => $raises ? 'credit' : 'debit',
        };
    }
}
