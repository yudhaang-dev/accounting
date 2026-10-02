<?php

namespace App\Modules\GeneralLedger\Application;

use App\Modules\GeneralLedger\Domain\Account;
use App\Platform\Api\ApiException;
use App\Platform\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Changes code, name, saldo normal, parent or posting attributes of an account. The class never changes: a different
 * class is a different account (GL-FR-01).
 */
final class UpdateAccount
{
    private const FIELDS = ['code', 'name', 'normal_balance', 'parent_id', 'allowed_sources', 'dimension_requirements', 'currency_restriction', 'restricted_currency', 'is_revaluable'];

    public function __construct(
        private readonly AccountRules $rules,
        private readonly Audit $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data  any of code, name, normal_balance, parent_id and the posting attributes of GL-FR-02
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

            if (array_intersect_key($changes, AccountRules::POSTING_DEFAULTS) !== []) {
                $changes = array_replace($changes, $this->rules->resolvePostingAttributes(
                    $changes,
                    $account->only(array_keys(AccountRules::POSTING_DEFAULTS)),
                ));
            }

            $before = $account->only(array_keys($changes));
            $account->updateLocked($changes, $expectedVersion);
            $after = $account->only(array_keys($changes));

            // Loose comparison: JSON columns come back with their keys in a different order.
            $differing = array_keys(array_filter($after, fn (mixed $value, string $field): bool => $value != $before[$field], ARRAY_FILTER_USE_BOTH));
            $before = array_intersect_key($before, array_flip($differing));
            $after = array_intersect_key($after, array_flip($differing));

            if ($differing !== []) {
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
