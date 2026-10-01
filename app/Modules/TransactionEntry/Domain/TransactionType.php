<?php

namespace App\Modules\TransactionEntry\Domain;

use App\Platform\Api\VersionedCompanyModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A transaction type with a stable key (TE-FR-01); what it does lives in its versions (TE-FR-02).
 *
 * @property string $id
 * @property string $company_id
 * @property string $key
 * @property string $label
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class TransactionType extends VersionedCompanyModel
{
    protected $table = 'te_transaction_types';

    protected $fillable = ['key', 'label'];

    /** @return HasMany<TransactionTypeVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(TransactionTypeVersion::class, 'transaction_type_id')->orderBy('version');
    }
}
