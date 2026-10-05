<?php

namespace App\Modules\Planning\Application;

use App\Modules\GeneralLedger\Contracts\AccountClass;
use App\Modules\GeneralLedger\Contracts\AccountData;
use App\Modules\GeneralLedger\Contracts\AccountDirectory;
use App\Modules\GeneralLedger\Contracts\LedgerReader;
use App\Modules\GeneralLedger\Contracts\PeriodData;
use App\Modules\GeneralLedger\Contracts\PeriodDirectory;
use App\Modules\MasterData\Contracts\DimensionSets;
use App\Modules\Planning\Domain\Plan;
use App\Modules\Planning\Domain\PlanLine;
use App\Modules\Planning\Domain\PlanVersion;
use App\Modules\Planning\Domain\PlanVersionStatus;
use App\Platform\Api\ApiException;
use App\Platform\Api\ErrorCode;
use App\Platform\Audit\Audit;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Writes the lines of a draft version (PL-FR-02, PL-FR-03). A line is the cell of a period, an account
 * and a dimension set; writing a cell that exists replaces its amount and note. Input is checked row by
 * row, and when any row is wrong nothing is written and every problem is reported (PL-AC-02).
 */
final class ManagePlanLines
{
    private const CHUNK = 500;

    private const AMOUNT_PATTERN = '/^-?\d{1,18}(\.\d{1,'.PlanLine::AMOUNT_SCALE.'})?$/';

    public function __construct(
        private readonly Audit $audit,
        private readonly AccountDirectory $accounts,
        private readonly PeriodDirectory $periods,
        private readonly DimensionSets $dimensionSets,
        private readonly LedgerReader $ledger,
    ) {}

    /**
     * Enters rows, each `period_id`, `account_id`, `amount` and optionally `dimensions` (dimension
     * type key => value ID) and `note`.
     *
     * @param  array<int|string, array<string, mixed>>  $rows  keyed by the label that names the row in errors
     * @param  array<string, list<string>>  $errors  problems found before the rows reached this point, with their full keys
     * @param  string  $prefix  leads the keys of the errors: `<prefix>.<row label>.<field>`
     * @param  string  $event  audit event of the batch
     * @return array{created: int, updated: int}
     *
     * @throws ApiException INVALID_STATE_TRANSITION when the version is not a draft
     * @throws ValidationException
     */
    public function enter(string $versionId, array $rows, int $actorId, string $prefix = 'lines', array $errors = [], string $event = 'plan_version.lines_saved'): array
    {
        return DB::transaction(function () use ($versionId, $rows, $actorId, $prefix, $errors, $event): array {
            [$version, $plan] = $this->draft($versionId);

            $accounts = [];
            $periods = [];
            $cells = [];
            $seen = [];

            foreach ($rows as $label => $row) {
                $problems = [];
                $cell = $this->resolve($version->company_id, $plan, $row, $accounts, $periods, $problems);

                if ($cell !== null) {
                    $key = $this->key($cell);

                    if (isset($seen[$key])) {
                        $problems['account_id'] = [(string) __('plans.line_duplicate', ['row' => $seen[$key]])];
                    } else {
                        $seen[$key] = $label;
                        $cells[] = $cell;
                    }
                }

                foreach ($problems as $field => $messages) {
                    $errors["{$prefix}.{$label}.{$field}"] = $messages;
                }
            }

            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            return $this->write($version, $cells, $actorId, $event);
        });
    }

    /**
     * @throws ApiException INVALID_STATE_TRANSITION when the version is not a draft
     */
    public function delete(string $versionId, string $lineId, int $actorId): void
    {
        DB::transaction(function () use ($versionId, $lineId, $actorId): void {
            [$version] = $this->draft($versionId);

            $line = PlanLine::query()->where('plan_version_id', $version->id)->findOrFail($lineId);
            $before = ['period_id' => $line->period_id, 'account_id' => $line->account_id, 'dimension_set_id' => $line->dimension_set_id, 'amount' => (string) $line->amount, 'note' => $line->note];
            $line->delete();

            $this->touch($version, $actorId);
            $this->audit->record('plan_version.line_deleted', "Line of plan version {$version->name} deleted.", $version, before: $before, companyId: $version->company_id);
        });
    }

    /**
     * Copies the lines of another version of the same fiscal year into a draft; cells that already
     * exist take the amounts of the source (PL-FR-03).
     *
     * @return array{created: int, updated: int, skipped: int}
     *
     * @throws ApiException INVALID_STATE_TRANSITION when the version is not a draft
     * @throws ValidationException
     */
    public function copyFromVersion(string $versionId, string $sourceId, int $actorId): array
    {
        return DB::transaction(function () use ($versionId, $sourceId, $actorId): array {
            [$version, $plan] = $this->draft($versionId);
            $source = PlanVersion::query()->with('plan')->findOrFail($sourceId);

            if ($source->id === $version->id) {
                throw ValidationException::withMessages(['from_version_id' => [__('plans.copy_same_version')]]);
            }

            if ($source->plan->fiscal_year_id !== $plan->fiscal_year_id) {
                throw ValidationException::withMessages(['from_version_id' => [__('plans.copy_other_year')]]);
            }

            $cells = [];

            PlanLine::query()->where('plan_version_id', $source->id)->orderBy('id')->each(function (PlanLine $line) use (&$cells): void {
                $cells[] = [
                    'period_id' => $line->period_id,
                    'account_id' => $line->account_id,
                    'dimension_set_id' => $line->dimension_set_id,
                    'amount' => $line->getRawOriginal('amount'),
                    'note' => $line->note,
                ];
            });

            return [...$this->write($version, $cells, $actorId, 'plan_version.lines_copied', ['from_version_id' => $source->id]), 'skipped' => 0];
        });
    }

    /**
     * Copies what was posted in a period into a period of the plan: income and expense accounts per
     * dimension set, as a positive amount in the direction of the normal balance of the account.
     * Inactive accounts are skipped (PL-FR-02) and counted. Cells that already exist take the actuals.
     *
     * @return array{created: int, updated: int, skipped: int}
     *
     * @throws ApiException INVALID_STATE_TRANSITION when the version is not a draft
     * @throws ValidationException
     */
    public function copyFromActuals(string $versionId, string $actualsPeriodId, string $toPeriodId, int $actorId): array
    {
        return DB::transaction(function () use ($versionId, $actualsPeriodId, $toPeriodId, $actorId): array {
            [$version, $plan] = $this->draft($versionId);

            $actuals = $this->periods->find($version->company_id, $actualsPeriodId);
            $target = $this->periods->find($version->company_id, $toPeriodId);
            $errors = [];

            if ($actuals === null) {
                $errors['actuals_period_id'] = [__('plans.period_unknown')];
            }

            if ($target === null) {
                $errors['to_period_id'] = [__('plans.period_unknown')];
            } elseif ($target->fiscalYearId !== $plan->fiscal_year_id) {
                $errors['to_period_id'] = [__('plans.period_outside_year')];
            }

            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            /** @var PeriodData $actuals */
            /** @var PeriodData $target */
            $cells = [];
            $skipped = 0;
            $accounts = [];

            foreach ($this->ledger->movementByDimensionSet($version->company_id, $actuals->startDate, $actuals->endDate, [AccountClass::Income, AccountClass::Expense]) as $row) {
                $account = $accounts[$row->accountId] ??= $this->accounts->find($version->company_id, $row->accountId);

                if ($account === null || ! $account->isActive || ! $account->isLeaf) {
                    $skipped++;

                    continue;
                }

                $net = $account->normalBalance === 'credit'
                    ? BigDecimal::of($row->credit)->minus($row->debit)
                    : BigDecimal::of($row->debit)->minus($row->credit);

                $cells[] = [
                    'period_id' => $target->id,
                    'account_id' => $account->id,
                    'dimension_set_id' => $row->dimensionSetId,
                    'amount' => (string) $net->toScale(PlanLine::AMOUNT_SCALE),
                    'note' => null,
                ];
            }

            return [...$this->write($version, $cells, $actorId, 'plan_version.lines_copied', ['actuals_period_id' => $actuals->id, 'to_period_id' => $target->id]), 'skipped' => $skipped];
        });
    }

    /**
     * @return array{0: PlanVersion, 1: Plan}
     *
     * @throws ApiException INVALID_STATE_TRANSITION
     */
    private function draft(string $versionId): array
    {
        $version = PlanVersion::query()->lockForUpdate()->findOrFail($versionId);

        if ($version->status !== PlanVersionStatus::Draft) {
            throw new ApiException(ErrorCode::InvalidStateTransition);
        }

        return [$version, Plan::query()->findOrFail($version->plan_id)];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, AccountData|null>  $accounts  looked up so far, by ID
     * @param  array<string, PeriodData|null>  $periods  looked up so far, by ID
     * @param  array<string, list<string>>  $problems  filled with field => messages
     * @return array{period_id: string, account_id: string, dimension_set_id: string, amount: string, note: string|null}|null
     */
    private function resolve(string $companyId, Plan $plan, array $row, array &$accounts, array &$periods, array &$problems): ?array
    {
        $periodId = (string) ($row['period_id'] ?? '');
        $accountId = (string) ($row['account_id'] ?? '');
        $amount = trim((string) ($row['amount'] ?? ''));

        $period = $periodId === '' ? null : ($periods[$periodId] ??= $this->periods->find($companyId, $periodId));

        if ($period === null) {
            $problems['period_id'] = [(string) __('plans.period_unknown')];
        } elseif ($period->fiscalYearId !== $plan->fiscal_year_id) {
            $problems['period_id'] = [(string) __('plans.period_outside_year')];
        }

        $account = $accountId === '' ? null : ($accounts[$accountId] ??= $this->accounts->find($companyId, $accountId));
        $accountProblem = match (true) {
            $account === null => (string) __('plans.account_unknown'),
            ! $account->isActive => (string) __('plans.account_inactive', ['code' => $account->code]),
            ! $account->isLeaf => (string) __('plans.account_parent', ['code' => $account->code]),
            ! in_array($account->class, [AccountClass::Income, AccountClass::Expense], true) => (string) __('plans.account_class', ['code' => $account->code]),
            default => null,
        };

        if ($accountProblem !== null) {
            $problems['account_id'] = [$accountProblem];
        }

        if (preg_match(self::AMOUNT_PATTERN, $amount) !== 1) {
            $problems['amount'] = [(string) __('plans.amount_invalid', ['scale' => PlanLine::AMOUNT_SCALE])];
        }

        $setId = null;

        if ($period !== null && ! isset($problems['period_id'])) {
            try {
                $setId = $this->dimensionSets->resolve($companyId, $row['dimensions'] ?? [], $period->startDate);
            } catch (ApiException $exception) {
                foreach ($exception->errors as $field => $messages) {
                    $problems[$field] = $messages;
                }
            }
        }

        if ($problems !== [] || $period === null || $account === null || $setId === null) {
            return null;
        }

        $note = isset($row['note']) ? trim((string) $row['note']) : '';

        return [
            'period_id' => $period->id,
            'account_id' => $account->id,
            'dimension_set_id' => $setId,
            'amount' => (string) BigDecimal::of($amount)->toScale(PlanLine::AMOUNT_SCALE),
            'note' => $note === '' ? null : $note,
        ];
    }

    /**
     * @param  list<array{period_id: string, account_id: string, dimension_set_id: string, amount: string, note: string|null}>  $cells
     * @param  array<string, string>  $properties  where a copy came from
     * @return array{created: int, updated: int}
     */
    private function write(PlanVersion $version, array $cells, int $actorId, string $event, array $properties = []): array
    {
        $existing = [];

        if ($cells !== []) {
            PlanLine::query()
                ->where('plan_version_id', $version->id)
                ->whereIn('period_id', array_values(array_unique(array_column($cells, 'period_id'))))
                ->orderBy('id')
                ->each(function (PlanLine $line) use (&$existing): void {
                    $existing[$this->key(['period_id' => $line->period_id, 'account_id' => $line->account_id, 'dimension_set_id' => $line->dimension_set_id])] = true;
                });
        }

        $created = 0;
        $updated = 0;
        $now = now();

        foreach (array_chunk($cells, self::CHUNK) as $chunk) {
            PlanLine::query()->upsert(array_map(function (array $cell) use ($version, $now, $existing, &$created, &$updated): array {
                if (isset($existing[$this->key($cell)])) {
                    $updated++;
                } else {
                    $created++;
                }

                return [
                    'id' => (string) Str::ulid(),
                    'company_id' => $version->company_id,
                    'plan_version_id' => $version->id,
                    ...$cell,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }, $chunk), ['plan_version_id', 'period_id', 'account_id', 'dimension_set_id'], ['amount', 'note', 'updated_at']);
        }

        if ($cells !== []) {
            $this->touch($version, $actorId);
            $this->audit->record($event, "Lines of plan version {$version->name} changed: {$created} created, {$updated} updated.", $version, after: ['created' => $created, 'updated' => $updated], properties: $properties, companyId: $version->company_id);
        }

        return ['created' => $created, 'updated' => $updated];
    }

    /** The publisher policy compares the publisher with whoever changed the version last (PL-FR-04). */
    private function touch(PlanVersion $version, int $actorId): void
    {
        PlanVersion::query()->whereKey($version->id)->update(['last_modified_by' => $actorId, 'updated_at' => now()]);
    }

    /** @param  array{period_id: string, account_id: string, dimension_set_id: string}  $cell */
    private function key(array $cell): string
    {
        return "{$cell['period_id']}|{$cell['account_id']}|{$cell['dimension_set_id']}";
    }
}
