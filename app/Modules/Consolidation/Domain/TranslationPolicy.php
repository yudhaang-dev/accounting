<?php

namespace App\Modules\Consolidation\Domain;

use App\Support\Eloquent\UlidModel;
use Illuminate\Support\Carbon;

/**
 * One version of the translation policy of a group: which rate type translates assets and liabilities,
 * income and expenses, and equity, and the equity account that takes the translation difference (CO-FR-06).
 *
 * @property string $id
 * @property string $group_id
 * @property int $version
 * @property Carbon $effective_date
 * @property string $closing_rate_type_id
 * @property string $average_rate_type_id
 * @property string $historical_rate_type_id
 * @property string $difference_account_id
 * @property int|null $created_by
 * @property Carbon|null $created_at
 */
class TranslationPolicy extends UlidModel
{
    public const UPDATED_AT = null;

    protected $table = 'co_translation_policies';

    protected $fillable = ['group_id', 'version', 'effective_date', 'closing_rate_type_id', 'average_rate_type_id', 'historical_rate_type_id', 'difference_account_id', 'created_by'];

    protected function casts(): array
    {
        return ['effective_date' => 'date', 'version' => 'integer'];
    }
}
