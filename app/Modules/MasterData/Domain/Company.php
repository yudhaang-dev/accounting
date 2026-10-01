<?php

namespace App\Modules\MasterData\Domain;

use App\Modules\MasterData\Database\Factories\CompanyFactory;
use App\Platform\Api\Concerns\HasLockVersion;
use App\Support\Eloquent\UlidModel;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $name
 * @property string|null $legal_name
 * @property string|null $tax_id
 * @property string $functional_currency
 * @property string $timezone
 * @property string $default_locale
 * @property CompanyStatus $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[UseFactory(CompanyFactory::class)]
class Company extends UlidModel
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory, HasLockVersion;

    protected $table = 'md_companies';

    protected $attributes = ['status' => 'setup'];

    protected $fillable = ['name', 'legal_name', 'tax_id', 'functional_currency', 'timezone', 'default_locale'];

    protected function casts(): array
    {
        return ['status' => CompanyStatus::class];
    }
}
