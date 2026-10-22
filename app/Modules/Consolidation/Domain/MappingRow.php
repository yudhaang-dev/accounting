<?php

namespace App\Modules\Consolidation\Domain;

use App\Support\Eloquent\UlidModel;

/**
 * An account of a company mapped to an account of the group, within one version of the mapping.
 *
 * @property string $id
 * @property string $mapping_version_id
 * @property string $company_id
 * @property string $account_id
 * @property string $account_code
 * @property string $group_account_id
 */
class MappingRow extends UlidModel
{
    public $timestamps = false;

    protected $table = 'co_mapping_rows';

    protected $fillable = ['mapping_version_id', 'company_id', 'account_id', 'account_code', 'group_account_id'];
}
