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
 * @property string|null $description when the type is used, written by the accountant for the catalog an operator picks from (TE-FR-01)
 * @property string $number_policy `on_create` or `on_submit`: when an entry of this type gets its number (TE-FR-16)
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class TransactionType extends VersionedCompanyModel
{
    public const NUMBER_ON_CREATE = 'on_create';

    public const NUMBER_ON_SUBMIT = 'on_submit';

    protected $table = 'te_transaction_types';

    protected $fillable = ['key', 'label', 'description', 'number_policy'];

    protected $attributes = ['number_policy' => self::NUMBER_ON_CREATE];

    /** @return HasMany<TransactionTypeVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(TransactionTypeVersion::class, 'transaction_type_id')->orderBy('version');
    }
}
