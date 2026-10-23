<?php

namespace App\Modules\Consolidation\Domain;

use App\Support\Eloquent\UlidModel;

/**
 * One line of an adjustment, on an account of the group as it was named then, on one side only.
 *
 * @property string $id
 * @property string $adjustment_id
 * @property string $group_account_id
 * @property string $group_account_code
 * @property string $group_account_name
 * @property string $group_account_class
 * @property string $debit
 * @property string $credit
 */
class AdjustmentLine extends UlidModel
{
    public $timestamps = false;

    protected $table = 'co_adjustment_lines';

    protected $guarded = [];
}
