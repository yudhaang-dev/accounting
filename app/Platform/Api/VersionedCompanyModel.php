<?php

namespace App\Platform\Api;

use App\Platform\Api\Concerns\HasLockVersion;
use App\Support\Company\CompanyScopedModel;

/**
 * Base class for mutable company-scoped resources exposed through the API: ULID key, company
 * isolation (P-05) and optimistic concurrency via `lock_version` (API-TR-14).
 */
abstract class VersionedCompanyModel extends CompanyScopedModel
{
    use HasLockVersion;
}
