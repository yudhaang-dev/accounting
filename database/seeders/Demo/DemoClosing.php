<?php

namespace Database\Seeders\Demo;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Period-end work, as the accountant does it: the ledger policy for soft-closed periods, the reconciliation of
 * control accounts against their open items, closing the month, and at the end of 2025 the income tax accrual
 * and the closing of the fiscal year with its closing journal.
 */
final class DemoClosing
{
    public function __construct(private readonly DemoFoundation $f, private readonly DemoBooks $books) {}

    public function policy(): void
    {
        $this->f->say('Kebijakan buku besar: periode tutup sementara hanya menerima jurnal penyesuaian');

        $this->f->api->as($this->f->users['accountant'])->put($this->f->url('/ledger-policy'), [
            'lock_version' => 0,
            'soft_closed_transaction_type_keys' => ['adjustment', 'general_journal'],
            'soft_closed_permission' => null,
            'source_override_permission' => null,
            'fx_rounding_tolerance' => '0',
        ]);
    }

    /** Compares each control account with the open items behind it on the date (SL-FR-09). */
    public function reconcile(CarbonImmutable $asOf): void
    {
        $this->f->api->as($this->f->users['accountant'])->post($this->f->url('/reconciliations'), ['as_of' => $asOf->toDateString()]);
    }

    /**
     * The opening balance of a cash account has no open items behind it, so it shows as a difference until the
     * accountant waives it with a reason that stays in the audit (SL-FR-09).
     */
    public function waiveOpeningCash(CarbonImmutable $asOf): void
    {
        $api = $this->f->api->as($this->f->users['accountant']);

        foreach ($api->get($this->f->url('/reconciliations'), ['filter[as_of]' => $asOf->toDateString(), 'filter[status]' => 'difference', 'per_page' => 50])['data'] as $row) {
            $api->post($this->f->url("/reconciliations/{$row['id']}/waive"), ['reason' => 'Saldo awal hasil konversi per 1 Januari 2025; dicocokkan dengan rekening koran dan saldo sistem lama.']);
        }
    }

    private function periodId(CarbonImmutable $date): string
    {
        return $this->f->api->get($this->f->url('/periods'), ['filter[date]' => $date->toDateString()])['data'][0]['id'];
    }

    public function close(CarbonImmutable $month): void
    {
        $this->f->api->as($this->f->users['accountant'])->post($this->f->url('/periods/'.$this->periodId($month).'/close'), ['reason' => 'Tutup buku bulanan '.$month->format('m/Y')]);
    }

    public function softClose(CarbonImmutable $month): void
    {
        $this->f->api->as($this->f->users['accountant'])->post($this->f->url('/periods/'.$this->periodId($month).'/soft-close'), ['reason' => 'Menunggu jurnal penyesuaian akhir bulan']);
    }

    /** The year's income tax at 22 % of the profit before tax, accrued on the last day of the year. */
    public function incomeTax(int $year): void
    {
        // Credits less debits over every income and expense line is the profit.
        $profit = (float) DB::table('gl_journal_lines as l')
            ->join('gl_accounts as a', 'a.id', '=', 'l.account_id')
            ->where('l.company_id', $this->f->companyId)
            ->whereBetween('l.accounting_date', ["{$year}-01-01", "{$year}-12-31"])
            ->whereIn('a.class', ['income', 'expense'])
            ->selectRaw('coalesce(sum(l.credit - l.debit), 0) as profit')
            ->value('profit');

        $tax = (int) (round($profit * 0.22 / 1000) * 1000);

        if ($tax <= 0) {
            return;
        }

        $this->books->post(
            'adjustment',
            "{$year}-12-31",
            "Taksiran pajak penghasilan badan tahun {$year}",
            lines: [
                ['account_id' => $this->f->account('7310'), 'debit' => (string) $tax, 'description' => 'Beban pajak kini 22%'],
                ['account_id' => $this->f->account('2153'), 'credit' => (string) $tax, 'description' => 'Utang pajak penghasilan'],
            ],
            by: 'accountant',
        );
    }

    /** Closes the fiscal year: its closing journal moves the result to retained earnings. */
    public function closeYear(int $year): void
    {
        $this->f->say("Tutup tahun buku {$year}");

        $years = $this->f->api->get($this->f->url('/fiscal-years'))['data'];
        $id = collect($years)->first(fn (array $y) => str_starts_with($y['start_date'], (string) $year))['id'];

        $this->f->api->as($this->f->users['accountant'])->post($this->f->url("/fiscal-years/{$id}/close"));
    }
}
