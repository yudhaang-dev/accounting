<?php

namespace App\Modules\Planning\Domain;

use App\Support\Company\CompanyScopedModel;
use App\Support\Money\DecimalCast;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;

/**
 * One planned amount of a period, account and dimension set, in the functional currency (PL-FR-02).
 *
 * @property string $id
 * @property string $company_id
 * @property string $plan_version_id
 * @property string $period_id
 * @property string $account_id
 * @property string $dimension_set_id
 * @property BigDecimal $amount
 * @property string|null $note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PlanLine extends CompanyScopedModel
{
    protected $table = 'pl_plan_lines';

    public const AMOUNT_SCALE = 6;

    protected $fillable = ['plan_version_id', 'period_id', 'account_id', 'dimension_set_id', 'amount', 'note'];

    protected function casts(): array
    {
        return ['amount' => DecimalCast::class.':'.self::AMOUNT_SCALE];
    }
}
