<?php

namespace App\Orchestration\Company;

use App\Modules\GeneralLedger\Contracts\AccountClass;
use App\Modules\GeneralLedger\Contracts\AccountDirectory;

/**
 * A company can only be activated once its chart of accounts can take postings of every class
 * (GL-FR-01, X-AC-03). Remembers the classes found missing so the message can name them.
 */
final class ChartOfAccountsRequirement implements ActivationRequirement
{
    /** @var list<AccountClass> */
    private array $missing = [];

    public function __construct(private readonly AccountDirectory $accounts) {}

    public function key(): string
    {
        return 'chart_of_accounts';
    }

    public function message(): string
    {
        return __('accounts.missing_classes', [
            'classes' => implode(', ', array_map(fn (AccountClass $class): string => __('accounts.classes.'.$class->value), $this->missing)),
        ]);
    }

    public function isMet(string $companyId): bool
    {
        $this->missing = $this->accounts->missingClasses($companyId);

        return $this->missing === [];
    }
}
