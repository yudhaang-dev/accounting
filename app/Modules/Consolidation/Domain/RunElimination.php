<?php

namespace App\Modules\Consolidation\Domain;

use App\Support\Eloquent\UlidModel;

/**
 * A row that exists only in a run (CO-AC-04): either the reversal of one intragroup position of a matched pair
 * (`elimination`), or what the pair left within the tolerance (`difference`, on no account).
 *
 * @property string $id
 * @property string $run_id
 * @property string $match_id
 * @property string $kind
 * @property string|null $company_id
 * @property string|null $counterparty_company_id
 * @property string|null $account_id
 * @property string|null $account_code
 * @property string|null $account_name
 * @property string|null $account_class
 * @property string|null $group_account_id
 * @property string|null $group_account_code
 * @property string|null $group_account_name
 * @property string|null $group_account_class
 * @property string|null $group_account_cash_flow_class
 * @property string $opening_debit
 * @property string $opening_credit
 * @property string $debit
 * @property string $credit
 * @property string $closing_debit
 * @property string $closing_credit
 */
class RunElimination extends UlidModel
{
    public const ELIMINATION = 'elimination';

    public const DIFFERENCE = 'difference';

    public $timestamps = false;

    protected $table = 'co_run_eliminations';

    protected $guarded = [];
}
