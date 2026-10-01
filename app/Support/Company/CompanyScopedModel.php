<?php

namespace App\Support\Company;

use App\Support\Eloquent\UlidModel;

/**
 * Base class for models of company-scoped tables (P-05): ULID key plus company filtering.
 */
abstract class CompanyScopedModel extends UlidModel
{
    use BelongsToCompany;
}
