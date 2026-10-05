<?php

namespace App\Modules\Subledger\Application;

use App\Modules\GeneralLedger\Contracts\AccountRole;
use App\Modules\GeneralLedger\Contracts\AccountRoles;
use App\Modules\GeneralLedger\Contracts\PostJournalLine;
use Brick\Math\BigDecimal;

/**
 * The journal lines that book the exchange difference realized when open items recorded at different
 * rates settle each other (SL-FR-06). The control account carried the credit side open item at a higher
 * or lower functional value than the debit side one, so an adjustment of the control account, which
 * changes no balance in the foreign currency, clears what is left, against the realized gain or loss.
 * A positive difference, credit part over debit part, is a gain.
 */
final class RealizedDifference
{
    public function __construct(private readonly AccountRoles $roles) {}

    /**
     * Fails with ApiException MAPPING_REQUIRED when the gain or loss role is not mapped.
     *
     * @param  string  $rate  functional currency per 1 unit of `$currency`, as the open items were recorded
     * @param  bool|null  $gainAccount  the account to book against when it is not the one the sign of the difference says, as when a gain is taken back
     * @return list<PostJournalLine> empty when there is no difference
     */
    public function lines(string $companyId, string $date, string $controlAccountId, string $currency, string $rate, BigDecimal $difference, ?bool $gainAccount = null): array
    {
        if ($difference->isZero()) {
            return [];
        }

        $gain = $difference->isPositive();
        $amount = (string) $difference->abs();
        $account = $this->roles->require($companyId, ($gainAccount ?? $gain) ? AccountRole::FxRealizedGain : AccountRole::FxRealizedLoss, $date)->accountId;

        // More credit than debit leaves the control account in credit, which a debit clears.
        return [
            new PostJournalLine($controlAccountId, $gain ? $amount : '0', $gain ? '0' : $amount, description: (string) __('documents.realized_difference'), currency: $currency, exchangeRate: $rate, adjustment: true),
            new PostJournalLine($account, $gain ? '0' : $amount, $gain ? $amount : '0', description: (string) __('documents.realized_difference')),
        ];
    }
}
