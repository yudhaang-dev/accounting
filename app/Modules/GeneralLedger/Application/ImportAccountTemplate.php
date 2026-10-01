<?php

namespace App\Modules\GeneralLedger\Application;

use App\Modules\GeneralLedger\Contracts\AccountClass;
use App\Modules\GeneralLedger\Domain\Account;
use App\Platform\Api\ApiException;
use App\Platform\Api\ErrorCode;
use App\Platform\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates the accounts of a template in an empty chart, after the user confirmed it (X-AC-03). The
 * confirmation itself is enforced at the HTTP boundary; this action is only reachable through it.
 */
final class ImportAccountTemplate
{
    public function __construct(
        private readonly ChartTemplates $templates,
        private readonly Audit $audit,
    ) {}

    /**
     * @param  list<string>  $exclude  codes of optional leaf accounts to leave out
     * @return int number of accounts created
     *
     * @throws ValidationException unknown template, or an excluded account that is not optional or has children
     * @throws ApiException INVALID_STATE_TRANSITION when the chart already has accounts
     */
    public function __invoke(string $templateKey, array $exclude = []): int
    {
        $template = $this->templates->find($templateKey)
            ?? throw ValidationException::withMessages(['template' => [__('accounts.template_unknown')]]);

        return DB::transaction(function () use ($template, $exclude): int {
            if (Account::query()->exists()) {
                throw new ApiException(ErrorCode::InvalidStateTransition, __('accounts.chart_not_empty'));
            }

            $this->assertExcludable($template['accounts'], $exclude);

            $ids = [];
            $created = 0;

            // Parents have lower codes than their children, so the template order is safe.
            foreach ($template['accounts'] as $row) {
                if (in_array($row['code'], $exclude, true)) {
                    continue;
                }

                $class = AccountClass::from($row['class']);
                $account = Account::create([
                    'code' => $row['code'],
                    'name' => $row['name'],
                    'class' => $class,
                    'normal_balance' => $row['normal'] ?? $class->defaultNormalBalance(),
                    'parent_id' => $row['parent'] === null ? null : $ids[$row['parent']],
                ]);

                $ids[$row['code']] = $account->id;
                $created++;
            }

            $this->audit->record(
                'accounts.template_imported',
                "Chart of accounts template {$template['key']} imported.",
                properties: [
                    'template' => $template['key'],
                    'version' => $template['version'],
                    'created' => $created,
                    'excluded' => $exclude,
                ],
            );

            return $created;
        });
    }

    /**
     * @param  list<array{code: string, optional: bool, parent: string|null}>  $accounts
     * @param  list<string>  $exclude
     *
     * @throws ValidationException
     */
    private function assertExcludable(array $accounts, array $exclude): void
    {
        $byCode = array_column($accounts, null, 'code');
        $parents = array_filter(array_column($accounts, 'parent'));
        $errors = [];

        foreach ($exclude as $index => $code) {
            $row = $byCode[$code] ?? null;

            if ($row === null) {
                $errors["exclude.$index"] = [__('accounts.exclude_unknown', ['code' => $code])];
            } elseif (! $row['optional'] || in_array($code, $parents, true)) {
                $errors["exclude.$index"] = [__('accounts.exclude_not_optional', ['code' => $code])];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
