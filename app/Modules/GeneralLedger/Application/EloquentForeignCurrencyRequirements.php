<?php

namespace App\Modules\GeneralLedger\Application;

use App\Modules\GeneralLedger\Contracts\AccountRole;
use App\Modules\GeneralLedger\Contracts\AccountRoles;
use App\Platform\Api\ApiException;
use App\Platform\Api\ErrorCode;
use App\Support\Currency\ForeignCurrencyRequirements;

/**
 * A foreign currency needs the five exchange difference roles mapped, as realized, unrealized and
 * rounding differences have nowhere to post otherwise (GL-FR-10).
 */
final class EloquentForeignCurrencyRequirements implements ForeignCurrencyRequirements
{
    public function __construct(private readonly AccountRoles $roles) {}

    public function assertMet(string $companyId): void
    {
        $missing = $this->roles->missing($companyId, AccountRole::foreignCurrencyRoles());

        if ($missing === []) {
            return;
        }

        $errors = [];

        foreach ($missing as $role) {
            $errors[$role->value] = [__('ledger.role_unmapped', ['key' => $role->value])];
        }

        throw new ApiException(ErrorCode::MappingRequired, __('currencies.roles_required'), errors: $errors);
    }
}
