<?php

namespace App\Platform\Numbering;

use App\Support\Eloquent\UlidModel;

/**
 * A numbering series definition: pattern with literal prefix and date tokens, padding, reset period.
 *
 * @property string $id
 * @property string $company_id
 * @property string $key
 * @property string $pattern
 * @property int $padding
 * @property ResetPeriod $reset_period
 */
class NumberSeries extends UlidModel
{
    protected $table = 'pf_number_series';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'padding' => 'integer',
            'reset_period' => ResetPeriod::class,
        ];
    }
}
