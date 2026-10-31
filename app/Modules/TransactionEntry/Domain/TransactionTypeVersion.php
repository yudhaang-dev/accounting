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
 * @property list<array{name: string, type: string, label: string, required?: bool, account_ids?: list<string>, dimension_type?: string, allowed_roles?: list<string>}>|null $fields
 * @property list<array{type: string, side?: string, amount_field?: string, account?: array{source: string, account_id?: string, field?: string}, apply_dimensions: bool, reuses_field: bool}>|null $posting_rules
 * @property list<string>|null $allowed_currencies foreign currencies the version accepts; null for any active one
 * @property string|null $rate_type_key rate type to look rates up in; null for the default of the system
 * @property bool $allow_rate_input whether an entry may bring its own rate
 * @property array{kind: string, control_role: string, party_field: string, document_number_field: string|null, due_date_field: string|null, cash_account_field: string|null, allocations_field: string|null, counterpart_cash_account_field: string|null, posting_profile_id: string|null}|null $subledger_route the document an entry records in the subledger; null for entries that go straight to the ledger (TE-FR-10)
 * @property Carbon|null $created_at
 */
class TransactionTypeVersion extends CompanyScopedModel
{
    protected $table = 'te_transaction_type_versions';

    protected $fillable = ['transaction_type_id', 'version', 'mode', 'status', 'fields', 'posting_rules', 'allowed_currencies', 'rate_type_key', 'allow_rate_input', 'subledger_route'];

    protected function casts(): array
    {
        return [
            'mode' => TransactionTypeMode::class,
            'status' => VersionStatus::class,
            'fields' => 'array',
            'posting_rules' => 'array',
            'allowed_currencies' => 'array',
            'allow_rate_input' => 'boolean',
            'subledger_route' => 'array',
        ];
    }
}
