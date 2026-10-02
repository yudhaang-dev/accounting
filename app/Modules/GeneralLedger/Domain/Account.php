<?php

namespace App\Modules\GeneralLedger\Domain;

use App\Modules\GeneralLedger\Contracts\AccountClass;
use App\Modules\GeneralLedger\Contracts\CurrencyRestriction;
use App\Modules\GeneralLedger\Database\Factories\AccountFactory;
use App\Platform\Api\VersionedCompanyModel;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An account of the company chart (GL-FR-01). Accounts are never deleted, only deactivated.
 *
 * @property string $id
 * @property string $company_id
 * @property string $code
 * @property string $name
 * @property AccountClass $class
 * @property string $normal_balance
 * @property string|null $parent_id
 * @property AccountStatus $status
 * @property list<string> $allowed_sources
 * @property array<string, string> $dimension_requirements
 * @property CurrencyRestriction $currency_restriction
 * @property string|null $restricted_currency
 * @property bool $is_revaluable
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[UseFactory(AccountFactory::class)]
class Account extends VersionedCompanyModel
{
    /** @use HasFactory<AccountFactory> */
    use HasFactory;

    protected $table = 'gl_accounts';

    protected $attributes = [
        'status' => 'active',
        'allowed_sources' => '["entry","subledger","system"]',
        'dimension_requirements' => '{}',
        'currency_restriction' => 'any',
        'is_revaluable' => false,
    ];

    protected $fillable = [
        'code', 'name', 'class', 'normal_balance', 'parent_id', 'status',
        'allowed_sources', 'dimension_requirements', 'currency_restriction', 'restricted_currency', 'is_revaluable',
    ];

    protected function casts(): array
    {
        return [
            'class' => AccountClass::class,
            'status' => AccountStatus::class,
            'allowed_sources' => 'array',
            'dimension_requirements' => JsonObjectCast::class,
            'currency_restriction' => CurrencyRestriction::class,
            'is_revaluable' => 'boolean',
        ];
    }

    /** @return BelongsTo<Account, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<Account, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function isActive(): bool
    {
        return $this->status === AccountStatus::Active;
    }
}
