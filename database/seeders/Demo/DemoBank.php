<?php

namespace Database\Seeders\Demo;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Month by month the BCA statement arrives as a CSV, is imported with the column mapping saved on the account,
 * and its lines are matched to the cash transactions the books already hold (SL-FR-13). The last two days of
 * each month are still on their way at the bank and show up on the next statement, which gives the report its
 * outstanding items; the latest months keep a bank charge or interest not booked yet and a few lines unmatched,
 * so the screens have something to work on.
 */
final class DemoBank
{
    /** @var list<array{direction: string, amount: string, reference: string}|array{date: string, direction: string, amount: string, reference: string}> */
    private array $carry = [];

    public function __construct(private readonly DemoFoundation $f) {}

    public function mapping(): void
    {
        $this->f->say('Pemetaan kolom rekening koran BCA');

        $this->f->api->as($this->f->users['accountant'])->put($this->f->url('/cash-accounts/'.$this->f->cash['bca'].'/import-mapping'), [
            'delimiter' => ',', 'date_format' => 'Y-m-d', 'decimal_separator' => '.', 'has_header' => true,
            'date_column' => 1, 'description_column' => 2, 'reference_column' => 3, 'amount_column' => 4,
        ]);
    }

    /** @param bool $latest the statement of a month still being worked on */
    public function statement(CarbonImmutable $first, CarbonImmutable $last, bool $latest): void
    {
        $api = $this->f->api->as($this->f->users['accountant']);
        $account = $this->f->cash['bca'];

        $transactions = [];
        $page = 1;

        do {
            $list = $api->get($this->f->url('/cash-transactions'), [
                'filter[cash_account_id]' => $account,
                'filter[date_from]' => $first->toDateString(),
                'filter[date_to]' => $last->toDateString(),
                'per_page' => 200,
                'page' => $page,
            ]);
            $transactions = [...$transactions, ...$list['data']];
            $page++;
        } while (($list['meta']['current_page'] ?? 1) < ($list['meta']['last_page'] ?? 1));

        usort($transactions, fn (array $a, array $b) => [$a['transaction_date'], $a['id']] <=> [$b['transaction_date'], $b['id']]);

        $cut = $last->subDay()->toDateString();
        $head = array_values(array_filter($transactions, fn (array $t) => $t['transaction_date'] < $cut));
        $tail = array_values(array_filter($transactions, fn (array $t) => $t['transaction_date'] >= $cut));

        $rows = [];

        foreach ($this->carry as $i => $c) {
            $rows[] = [$first->addDays($i % 2)->toDateString(), $c['direction'], $c['amount'], $c['reference']];
        }

        foreach ($head as $i => $t) {
            $bankDate = CarbonImmutable::parse($t['transaction_date'])->addDays($i % 3 === 0 ? 1 : 0)->min($last->subDay());
            $rows[] = [$bankDate->toDateString(), $t['direction'], $t['amount'], (string) ($t['reference'] ?? '')];
        }

        // Interest on the account, which the books have not recorded yet.
        $unrecorded = 0;

        if ($latest) {
            $unrecorded = 186_450;
            $rows[] = [$last->subDays(3)->toDateString(), 'in', (string) $unrecorded, 'BUNGA GIRO'];
        }

        usort($rows, fn (array $a, array $b) => $a[0] <=> $b[0]);

        $csv = "tanggal,keterangan,referensi,jumlah\n";
        foreach ($rows as [$date, $direction, $amount, $reference]) {
            $label = $reference === 'BUNGA GIRO' ? 'Bunga giro' : ($direction === 'in' ? 'Setoran transfer ' : 'Pembayaran transfer ').$reference;
            $csv .= sprintf("%s,%s,%s,%s\n", $date, $label, $reference, ($direction === 'in' ? '' : '-').$amount);
        }

        $outstandingIn = array_sum(array_map(fn (array $t) => $t['direction'] === 'in' ? (int) $t['amount'] : 0, $tail));
        $outstandingOut = array_sum(array_map(fn (array $t) => $t['direction'] === 'out' ? (int) $t['amount'] : 0, $tail));
        $book = (int) round((float) DB::table('gl_journal_lines')
            ->where('company_id', $this->f->companyId)->where('account_id', $this->f->account('1112'))->where('accounting_date', '<=', $last->toDateString())
            ->selectRaw('coalesce(sum(debit - credit), 0) as balance')->value('balance'));

        $statement = $api->post($this->f->url('/cash-accounts/'.$account.'/bank-statements'), [
            'csv' => $csv,
            'file_name' => 'BCA-'.$first->format('Y-m').'.csv',
            'closing_balance' => (string) ($book + $unrecorded - $outstandingIn + $outstandingOut),
            'closing_date' => $last->toDateString(),
        ])['data'];

        $this->match($statement['id'], $latest);
        $this->carry = array_map(fn (array $t) => ['direction' => $t['direction'], 'amount' => $t['amount'], 'reference' => (string) ($t['reference'] ?? '')], $tail);
    }

    private function match(string $statementId, bool $latest): void
    {
        $api = $this->f->api;

        $lines = [];
        $page = 1;

        do {
            $list = $api->get($this->f->url("/bank-statements/{$statementId}/lines"), ['per_page' => 200, 'page' => $page]);
            $lines = [...$lines, ...$list['data']];
            $page++;
        } while (($list['meta']['current_page'] ?? 1) < ($list['meta']['last_page'] ?? 1));

        $candidates = collect($api->rows($this->f->url("/bank-statements/{$statementId}/suggestions"), ['window_days' => 45]))
            ->mapWithKeys(fn (array $row) => [$row['line_id'] => $row['candidates'][0]['cash_transaction_id'] ?? null]);

        $used = [];
        $left = $latest ? 3 : 0;

        foreach (array_reverse($lines) as $line) {
            $transaction = $candidates[$line['id']] ?? null;

            if ($transaction === null || isset($used[$transaction])) {
                continue;
            }

            if ($left > 0) {
                $left--;

                continue;
            }

            $used[$transaction] = true;
            $api->post($this->f->url("/bank-statement-lines/{$line['id']}/match"), ['cash_transaction_id' => $transaction]);
        }
    }
}
