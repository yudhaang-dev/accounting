<?php

namespace App\Modules\Subledger\Contracts;

/**
 * The kinds of document the subledger records (SL-FR-04). A mechanism of the system, not data a company
 * defines: an invoice or a loan creates an open item; the others settle open items, and what they leave
 * unsettled stays an open item of its own until it is allocated (SL-FR-05).
 *
 * A loan is the one kind that raises a balance and moves cash at once: the cash goes out and the party
 * owes the company for it (an employee's advance, a loan given), or the cash comes in and the company
 * owes the party (a loan received). It is settled later by the payments and credit notes that lower it.
 */
enum DocumentKind: string
{
    case Invoice = 'invoice';
    case CreditNote = 'credit_note';
    case Payment = 'payment';
    case Advance = 'advance';
    case WriteOff = 'write_off';
    case Loan = 'loan';

    /** Whether the document may settle open items when it is posted: the kinds that lower a balance, except an advance, which waits for them (SL-FR-05). */
    public function allocates(): bool
    {
        return in_array($this, [self::CreditNote, self::Payment, self::WriteOff], true);
    }

    /** Whether the document moves cash and so needs a cash account (SL-FR-11). */
    public function movesCash(): bool
    {
        return match ($this) {
            self::Payment, self::Advance, self::Loan => true,
            self::Invoice, self::CreditNote, self::WriteOff => false,
        };
    }

    /** Whether the document raises the balance of the control account; the other kinds lower it. */
    public function raises(): bool
    {
        return in_array($this, [self::Invoice, self::Loan], true);
    }

    /**
     * The side of the control account line of the document: an invoice or a loan raises the balance of
     * the control account and the other kinds lower it, so a receivable is debited by one that raises
     * it and credited by the rest, and a payable the other way round.
     *
     * @return 'debit'|'credit'
     */
    public function controlSide(ControlRole $role): string
    {
        $raises = $this->raises();

        return match ($role) {
            ControlRole::Receivables => $raises ? 'debit' : 'credit',
            ControlRole::Payables => $raises ? 'credit' : 'debit',
        };
    }
}
