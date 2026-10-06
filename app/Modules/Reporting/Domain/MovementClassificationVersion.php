<?php

namespace App\Modules\Reporting\Domain;

use App\Support\Company\CompanyScopedModel;
use Illuminate\Support\Carbon;

/**
 * One immutable set of rows and rules of a movement classification (RP-FR-03).
 *
 * @property string $id
 * @property string $company_id
 * @property string $classification_id
 * @property int $version
 * @property array{rows: list<array{key: string, label: string}>, rules: list<array{row: string, accounts: list<array<string, string>>, transaction_type_keys: list<string>}>} $structure
 * @property int|null $created_by
 * @property Carbon|null $created_at
 */
class MovementClassificationVersion extends CompanyScopedModel
{
    protected $table = 'rp_movement_classification_versions';

    protected $fillable = ['classification_id', 'version', 'structure', 'created_by'];

    protected function casts(): array
    {
        return ['structure' => 'array'];
    }
}
