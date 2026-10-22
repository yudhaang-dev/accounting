<?php

namespace App\Modules\Consolidation\Domain;

use App\Support\Eloquent\UlidModel;

/**
 * An account balance of one company in a run, with the group account it was mapped to or none (CO-FR-04).
 * Group lines are sums of these rows; the rows without a group account are the exceptions.
 *
 * @property string $id
 * @property string $run_id
 * @property string $company_id
 * @property string $account_id
 * @property string $account_code
 * @property string $account_name
 * @property string $account_class
 * @property string|null $group_account_id
 * @property string|null $group_account_code
 * @property string|null $group_account_name
 * @property string|null $group_account_class
 * @property string $opening_debit
 * @property string $opening_credit
 * @property string $debit
 * @property string $credit
 * @property string $closing_debit
 * @property string $closing_credit
 */
class RunBalance extends UlidModel
{
    public $timestamps = false;

    protected $table = 'co_run_balances';

    protected $guarded = [];
}
