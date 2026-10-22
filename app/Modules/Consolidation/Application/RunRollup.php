<?php

namespace App\Modules\Consolidation\Application;

use App\Modules\Consolidation\Domain\Group;
use App\Modules\Consolidation\Domain\GroupAccount;
use App\Modules\Consolidation\Domain\MappingVersion;
use App\Modules\Consolidation\Domain\PerimeterMember;
use App\Modules\Consolidation\Domain\Run;
use App\Modules\Consolidation\Domain\RunBalance;
use App\Modules\Consolidation\Domain\RunEntity;
use App\Modules\MasterData\Contracts\CompanyDirectory;
use App\Modules\Reporting\Contracts\ReportPackage;
use App\Modules\Reporting\Contracts\ReportPackages;
use App\Platform\Audit\Audit;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The first stage of consolidation, the management roll-up (CO-FR-04): the packages of the entities of the
 * perimeter for one range, added up per account of the group through the version of the mapping in force at the
 * cutoff date. It is labelled a roll-up and never a consolidated statement, because nothing is translated,
 * eliminated or adjusted yet (CO-FR-05).
 *
 * - Each effective entity takes part exactly once. A member who joined or left inside the range makes the
 *   perimeter ambiguous, which is not supported yet, so the run is refused (CO-AC-02).
 * - Currencies are never added across: an entity whose functional currency is not the presentation currency
 *   refuses the run until translation exists (CO-FR-06, CO-AC-03).
 * - An account with no mapping is kept as an exception with its balance, so the totals still add up (CO-AC-02).
 * - A package that is not closed, or does not cover the range, is flagged. The group's package policy decides
 *   whether the run is kept as a draft or refused (CO-FR-03); data that is not there is never counted as zero.
 * - A run is only ever added. A later change of perimeter, mapping or ledger makes a new run (CO-FR-09, CO-AC-05).
 */
final class RunRollup
{
    public function __construct(
        private readonly ReportPackages $packages,
        private readonly CompanyDirectory $companies,
        private readonly Audit $audit,
    ) {}

    /** @throws ValidationException */
    public function __invoke(string $groupId, string $from, string $to, int $userId): Run
    {
        $this->assertRange($from, $to);

        $group = Group::query()->findOrFail($groupId);
        $members = $this->effectiveMembers($group, $from, $to);
        $packages = $this->packagesOf($members, $from, $to);

        $this->assertCurrencies($group, $packages);
        $draft = $this->assertPolicy($group, $packages);

        $mapping = MappingVersion::query()->where('group_id', $group->id)->where('effective_date', '<=', $to)->orderByDesc('version')->first();
        $rows = $mapping === null ? [] : $mapping->rows()->get();
        $groupAccounts = GroupAccount::query()->where('group_id', $group->id)->get()->keyBy('id');
        $names = collect($this->companies->findMany(array_keys($packages)))->keyBy('id');

        return DB::transaction(function () use ($group, $from, $to, $userId, $members, $packages, $draft, $mapping, $rows, $groupAccounts, $names): Run {
            $coverage = $draft ? $this->weakestCoverage($packages) : 'complete';

            $run = Run::create([
                'group_id' => $group->id,
                'from_date' => $from,
                'to_date' => $to,
                'label' => Run::MANAGEMENT_ROLLUP,
                'status' => $draft ? Run::DRAFT : Run::COMPLETE,
                'presentation_currency' => $group->presentation_currency,
                'mapping_version' => $mapping?->version,
                'perimeter' => array_map(fn (PerimeterMember $member): array => [
                    'member_id' => $member->id,
                    'company_id' => $member->company_id,
                    'effective_from' => $member->effective_from->toDateString(),
                    'effective_to' => $member->effective_to?->toDateString(),
                    'approved_by' => $member->approved_by,
                    'approved_at' => $member->approved_at?->toIso8601String(),
                ], $members),
                'package_policy' => $group->package_policy,
                'coverage_status' => $coverage,
                'created_by' => $userId,
            ])->refresh();

            $mapped = [];

            foreach ($rows as $row) {
                $mapped["{$row->company_id}:{$row->account_id}"] = $groupAccounts[$row->group_account_id] ?? null;
            }

            foreach ($packages as $companyId => $package) {
                RunEntity::create([
                    'run_id' => $run->id,
                    'company_id' => $companyId,
                    'company_name' => $names[$companyId]->name ?? $companyId,
                    'functional_currency' => $package->functionalCurrency,
                    'book_status' => $package->bookStatus,
                    'coverage_from' => $package->coverageFrom,
                    'coverage_to' => $package->coverageTo,
                    'coverage_granularity' => $package->coverageGranularity,
                    'coverage_status' => $package->coverageStatus,
                    'balanced' => $package->balanced,
                    'source' => ['references' => $package->sourceReferences, 'mapping_versions' => $package->mappingVersions],
                ]);

                // An entity with no data for the range has no balances here: nothing is invented for it.
                $balances = $package->coverageStatus === 'unavailable' ? [] : $package->balances;

                foreach (array_chunk($balances, 500) as $chunk) {
                    RunBalance::query()->insert(array_map(function ($balance) use ($run, $companyId, $mapped): array {
                        /** @var GroupAccount|null $target */
                        $target = $mapped["{$companyId}:{$balance->accountId}"] ?? null;

                        return [
                            'id' => (string) Str::ulid(),
                            'run_id' => $run->id,
                            'company_id' => $companyId,
                            'account_id' => $balance->accountId,
                            'account_code' => $balance->code,
                            'account_name' => $balance->name,
                            'account_class' => $balance->class,
                            'group_account_id' => $target?->id,
                            'group_account_code' => $target?->code,
                            'group_account_name' => $target?->name,
                            'group_account_class' => $target?->class,
                            'opening_debit' => $balance->openingDebit,
                            'opening_credit' => $balance->openingCredit,
                            'debit' => $balance->debit,
                            'credit' => $balance->credit,
                            'closing_debit' => $balance->closingDebit,
                            'closing_credit' => $balance->closingCredit,
                        ];
                    }, $chunk));
                }
            }

            $this->audit->record(
                'group_run.created',
                "Management roll-up of group {$group->name} for {$from}..{$to} ({$run->status}).",
                $run,
                after: ['status' => $run->status, 'mapping_version' => $run->mapping_version, 'entities' => array_keys($packages), 'coverage_status' => $coverage],
                properties: ['group_id' => $group->id, 'from' => $from, 'to' => $to],
                companyId: $group->parent_company_id,
            );

            return $run;
        });
    }

    /**
     * The approved members that are in the perimeter for the whole range. A member whose period of membership
     * covers only part of it is a change of perimeter inside the range, which this stage does not handle.
     *
     * @return list<PerimeterMember>
     *
     * @throws ValidationException
     */
    private function effectiveMembers(Group $group, string $from, string $to): array
    {
        $overlapping = PerimeterMember::query()
            ->where('group_id', $group->id)
            // Approved at some time: a member that has left since was in the perimeter and is part of the history.
            ->whereNotNull('approved_at')
            ->where('effective_from', '<=', $to)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>=', $from))
            ->orderBy('company_id')
            ->get();

        $errors = [];

        foreach ($overlapping as $member) {
            if ($member->effective_from->toDateString() > $from || ($member->effective_to !== null && $member->effective_to->toDateString() < $to)) {
                $errors["entities.{$member->company_id}"] = [__('consolidation.perimeter_changes_in_range')];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        if ($overlapping->isEmpty()) {
            throw ValidationException::withMessages(['from' => [__('consolidation.no_entity_in_range')]]);
        }

        return array_values($overlapping->all());
    }

    /**
     * @param  list<PerimeterMember>  $members
     * @return array<string, ReportPackage> by company
     */
    private function packagesOf(array $members, string $from, string $to): array
    {
        $packages = [];

        foreach ($members as $member) {
            $packages[$member->company_id] = $this->packages->build($member->company_id, $from, $to);
        }

        return $packages;
    }

    /**
     * @param  array<string, ReportPackage>  $packages
     *
     * @throws ValidationException
     */
    private function assertCurrencies(Group $group, array $packages): void
    {
        $errors = [];

        foreach ($packages as $companyId => $package) {
            if ($package->functionalCurrency !== $group->presentation_currency) {
                $errors["entities.{$companyId}"] = [__('consolidation.translation_required', ['currency' => $package->functionalCurrency, 'presentation' => $group->presentation_currency])];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Whether the run is a draft: some package is not closed or does not cover the range completely. Under the
     * policy `reject` such a run is refused instead.
     *
     * @param  array<string, ReportPackage>  $packages
     *
     * @throws ValidationException
     */
    private function assertPolicy(Group $group, array $packages): bool
    {
        $errors = [];

        foreach ($packages as $companyId => $package) {
            if (! $package->isClosed()) {
                $errors["entities.{$companyId}"][] = __('consolidation.package_not_closed', ['status' => $package->bookStatus]);
            }

            if (! $package->isComplete()) {
                $errors["entities.{$companyId}"][] = __('consolidation.package_coverage', ['status' => $package->coverageStatus]);
            }
        }

        if ($errors !== [] && $group->package_policy === 'reject') {
            throw ValidationException::withMessages($errors);
        }

        return $errors !== [];
    }

    /** @param  array<string, ReportPackage>  $packages */
    private function weakestCoverage(array $packages): string
    {
        $statuses = array_values(array_unique(array_map(fn (ReportPackage $package): string => $package->coverageStatus, $packages)));

        return match (true) {
            $statuses === ['complete'] => 'complete',
            $statuses === ['unavailable'] => 'unavailable',
            default => 'partial',
        };
    }

    /** @throws ValidationException */
    private function assertRange(string $from, string $to): void
    {
        foreach (['from' => $from, 'to' => $to] as $field => $value) {
            try {
                $valid = CarbonImmutable::createFromFormat('!Y-m-d', $value)->format('Y-m-d') === $value;
            } catch (InvalidFormatException) {
                $valid = false;
            }

            if (! $valid) {
                throw ValidationException::withMessages([$field => [__('validation.date_format', ['attribute' => $field, 'format' => 'Y-m-d'])]]);
            }
        }

        if ($to < $from) {
            throw ValidationException::withMessages(['to' => [__('validation.after_or_equal', ['attribute' => 'to', 'date' => 'from'])]]);
        }
    }
}
