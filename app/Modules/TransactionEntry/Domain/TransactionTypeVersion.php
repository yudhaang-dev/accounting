<?php

namespace App\Modules\TransactionEntry\Domain;

use App\Support\Company\CompanyScopedModel;
use Illuminate\Support\Carbon;

/**
 * An immutable package of a transaction type (TE-FR-02). Only the status changes, when the version is retired.
 *
 * @property string $id
 * @property string $company_id
 * @property string $transaction_type_id
 * @property int $version
 * @property TransactionTypeMode $mode
 * @property VersionStatus $status
 * @property Carbon|null $created_at
 */
class TransactionTypeVersion extends CompanyScopedModel
{
    protected $table = 'te_transaction_type_versions';

    protected $fillable = ['transaction_type_id', 'version', 'mode', 'status'];

    protected function casts(): array
    {
        return [
            'mode' => TransactionTypeMode::class,
            'status' => VersionStatus::class,
        ];
    }
}
