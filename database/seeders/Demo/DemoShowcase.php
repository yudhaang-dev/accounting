<?php

namespace Database\Seeders\Demo;

use Carbon\CarbonImmutable;

/**
 * What is going on today, so every screen has something to show: entries waiting for approval, a rejected one,
 * a cancelled draft, a reversal, an accrual that reverses itself, an adjustment into a soft-closed month,
 * attachments, an advance applied to an invoice, an advance left open, and the access settings (a branch
 * head who sees only Surabaya, the session limit, the buckets of the aging report).
 */
final class DemoShowcase
{
    public function __construct(private readonly DemoFoundation $f, private readonly DemoBooks $books, private readonly CarbonImmutable $today) {}

    public function run(): void
    {
        $this->f->say('Keadaan hari ini: persetujuan tertunda, penolakan, pembalikan, lampiran, uang muka');

        $this->adjustmentIntoSoftClosedMonth();
        $this->reversal();
        $this->advances();
        $this->waitingForApproval();
        $this->attachments();
        $this->accessAndPolicies();
    }

    private function day(int $daysAgo): string
    {
        return $this->today->subDays($daysAgo)->max($this->today->startOfMonth())->toDateString();
    }

    /** September is soft-closed: only adjustments are accepted, which is what the ledger policy says. */
    private function adjustmentIntoSoftClosedMonth(): void
    {
        $softClosed = $this->today->startOfMonth()->subMonth();

        if ($softClosed->toDateString() < '2025-02-01') {
            return;
        }

        $this->books->post('adjustment', $softClosed->endOfMonth()->toDateString(), 'Akrual perlengkapan kantor yang belum ditagih '.$softClosed->locale('id')->translatedFormat('F Y'), lines: [
            ['account_id' => $this->f->account('6240'), 'debit' => '4200000'],
            ['account_id' => $this->f->account('2120'), 'credit' => '4200000'],
        ], by: 'accountant');
    }

    /** An accrual booked too early, reversed once the bill arrived. */
    private function reversal(): void
    {
        $entry = $this->books->post('general_journal', $this->day(18), 'Akrual honor konsultan proyek integrasi', lines: [
            ['account_id' => $this->f->account('6260'), 'debit' => '12000000', 'dimensions' => ['department' => $this->f->dimensionValues['ADM']]],
            ['account_id' => $this->f->account('2130'), 'credit' => '12000000'],
        ], by: 'accountant');

        $reversal = $this->f->api->as($this->f->users['preparer'])->post($this->f->url("/journals/{$entry['journal_id']}/reverse"), [
            'reversal_date' => $this->day(9),
            'reason' => 'Akrual dibatalkan: tagihan konsultan sudah diterima dan dicatat sebagai tagihan pemasok.',
        ])['data'];
        $this->books->approve($reversal['status'] === 'draft' ? $this->books->submit($reversal['id'], 'preparer') : $reversal);
    }

    /** One advance applied to an invoice of the same customer, and one still waiting for its invoice. */
    private function advances(): void
    {
        $api = $this->f->api->as($this->f->users['preparer']);

        $advance = fn (string $customer, string $amount, string $date, string $number) => $this->books->post('customer_receipt', $date, 'Uang muka pelanggan', [
            'customer' => $this->f->customers[$customer],
            'bank' => $this->f->cash['bca'],
            'receipt_number' => $number,
            'total' => $amount,
        ]);

        $advance('apotek', '25000000', $this->day(14), 'TT-UM-APTK-01');
        $advance('univ', '30000000', $this->day(6), 'TT-UM-UNIV-01');

        $party = $this->f->customers['apotek'];
        $items = $api->as($this->f->users['preparer'])->get($this->f->url('/open-items'), ['filter[party_id]' => $party, 'filter[status]' => 'open', 'per_page' => 200])['data'];
        $payment = collect($items)->first(fn (array $i) => $i['kind'] === 'payment');
        $invoice = collect($items)->first(fn (array $i) => $i['kind'] === 'invoice');

        if ($payment !== null && $invoice !== null) {
            $api->post($this->f->url('/allocations'), [
                'open_item_id' => $payment['id'],
                'counterpart_open_item_id' => $invoice['id'],
                'amount' => (string) min((int) $payment['remaining_amount'], (int) $invoice['remaining_amount']),
                'allocation_date' => $this->day(5),
            ]);
        }
    }

    /** Scanned documents on the entries still being worked on: a posted entry's attachments are closed. */
    private function attachments(): void
    {
        $api = $this->f->api->as($this->f->users['preparer']);
        $pdf = '%PDF-1.4
1 0 obj
<< /Type /Catalog >>
endobj
trailer
<< /Root 1 0 R >>
%%EOF
';

        foreach (['submitted', 'draft'] as $status) {
            foreach (['sales_invoice' => 'faktur-pajak', 'purchase_invoice' => 'tagihan-pemasok'] as $type => $name) {
                $entries = $api->get($this->f->url('/entries'), ['filter[status]' => $status, 'filter[transaction_type_key]' => $type, 'per_page' => 5])['data'];

                foreach ($entries as $i => $entry) {
                    $api->upload($this->f->url("/entries/{$entry['id']}/attachments"), sprintf('%s-%s-%02d.pdf', $name, $status, $i + 1), $pdf);
                }
            }
        }
    }

    private function waitingForApproval(): void
    {
        $api = $this->f->api;
        $branch = $this->f->dimensionValues;
        $sales = fn (string $customer, string $subtotal, string $branchKey, string $date, string $number) => [
            'customer' => $this->f->customers[$customer],
            'invoice_number' => $number,
            'due_date' => CarbonImmutable::parse($date)->addDays(30)->toDateString(),
            'branch' => $branch[$branchKey],
            'department' => $branch['SALES'],
            'subtotal' => $subtotal,
            'ppn' => (string) intdiv((int) $subtotal * 11 + 50, 100),
            'total' => (string) ((int) $subtotal + intdiv((int) $subtotal * 11 + 50, 100)),
            'revenue_account' => $this->f->account('4110'),
        ];

        // Submitted and waiting for the finance manager.
        foreach ([['klinik', '38500000', 'SBY', 'FP-DEMO-0001'], ['lab', '52000000', 'SBY', 'FP-DEMO-0002'], ['hotel', '21800000', 'JKT', 'FP-DEMO-0003']] as [$customer, $subtotal, $b, $number]) {
            $date = $this->day(2);
            $entry = $this->books->draft('sales_invoice', $date, "Penjualan alat kesehatan - menunggu persetujuan {$number}", $sales($customer, $subtotal, $b, $date, $number));
            $this->books->submit($entry['id']);
        }

        // A large invoice: two stages, the first already approved, waiting for the director.
        $date = $this->day(1);
        $big = $this->books->draft('sales_invoice', $date, 'Penjualan paket alat ICU - dua tahap persetujuan', $sales('rsud', '420000000', 'JKT', $date, 'FP-DEMO-0004'));
        $submitted = $this->books->submit($big['id']);
        $api->as($this->f->users['approver'])->post($this->f->url("/entries/{$submitted['id']}/approve"));

        // A supplier bill waiting.
        $bill = $this->books->draft('purchase_invoice', $this->day(1), 'Pembelian barang dagang - menunggu persetujuan', [
            'supplier' => $this->f->suppliers['alkes'], 'bill_number' => 'GAI-DEMO-0001', 'due_date' => $this->today->addDays(29)->toDateString(),
            'subtotal' => '88000000', 'ppn' => '9680000', 'total' => '97680000', 'expense_account' => $this->f->account('1131'),
        ]);
        $this->books->submit($bill['id']);

        // Rejected, with the reason the approver gave.
        $wrong = $this->books->draft('general_journal', $this->day(3), 'Reklasifikasi beban sewa', lines: [
            ['account_id' => $this->f->account('6220'), 'debit' => '3000000'],
            ['account_id' => $this->f->account('2130'), 'credit' => '3000000'],
        ], by: 'preparer');
        $this->books->submit($wrong['id']);
        $api->as($this->f->users['approver'])->post($this->f->url("/entries/{$wrong['id']}/reject"), ['reason' => 'Sewa kantor sudah diamortisasi lewat jurnal berulang; tidak perlu direklasifikasi.']);

        // A draft nobody finished, a draft that was cancelled, and an accrual that reverses itself.
        $this->books->draft('purchase_invoice', $this->day(1), 'Tagihan perbaikan gedung (draf)', [
            'supplier' => $this->f->suppliers['furnitur'], 'bill_number' => 'MEB-DEMO-0001', 'subtotal' => '15000000', 'ppn' => '1650000', 'total' => '16650000', 'expense_account' => $this->f->account('6240'),
        ]);

        $cancelled = $this->books->draft('general_journal', $this->day(4), 'Jurnal salah input (dibatalkan)', lines: [
            ['account_id' => $this->f->account('6230'), 'debit' => '1000000'],
            ['account_id' => $this->f->account('2130'), 'credit' => '1000000'],
        ]);
        $api->as($this->f->users['preparer'])->post($this->f->url("/entries/{$cancelled['id']}/cancel"), ['reason' => 'Salah input, dibuat ulang lewat faktur pemasok.']);

        $reverseOn = $this->today->startOfMonth()->addMonth()->toDateString();
        $this->books->post('adjustment', $this->day(0), 'Akrual listrik dan air bulan berjalan (balik otomatis)', lines: [
            ['account_id' => $this->f->account('6230'), 'debit' => '11800000'],
            ['account_id' => $this->f->account('2120'), 'credit' => '11800000'],
        ], extra: ['auto_reverse_on' => $reverseOn], by: 'accountant');
    }

    private function accessAndPolicies(): void
    {
        $api = $this->f->api->as($this->f->users['owner']);

        // The branch head sees Surabaya only, in every report and list (IA-FR-03).
        $memberships = $api->get($this->f->url('/memberships'))['data'];
        $mine = collect($memberships)->first(fn (array $m) => (int) ($m['user']['id'] ?? $m['user_id'] ?? 0) === (int) $this->f->users['branch']->id);

        if ($mine !== null) {
            $api->patch($this->f->url('/memberships/'.$mine['id']), ['lock_version' => $mine['lock_version'], 'data_scope' => [$this->f->dimensionValues['SBY']]]);
        }

        $api->put($this->f->url('/security-policy'), ['lock_version' => 0, 'two_factor_permissions' => [], 'idle_timeout_minutes' => 240, 'client_ip_allowlist' => []]);

        $aging = $api->as($this->f->users['accountant'])->get($this->f->url('/aging-policy'))['data'];
        $api->put($this->f->url('/aging-policy'), ['lock_version' => $aging['lock_version'], 'basis' => 'due_date', 'buckets' => [30, 60, 90, 120]]);
    }
}
