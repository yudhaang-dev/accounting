<?php

namespace App\Modules\Consolidation\Application;

use App\Modules\Consolidation\Domain\Group;
use App\Modules\Consolidation\Domain\GroupAccount;
use App\Modules\Consolidation\Domain\GroupRate;
use App\Modules\Consolidation\Domain\PerimeterMember;
use App\Modules\Consolidation\Domain\RateType;
use App\Modules\Consolidation\Domain\RunBalance;
use App\Modules\Consolidation\Domain\TranslationPolicy;
use App\Modules\Reporting\Contracts\ReportPackage;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Translates the package of an entity from its functional currency into the presentation currency of the group
 * (CO-FR-06). Currencies are never added across: every amount is multiplied by a rate that the policy and the
 * rates of the group name, and none is ever assumed.
 *
 * The policy says which type of rate applies to which part of the statements (ADR-0014):
 * - assets and liabilities at the closing rate: the closing balance at the rate on the cutoff date, the opening
 *   balance at the rate on the day before the range starts, so it equals the closing balance of a run before it;
 * - income and expenses at the average rate of the period, found on the same dates;
 * - equity at the historical rate, on the date the entity entered the perimeter, for opening, movement and closing.
 *
 * A rate is the latest version of the latest effective date on or before the date asked, of that type and
 * currency. When there is none the run is refused and names what is missing; it does not fall back to another
 * date, type or 1. An amount of zero needs no rate, so only the rates a balance really uses are required.
 *
 * Translating at several rates leaves each column (opening, movement, closing) out of balance. The difference
 * of each column goes to one row on the equity account the policy names, so every column of the entity balances
 * again; that row is the translation difference and carries the kind `translation_difference`. Amounts are
 * rounded half up to six decimals one by one, and the difference is taken from the rounded figures.
 */
final class TranslatePackage
{
    private const SCALE = 6;

    private const COLUMNS = ['opening_debit', 'opening_credit', 'debit', 'credit', 'closing_debit', 'closing_credit'];

    /**
     * @return array{rows: list<array<string, mixed>>, translation: array<string, mixed>} rows to store for the run, without ID and run
     *
     * @throws ValidationException
     */
    public function __invoke(Group $group, TranslationPolicy $policy, GroupAccount $differenceAccount, PerimeterMember $member, ReportPackage $package, string $from, string $to): array
    {
        $companyId = $member->company_id;
        $currency = $package->functionalCurrency;
        $dayBefore = CarbonImmutable::createFromFormat('!Y-m-d', $from)->subDay()->toDateString();
        $historical = $member->effective_from->toDateString();
        $types = RateType::query()->whereIn('id', [$policy->closing_rate_type_id, $policy->average_rate_type_id, $policy->historical_rate_type_id])->pluck('code', 'id')->all();
        $roles = [$policy->closing_rate_type_id => 'closing', $policy->average_rate_type_id => 'average', $policy->historical_rate_type_id => 'historical'];

        /** @var array<string, array<string, mixed>> $used */
        $used = [];

        $rateOn = function (string $typeId, string $date) use ($group, $companyId, $currency, $types, $roles, &$used): GroupRate {
            $key = "{$typeId}|{$date}";

            $rate = GroupRate::query()
                ->where('group_id', $group->id)->where('rate_type_id', $typeId)->where('currency', $currency)->where('effective_date', '<=', $date)
                ->orderByDesc('effective_date')->orderByDesc('version')->first();

            if ($rate === null) {
                throw ValidationException::withMessages(["entities.{$companyId}" => [__('consolidation.translation_rate_missing', ['type' => $types[$typeId] ?? $typeId, 'currency' => $currency, 'date' => $date])]]);
            }

            $used[$key] = [
                'purpose' => $roles[$typeId],
                'rate_type' => $types[$typeId] ?? $typeId,
                'requested_date' => $date,
                'effective_date' => $rate->effective_date->toDateString(),
                'rate' => (string) $rate->rate,
                'version' => $rate->version,
            ];

            return $rate;
        };

        $sums = array_fill_keys(self::COLUMNS, BigDecimal::zero());
        $rows = [];

        foreach ($package->balances as $balance) {
            $typeId = match ($balance->class) {
                'asset', 'liability' => $policy->closing_rate_type_id,
                'income', 'expense' => $policy->average_rate_type_id,
                'equity' => $policy->historical_rate_type_id,
                default => throw new LogicException("Unknown account class [{$balance->class}]."),
            };
            $isEquity = $balance->class === 'equity';
            $dates = ['opening' => $isEquity ? $historical : $dayBefore, 'movement' => $isEquity ? $historical : $to, 'closing' => $isEquity ? $historical : $to];

            $functional = [
                'opening_debit' => $balance->openingDebit, 'opening_credit' => $balance->openingCredit,
                'debit' => $balance->debit, 'credit' => $balance->credit,
                'closing_debit' => $balance->closingDebit, 'closing_credit' => $balance->closingCredit,
            ];
            $rates = ['opening' => null, 'movement' => null, 'closing' => null];
            $translated = [];

            foreach ($functional as $column => $amount) {
                $part = str_starts_with($column, 'opening') ? 'opening' : (str_starts_with($column, 'closing') ? 'closing' : 'movement');

                if (BigDecimal::of($amount)->isZero()) {
                    $translated[$column] = $this->money(BigDecimal::zero());

                    continue;
                }

                $rate = $rateOn($typeId, $dates[$part]);
                $rates[$part] = (string) $rate->rate;
                $translated[$column] = $this->money(BigDecimal::of($amount)->multipliedBy($rate->rate));
                $sums[$column] = $sums[$column]->plus($translated[$column]);
            }

            $rows[] = [
                'company_id' => $companyId,
                'account_id' => $balance->accountId,
                'account_code' => $balance->code,
                'account_name' => $balance->name,
                'account_class' => $balance->class,
                'kind' => 'account',
                'translation' => json_encode(['functional' => $functional, 'rates' => $rates], JSON_THROW_ON_ERROR),
            ] + $translated;
        }

        $difference = [
            'opening' => $sums['opening_debit']->minus($sums['opening_credit']),
            'movement' => $sums['debit']->minus($sums['credit']),
            'closing' => $sums['closing_debit']->minus($sums['closing_credit']),
        ];

        $rows[] = [
            'company_id' => $companyId,
            'account_id' => $differenceAccount->id,
            'account_code' => $differenceAccount->code,
            'account_name' => $differenceAccount->name,
            'account_class' => $differenceAccount->class,
            'kind' => RunBalance::TRANSLATION_DIFFERENCE,
            'translation' => null,
            'opening_debit' => $this->money($difference['opening']->isNegative() ? $difference['opening']->negated() : BigDecimal::zero()),
            'opening_credit' => $this->money($difference['opening']->isPositive() ? $difference['opening'] : BigDecimal::zero()),
            'debit' => $this->money($difference['movement']->isNegative() ? $difference['movement']->negated() : BigDecimal::zero()),
            'credit' => $this->money($difference['movement']->isPositive() ? $difference['movement'] : BigDecimal::zero()),
            'closing_debit' => $this->money($difference['closing']->isNegative() ? $difference['closing']->negated() : BigDecimal::zero()),
            'closing_credit' => $this->money($difference['closing']->isPositive() ? $difference['closing'] : BigDecimal::zero()),
        ];

        // Closing, average, historical; by date within each, so the trail reads the same every time.
        $order = ['closing' => 0, 'average' => 1, 'historical' => 2];
        usort($used, fn (array $a, array $b): int => [$order[$a['purpose']], $a['requested_date']] <=> [$order[$b['purpose']], $b['requested_date']]);

        return [
            'rows' => $rows,
            'translation' => [
                'policy_version' => $policy->version,
                'functional_currency' => $currency,
                'presentation_currency' => $group->presentation_currency,
                'rates' => $used,
                'difference' => ['account_id' => $differenceAccount->id, 'opening' => $this->money($difference['opening']->negated()), 'closing' => $this->money($difference['closing']->negated())],
            ],
        ];
    }

    private function money(BigDecimal|string $value): string
    {
        return (string) BigDecimal::of($value)->toScale(self::SCALE, RoundingMode::HalfUp);
    }
}
