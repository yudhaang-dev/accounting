<?php

namespace App\Modules\Subledger\Application;

use App\Modules\MasterData\Contracts\CompanyDirectory;
use App\Modules\Subledger\Contracts\ControlRole;
use App\Modules\Subledger\Contracts\DocumentKind;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * Receivables or payables by age on a date, per party and document (SL-FR-08). What is left of an open item on the
 * date is what it was, less the allocations dated up to it that were not cancelled by then (SL-AC-04), so a past
 * date shows the position of that day; documents recorded later or reversed by then are left out. The functional
 * value follows the values the item carried: settled in parts at what they were worth and adjusted by the
 * revaluations in force. An amount is positive when it raises the balance of the control account, as an invoice
 * does, and negative for what lowers it, such as a credit note or an advance not applied yet. The buckets and the
 * date the age counts from are those of the policy, which changes no stored amount.
 *
 * @phpstan-type Bucket array{key: string, from_days: int|null, to_days: int|null}
 * @phpstan-type Row array{id: string, document_id: string, party_id: string, party_name: string, kind: string, side: string, document_number: string, document_date: string, due_date: string, currency: string, original_amount: string, original_functional: string, settled: string, settled_functional: string, adjustment: string}
 * @phpstan-type Item array{open_item_id: string, document_id: string, document_number: string, kind: string, document_date: string, due_date: string, currency: string, remaining_amount: string, remaining_functional: string, age_days: int, bucket: string}
 */
final class AgingReport
{
    public function __construct(
        private readonly ManageAgingPolicy $policies,
        private readonly CompanyDirectory $companies,
    ) {}

    /**
     * @return array{parameters: array{as_of: string, control_role: string, party_id: string|null, basis: string, buckets: list<Bucket>}, functional_currency: string, totals: array<string, string>, currency_totals: list<array{currency: string, buckets: array<string, string>, total: string}>, parties: list<array{party_id: string, party_name: string, totals: array<string, string>, items: list<Item>}>}
     */
    public function __invoke(string $companyId, string $asOf, ControlRole $role, ?string $partyId = null, ?string $basis = null): array
    {
        $policy = $this->policies->current();
        $basis ??= $policy['basis'];
        $buckets = $this->buckets($policy['buckets']);
        $raising = DocumentKind::Invoice->controlSide($role);

        $totals = $this->emptyTotals($buckets);
        $currencies = [];
        $parties = [];

        foreach ($this->rows($companyId, $asOf, $role, $partyId) as $row) {
            $remaining = BigDecimal::of($row['original_amount'])->minus($row['settled']);

            if (! $remaining->isPositive()) {
                continue;
            }

            $functional = BigDecimal::of($row['original_functional'])->minus($row['settled_functional'])->plus($row['adjustment']);
            $sign = $row['side'] === $raising ? 1 : -1;
            $remaining = $sign === 1 ? $remaining : $remaining->negated();
            $functional = $sign === 1 ? $functional : $functional->negated();

            $due = substr($row['due_date'], 0, 10);
            $date = substr($row['document_date'], 0, 10);
            $age = intdiv(strtotime($asOf) - strtotime($basis === 'document_date' ? $date : $due), 86400);
            $bucket = $this->bucketOf($buckets, $age);

            $parties[$row['party_id']] ??= ['party_id' => $row['party_id'], 'party_name' => $row['party_name'], 'totals' => $this->emptyTotals($buckets), 'items' => []];
            $parties[$row['party_id']]['totals'] = $this->add($parties[$row['party_id']]['totals'], $bucket, $functional);
            $parties[$row['party_id']]['items'][] = [
                'open_item_id' => $row['id'],
                'document_id' => $row['document_id'],
                'document_number' => $row['document_number'],
                'kind' => $row['kind'],
                'document_date' => $date,
                'due_date' => $due,
                'currency' => $row['currency'],
                'remaining_amount' => (string) $remaining->toScale(6),
                'remaining_functional' => (string) $functional->toScale(6),
                'age_days' => $age,
                'bucket' => $bucket,
            ];

            $totals = $this->add($totals, $bucket, $functional);
            $currencies[$row['currency']] = $this->add($currencies[$row['currency']] ?? $this->emptyTotals($buckets), $bucket, $remaining);
        }

        ksort($currencies);
        $parties = array_values($parties);
        usort($parties, fn (array $a, array $b): int => [$a['party_name'], $a['party_id']] <=> [$b['party_name'], $b['party_id']]);

        return [
            'parameters' => ['as_of' => $asOf, 'control_role' => $role->value, 'party_id' => $partyId, 'basis' => $basis, 'buckets' => $buckets],
            'functional_currency' => $this->companies->find($companyId)->functionalCurrency ?? '',
            'totals' => $totals,
            'currency_totals' => array_map(
                fn (string $currency, array $row): array => ['currency' => $currency, 'buckets' => array_diff_key($row, ['total' => 1]), 'total' => $row['total']],
                array_keys($currencies),
                $currencies,
            ),
            'parties' => $parties,
        ];
    }

    /**
     * The bucket after `current` and each bound of the policy, as ranges of days of age.
     *
     * @param  list<int>  $bounds
     * @return list<Bucket>
     */
    private function buckets(array $bounds): array
    {
        $buckets = [['key' => 'current', 'from_days' => null, 'to_days' => 0]];
        $from = 1;

        foreach ($bounds as $bound) {
            $buckets[] = ['key' => "{$from}-{$bound}", 'from_days' => $from, 'to_days' => $bound];
            $from = $bound + 1;
        }

        $buckets[] = ['key' => 'over_'.($from - 1), 'from_days' => $from, 'to_days' => null];

        return $buckets;
    }

    /**
     * @param  list<Bucket>  $buckets
     */
    private function bucketOf(array $buckets, int $age): string
    {
        $key = '';

        foreach ($buckets as $bucket) {
            $key = $bucket['key'];

            if ($bucket['to_days'] === null || $age <= $bucket['to_days']) {
                break;
            }
        }

        return $key;
    }

    /**
     * @param  list<Bucket>  $buckets
     * @return array<string, string>
     */
    private function emptyTotals(array $buckets): array
    {
        return [...array_fill_keys(array_column($buckets, 'key'), '0.000000'), 'total' => '0.000000'];
    }

    /**
     * @param  array<string, string>  $totals
     * @return array<string, string>
     */
    private function add(array $totals, string $bucket, BigDecimal $amount): array
    {
        $totals[$bucket] = (string) BigDecimal::of($totals[$bucket])->plus($amount)->toScale(6);
        $totals['total'] = (string) BigDecimal::of($totals['total'])->plus($amount)->toScale(6);

        return $totals;
    }

    /**
     * @return list<Row>
     */
    private function rows(string $companyId, string $asOf, ControlRole $role, ?string $partyId): array
    {
        $settled = "(a.debit_item_id = o.id or a.credit_item_id = o.id) and a.allocation_date <= ? and (a.status = 'active' or a.cancelled_on > ?)";

        $rows = DB::select(
            "select o.id, o.document_id, o.party_id, p.name as party_name, o.kind, o.side, o.document_number, o.document_date, o.due_date, o.currency,
                    o.original_amount, o.original_functional,
                    coalesce((select sum(a.amount) from sl_allocations a where a.company_id = o.company_id and {$settled}), 0) as settled,
                    coalesce((select sum(case when a.debit_item_id = o.id then a.functional_amount else a.credit_functional_amount end) from sl_allocations a where a.company_id = o.company_id and {$settled}), 0) as settled_functional,
                    coalesce((select sum(i.adjustment) from sl_revaluation_items i join sl_revaluation_runs v on v.company_id = i.company_id and v.id = i.run_id
                              join gl_journals j on j.id = v.journal_id left join gl_journals rj on rj.id = v.reversal_journal_id
                              where i.company_id = o.company_id and i.open_item_id = o.id and j.accounting_date <= ? and (rj.id is null or rj.accounting_date > ?)), 0) as adjustment
             from sl_open_items o
             join sl_documents d on d.company_id = o.company_id and d.id = o.document_id
             join sl_parties p on p.company_id = o.company_id and p.id = o.party_id
             left join sl_document_reversals r on r.company_id = d.company_id and r.document_id = d.id
             where o.company_id = ? and o.control_role = ? and d.accounting_date <= ? and (r.id is null or r.reversal_date > ?)
               ".($partyId === null ? '' : 'and o.party_id = ?').'
             order by p.name, o.party_id, o.document_date, o.document_number, o.id',
            [$asOf, $asOf, $asOf, $asOf, $asOf, $asOf, $companyId, $role->value, $asOf, $asOf, ...($partyId === null ? [] : [$partyId])],
        );

        $result = [];

        foreach ($rows as $row) {
            $result[] = [
                'id' => (string) $row->id,
                'document_id' => (string) $row->document_id,
                'party_id' => (string) $row->party_id,
                'party_name' => (string) $row->party_name,
                'kind' => (string) $row->kind,
                'side' => (string) $row->side,
                'document_number' => (string) $row->document_number,
                'document_date' => (string) $row->document_date,
                'due_date' => (string) $row->due_date,
                'currency' => (string) $row->currency,
                'original_amount' => (string) $row->original_amount,
                'original_functional' => (string) $row->original_functional,
                'settled' => (string) $row->settled,
                'settled_functional' => (string) $row->settled_functional,
                'adjustment' => (string) $row->adjustment,
            ];
        }

        return $result;
    }
}
