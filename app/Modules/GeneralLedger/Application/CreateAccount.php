<?php

namespace App\Modules\GeneralLedger\Application;

use App\Modules\GeneralLedger\Contracts\AccountClass;
use App\Modules\GeneralLedger\Domain\Account;
use App\Platform\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Adds an account to the chart of the current company (GL-FR-01, GL-FR-02).
 */
final class CreateAccount
{
    public const AUDITED = ['code', 'name', 'class', 'normal_balance', 'parent_id', 'status', 'allowed_sources', 'dimension_requirements', 'currency_restriction', 'restricted_currency', 'is_revaluable'];

    public function __construct(
        private readonly AccountRules $rules,
        private readonly Audit $audit,
    ) {}

    /**
     * @param  array{code: string, name: string, class: string, normal_balance?: string|null, parent_id?: string|null, allowed_sources?: list<string>, dimension_requirements?: array<string, string>, currency_restriction?: string, restricted_currency?: string|null, is_revaluable?: bool}  $data
     *
     * @throws ValidationException
     */
    public function __invoke(array $data): Account
    {
        $class = AccountClass::from($data['class']);

        return DB::transaction(function () use ($data, $class): Account {
            $this->rules->assertCodeAvailable($data['code']);

            $parentId = $data['parent_id'] ?? null;

            if ($parentId !== null) {
                $this->rules->assertValidParent($parentId, $class);
            }

            $posting = $this->rules->resolvePostingAttributes($data);

            $account = Account::create([
                'code' => $data['code'],
                'name' => $data['name'],
                'class' => $class,
                'normal_balance' => $data['normal_balance'] ?? $class->defaultNormalBalance(),
                'parent_id' => $parentId,
                ...$posting,
            ])->refresh();

            $this->audit->record(
                'account.created',
                "Account {$account->code} {$account->name} created.",
                $account,
                after: $account->only(self::AUDITED),
                companyId: $account->company_id,
            );

            return $account;
        });
    }
}
