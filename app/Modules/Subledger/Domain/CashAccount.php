<?php

namespace App\Modules\Subledger\Domain;

use App\Platform\Api\VersionedCompanyModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A cash box or bank account with one currency and one ledger account (SL-FR-03). The currency and
 * the ledger account never change, since transactions and journals refer to them.
 *
 * @property string $id
 * @property string $company_id
 * @property string $name
 * @property CashAccountKind $kind
 * @property string|null $bank_name
 * @property string|null $account_number
 * @property string $currency
 * @property string $account_id
 * @property PartyStatus $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class CashAccount extends VersionedCompanyModel
{
    protected $table = 'sl_cash_accounts';

    protected $attributes = ['status' => 'active'];

    protected $fillable = ['name', 'kind', 'bank_name', 'account_number', 'currency', 'account_id', 'status'];

    protected function casts(): array
    {
        return [
            'kind' => CashAccountKind::class,
            'status' => PartyStatus::class,
        ];
    }

    /** @return HasMany<CashTransaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(CashTransaction::class);
    }
}
