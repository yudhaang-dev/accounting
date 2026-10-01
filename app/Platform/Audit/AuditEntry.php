<?php

namespace App\Platform\Audit;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use LogicException;
use Spatie\Activitylog\Models\Activity;

/**
 * One append-only audit record (X-FR-01). The database trigger is the real guard (X-AC-05);
 * the model events fail early with a clear message.
 *
 * @property string $id
 * @property string|null $company_id
 * @property string $outcome
 */
class AuditEntry extends Activity
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $table = 'pf_audit_log';

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit entries are append-only.'));
        static::deleting(fn () => throw new LogicException('Audit entries are append-only.'));
    }
}
