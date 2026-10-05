<?php

namespace App\Modules\Subledger\Domain;

use App\Platform\Api\VersionedCompanyModel;
use Illuminate\Support\Carbon;

/**
 * The basis and buckets of the aging reports of a company (SL-FR-08).
 *
 * @property string $id
 * @property string $company_id
 * @property string $basis `due_date` or `document_date`
 * @property list<int> $buckets upper bound in days of each bucket after the current one, ascending
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AgingPolicy extends VersionedCompanyModel
{
    public const DEFAULT_BASIS = 'due_date';

    /** @var list<int> */
    public const DEFAULT_BUCKETS = [30, 60, 90];

    protected $table = 'sl_aging_policies';

    protected $fillable = ['basis', 'buckets'];

    protected function casts(): array
    {
        return ['buckets' => 'array'];
    }
}
