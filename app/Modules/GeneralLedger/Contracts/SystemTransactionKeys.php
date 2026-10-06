<?php

namespace App\Modules\GeneralLedger\Contracts;

/**
 * Transaction type keys that the ledger gives to the journals it creates itself. Reports tell
 * them apart by these keys, for example to leave closing journals out of an income statement
 * (RP-FR-07).
 */
final class SystemTransactionKeys
{
    /** The journal that closes income and expense into retained earnings at the end of a fiscal year (GL-FR-13). */
    public const CLOSING = 'system.closing';

    /** The one journal of an opening balance import (GL-FR-11). */
    public const OPENING_BALANCE = 'system.opening_balance';
}
