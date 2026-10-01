<?php

namespace App\Platform\Retention;

use App\Support\Company\CompanyScopedModel;

/**
 * Base class for company-scoped financial records that must outlive their retention period
 * (X-FR-05): ULID key, company isolation (P-05) and no delete. Pair the table with
 * `RetentionGuard::protect()` in its migration.
 */
abstract class RetainedCompanyModel extends CompanyScopedModel
{
    use Retained;
}
