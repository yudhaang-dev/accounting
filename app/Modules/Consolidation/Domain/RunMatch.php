<?php

namespace App\Modules\Consolidation\Domain;

use App\Support\Eloquent\UlidModel;

/**
 * The verdict of a run on what two entities hold against each other (CO-FR-07): the positions of both sides in
 * the presentation currency, their sum, and whether it is within the tolerance of the group. A pair that does
 * not match is an exception and nothing of it is eliminated.
 *
 * @property string $id
 * @property string $run_id
 * @property string $kind `balance` (closing balance of balance sheet accounts) or `flow` (movement of income and expense accounts)
 * @property string $company_a_id
 * @property string $company_b_id
 * @property string $amount_a net debit of the positions of company A
 * @property string $amount_b net debit of the positions of company B
 * @property string $difference `amount_a` + `amount_b`; zero when both sides agree
 * @property string $status `matched` or `exception`
 * @property string|null $reason `difference` or `outside_perimeter` for an exception
 * @property list<array<string, mixed>> $positions
 */
class RunMatch extends UlidModel
{
    public const MATCHED = 'matched';

    public const EXCEPTION = 'exception';

    public $timestamps = false;

    protected $table = 'co_run_matches';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['positions' => 'array'];
    }
}
