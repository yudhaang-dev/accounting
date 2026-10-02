<?php

namespace App\Modules\TransactionEntry\Domain;

use App\Support\Company\CompanyScopedModel;
use Illuminate\Support\Carbon;

/**
 * An immutable package of a transaction type (TE-FR-02). A draft can still be edited; once published only
 * the status changes, when the version is retired.
 *
 * @property string $id
 * @property string $company_id
 * @property string $transaction_type_id
 * @property int $version
 * @property TransactionTypeMode $mode
 * @property VersionStatus $status
 * @property list<array{name: string, type: string, label: string, required?: bool, account_ids?: list<string>, dimension_type?: string}>|null $fields
 * @property list<array{type: string, side?: string, amount_field?: string, account?: array{source: string, account_id?: string, field?: string}, apply_dimensions: bool, reuses_field: bool}>|null $posting_rules
 * @property Carbon|null $created_at
 */
class TransactionTypeVersion extends CompanyScopedModel
{
    protected $table = 'te_transaction_type_versions';

    protected $fillable = ['transaction_type_id', 'version', 'mode', 'status', 'fields', 'posting_rules'];

    protected function casts(): array
    {
        return [
            'mode' => TransactionTypeMode::class,
            'status' => VersionStatus::class,
            'fields' => 'array',
            'posting_rules' => 'array',
        ];
    }
}
