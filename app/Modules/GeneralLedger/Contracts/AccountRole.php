<?php

namespace App\Modules\GeneralLedger\Contracts;

/**
 * The account roles the ledger owns (GL-FR-10). The keys are fixed by the product; which account
 * plays a role is data per company (P-01).
 */
enum AccountRole: string
{
    case RetainedEarnings = 'retained_earnings';
    case FxRealizedGain = 'fx_realized_gain';
    case FxRealizedLoss = 'fx_realized_loss';
    case FxUnrealizedGain = 'fx_unrealized_gain';
    case FxUnrealizedLoss = 'fx_unrealized_loss';
    case FxRounding = 'fx_rounding';

    /** @return list<AccountClass> classes of the account that may play the role */
    public function allowedClasses(): array
    {
        return match ($this) {
            self::RetainedEarnings => [AccountClass::Equity],
            default => [AccountClass::Income, AccountClass::Expense],
        };
    }

    /**
     * Roles a company needs once it uses a foreign currency.
     *
     * @return list<self>
     */
    public static function foreignCurrencyRoles(): array
    {
        return [self::FxRealizedGain, self::FxRealizedLoss, self::FxUnrealizedGain, self::FxUnrealizedLoss, self::FxRounding];
    }
}
