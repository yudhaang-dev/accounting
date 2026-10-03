<?php

namespace App\Modules\Subledger\Domain;

use App\Platform\Api\VersionedCompanyModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Decides which control accounts the documents of a party post to, such as third parties, related
 * parties or foreign currency (SL-FR-02). Exactly one profile of the company is the default.
 *
 * @property string $id
 * @property string $company_id
 * @property string $name
 * @property bool $is_default
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PostingProfile extends VersionedCompanyModel
{
    protected $table = 'sl_posting_profiles';

    protected $attributes = ['is_default' => false];

    protected $fillable = ['name', 'is_default'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }

    /** @return HasMany<PostingProfileAccount, $this> */
    public function accounts(): HasMany
    {
        return $this->hasMany(PostingProfileAccount::class);
    }
}
