<?php

namespace App\Modules\GeneralLedger\Application;

use App\Modules\GeneralLedger\Domain\Account;
use App\Platform\Api\ApiException;
use App\Platform\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Changes code, name, saldo normal or parent of an account. The class never changes: a different
 * class is a different account (GL-FR-01).
 */
final class UpdateAccount
{
    private const FIELDS = ['code', 'name', 'normal_balance', 'parent_id'];

    public function __construct(
        private readonly AccountRules $rules,
        private readonly Audit $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data  any of code, name, normal_balance, parent_id
     *
     * @throws ValidationException
     * @throws ApiException VERSION_CONFLICT
     */
    public function __invoke(string $accountId, array $data, int $expectedVersion): Account
    {
        return DB::transaction(function () use ($accountId, $data, $expectedVersion): Account {
            $account = Account::query()->lockForUpdate()->findOrFail($accountId);
            $changes = array_intersect_key($data, array_flip(self::FIELDS));

            if (isset($changes['code']) && $changes['code'] !== $account->code) {
                $this->rules->assertCodeAvailable($changes['code'], $account->id);
            }

            if (array_key_exists('parent_id', $changes) && $changes['parent_id'] !== $account->parent_id && $changes['parent_id'] !== null) {
                $parent = $this->rules->assertValidParent($changes['parent_id'], $account->class);
                $this->rules->assertNoCycle($account, $parent);
            }

            $before = $account->only(array_keys($changes));
            $account->updateLocked($changes, $expectedVersion);
            $after = $account->only(array_keys($changes));

            if ($before !== $after) {
                $this->audit->record(
                    'account.updated',
                    "Account {$account->code} {$account->name} updated.",
                    $account,
                    before: $before,
                    after: $after,
                    companyId: $account->company_id,
                );
            }

            return $account->refresh();
        });
    }
}
