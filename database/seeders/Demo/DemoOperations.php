<?php

namespace Database\Seeders\Demo;

use App\Modules\TransactionEntry\Application\RecurringSchedules;
use App\Support\Company\CompanyContext;
use Carbon\CarbonImmutable;
use ReflectionMethod;

/**
 * A month of trading for PT Sinar Nusantara Perkasa, a distributor of medical and office equipment: credit
 * sales with VAT to hospitals, clinics and shops across three branches, goods and expense bills from suppliers,
 * collections and payments (combined, partial, late, and some never in time), payroll, VAT settlement, petty
 * cash, bank charges and interest, and the monthly cost of goods sold. The figures come from a fixed seed, so
 * every run tells the same story.
 *
 * Future payments stay open: that is what gives the aging report, party balances, and open items their shape.
 */
final class DemoOperations
{
    /** @var list<array<string, mixed>> */
    private array $queue = [];

    /** @var array<string, array{party: string, total: int, paid: int}> invoices by document number */
    private array $invoices = [];

    /** @var array<string, array{party: string, total: int, paid: int}> */
    private array $bills = [];

    /** @var array<string, array<string, string>> party id => document number => open item id */
    private array $openItems = [];

    /** @var array<string, array{out: int, in: int}> */
    private array $vat = [];

    /** @var array<string, int> goods invoiced per month, the base of the cost of goods sold */
    public array $goodsSold = [];

    private int $invoiceSeq = 0;

    private int $billSeq = 0;

    private int $receiptSeq = 0;

    private int $paymentSeq = 0;

    /** @var list<string> */
    private array $schedules = [];

    private int $monthIndex = 0;

    public int $entries = 0;

    private readonly DemoBooks $books;

    public function __construct(private readonly DemoFoundation $f)
    {
        $this->books = new DemoBooks($f);
    }

    /** The receivables and payables brought over from the old system get collected and paid in January and February. */
    public function seedOpening(): void
    {
        $c = $this->f->customers;
        $s = $this->f->suppliers;

        $this->invoices['INV-2024-0987'] = ['party' => $c['rsud'], 'total' => 200_000_000, 'paid' => 0];
        $this->invoices['INV-2024-0991'] = ['party' => $c['toko'], 'total' => 130_000_000, 'paid' => 0];
        $this->bills['BILL-2024-311'] = ['party' => $s['alkes'], 'total' => 185_000_000, 'paid' => 0];
        $this->bills['BILL-2024-120'] = ['party' => $s['furnitur'], 'total' => 100_000_000, 'paid' => 0];

        $this->collect('rsud', $c['rsud'], CarbonImmutable::parse('2025-01-20'), 'INV-2024-0987', 200_000_000);
        $this->collect('toko', $c['toko'], CarbonImmutable::parse('2025-02-10'), 'INV-2024-0991', 130_000_000);
        $this->settle('alkes', $s['alkes'], CarbonImmutable::parse('2025-01-10'), 'BILL-2024-311', 185_000_000, 'bca');
        $this->settle('furnitur', $s['furnitur'], CarbonImmutable::parse('2025-01-21'), 'BILL-2024-120', 100_000_000, 'bca');
    }

    /**
     * Depreciation and the amortisation of prepaid rent as monthly recurring journals (TE-FR-14). Their first
     * run is the last day of January 2025.
     */
    public function schedules(): void
    {
        $this->f->say('Jurnal berulang: penyusutan dan amortisasi sewa dibayar di muka');

        $api = $this->f->api->as($this->f->users['preparer']);

        foreach ([
            ['Penyusutan aset tetap bulanan', 'Penyusutan peralatan, komputer, dan kendaraan', '6310', '1219', '12500000'],
            ['Amortisasi sewa dibayar di muka', 'Amortisasi sewa gedung kantor', '6220', '1150', '3000000'],
        ] as [$name, $description, $debit, $credit, $amount]) {
            $this->schedules[] = $api->post($this->f->url('/recurring-schedules'), [
                'name' => $name,
                'transaction_type_key' => 'adjustment',
                'frequency' => 'monthly',
                'start_date' => '2025-01-31',
                'initial_status' => 'submitted',
                'template' => [
                    'description' => $description,
                    'lines' => [
                        ['account_id' => $this->f->account($debit), 'debit' => $amount],
                        ['account_id' => $this->f->account($credit), 'credit' => $amount],
                    ],
                ],
            ])['data']['id'];
        }
    }

    /**
     * Runs the schedules that are due, which is what the scheduler does every night, then carries the entries
     * they made through approval. Only this company's schedules run, whatever else the database holds.
     */
    private function runSchedules(CarbonImmutable $date): void
    {
        $service = app(RecurringSchedules::class);
        $run = new ReflectionMethod($service, 'runSchedule');

        foreach ($this->schedules as $id) {
            app(CompanyContext::class)->run($this->f->companyId, fn () => $run->invoke($service, $id, $date->toDateString()));
        }

        foreach (['draft', 'submitted'] as $status) {
            $list = $this->f->api->as($this->f->users['preparer'])->get($this->f->url('/entries'), ['filter[status]' => $status, 'per_page' => 200])['data'];

            foreach ($list as $entry) {
                if ($entry['source'] !== 'recurring') {
                    continue;
                }

                $entry = $status === 'draft' ? $this->books->submit($entry['id'], 'preparer') : $entry;
                $this->books->approve($entry);
                $this->entries++;
            }
        }
    }

    public function books(): DemoBooks
    {
        return $this->books;
    }

    /** Plans and posts everything dated in the month, up to today. */
    public function month(CarbonImmutable $first): void
    {
        mt_srand(20250101 + $this->monthIndex * 7919);

        $last = $first->endOfMonth()->startOfDay();
        $until = $last->min($this->f->today);

        $this->plan($first, $last);
        $this->process($first, $until);
        $this->runSchedules($until);
        $this->monthEnd($until, $first);
        $this->monthIndex++;
    }

    // ---------------------------------------------------------------- planning

    private function plan(CarbonImmutable $first, CarbonImmutable $last): void
    {
        $growth = 1 + 0.017 * $this->monthIndex;
        $season = match ($first->month) {
            1, 2 => 0.93, 11, 12 => 1.16, 6, 7 => 0.97, default => 1.0,
        };
        $scale = $growth * $season;

        // Sales: goods to institutions, and a few service jobs.
        $goods = 0;
        foreach (range(1, mt_rand(11, 14)) as $i) {
            $subtotal = $this->round((int) (mt_rand(14, 92) * 1_000_000 * $scale), 1000);
            $goods += $subtotal;
            $this->push($first->addDays(mt_rand(0, 26)), 0, 'invoice', ['revenue' => '4110', 'subtotal' => $subtotal]);
        }

        foreach (range(1, mt_rand(2, 4)) as $i) {
            $this->push($first->addDays(mt_rand(0, 26)), 0, 'invoice', ['revenue' => '4120', 'subtotal' => $this->round((int) (mt_rand(7, 38) * 1_000_000 * $scale), 1000)]);
        }

        $planned = $goods;

        // Purchases of goods follow what was sold.
        $target = (int) ($planned * 0.66 * (mt_rand(92, 108) / 100));
        $parts = mt_rand(6, 8);
        $weights = array_map(fn () => mt_rand(60, 140), range(1, $parts));
        foreach ($weights as $w) {
            $subtotal = $this->round((int) ($target * $w / array_sum($weights)), 1000);
            $supplier = $this->pick(['alkes' => 60, 'it' => 25, 'furnitur' => 15]);
            $this->push($first->addDays(mt_rand(0, 25)), 0, 'bill', ['supplier' => $supplier, 'account' => '1131', 'subtotal' => $subtotal, 'ppn' => true, 'terms' => $supplier === 'alkes' ? 30 : 45, 'what' => 'Pembelian barang dagang']);
        }

        // Running costs.
        $revenueGuess = $planned * 1.12;
        $this->push($first->addDays(14), 0, 'bill', ['supplier' => 'listrik', 'account' => '6230', 'subtotal' => $this->round(mt_rand(10_200_000, 12_400_000), 1000), 'ppn' => true, 'terms' => 7, 'what' => 'Listrik kantor dan gudang', 'dept' => 'OPS']);
        $this->push($first->addDays(9), 0, 'bill', ['supplier' => 'telkom', 'account' => '6230', 'subtotal' => $this->round(mt_rand(6_100_000, 6_900_000), 1000), 'ppn' => true, 'terms' => 14, 'what' => 'Internet dan telepon', 'dept' => 'ADM']);
        $this->push($first->addDays(4), 0, 'bill', ['supplier' => 'it', 'account' => '6270', 'subtotal' => 4_800_000, 'ppn' => true, 'terms' => 30, 'what' => 'Langganan perangkat lunak akuntansi dan CRM', 'dept' => 'ADM']);
        $this->push($last->subDays(2), 0, 'bill', ['supplier' => 'logistik', 'account' => '6130', 'subtotal' => $this->round((int) ($revenueGuess * 0.011), 1000), 'ppn' => true, 'terms' => 14, 'what' => 'Pengiriman barang ke pelanggan', 'dept' => 'OPS']);
        $this->push($first->addDays(mt_rand(5, 20)), 0, 'bill', ['supplier' => 'iklan', 'account' => '6110', 'subtotal' => $this->round(($first->year >= 2026 ? mt_rand(15, 32) : mt_rand(11, 26)) * 1_000_000, 1000), 'ppn' => true, 'terms' => 21, 'what' => 'Iklan dan promosi digital', 'dept' => 'SALES']);

        // The promotion study: the digital campaign (a tactic) creeps up month by month, printed material and
        // exhibitions (the strategy itself) stay flat.
        if ($this->inPromotion($first)) {
            $step = $first->month - (int) substr(DemoFoundation::PROMO_FROM, 5);
            $this->push($first->addDays(8), 0, 'bill', ['supplier' => 'iklan', 'account' => '6110', 'subtotal' => (18 + 4 * $step) * 1_000_000, 'ppn' => true, 'terms' => 21, 'what' => 'Kampanye iklan digital promo Mei–Oktober', 'dept' => 'SALES', 'activity' => 'PROMO-DIG']);
            $this->push($first->addDays(12), 0, 'bill', ['supplier' => 'iklan', 'account' => '6110', 'subtotal' => 8_000_000, 'ppn' => true, 'terms' => 21, 'what' => 'Materi cetak dan pameran promo Mei–Oktober', 'dept' => 'SALES', 'activity' => 'PROMO']);
        }

        $this->push($first->addDays(2), 0, 'bill', ['supplier' => 'atk', 'account' => '6240', 'subtotal' => $this->round(mt_rand(1_800_000, 3_100_000), 1000), 'ppn' => false, 'terms' => 0, 'what' => 'Alat tulis dan perlengkapan kantor', 'dept' => 'ADM', 'from' => 'petty']);
        $this->push($last, 0, 'bill', ['supplier' => 'bank', 'account' => '6280', 'subtotal' => 175_000, 'ppn' => false, 'terms' => 0, 'what' => 'Biaya administrasi bank', 'dept' => 'ADM']);
        $this->push($last, 0, 'bill', ['supplier' => 'bank', 'account' => '7210', 'subtotal' => 2_500_000, 'ppn' => false, 'terms' => 0, 'what' => 'Bunga pinjaman bank', 'dept' => 'ADM']);

        if (in_array($first->month, [3, 6, 9, 12], true)) {
            $this->push($first->addDays(19), 0, 'bill', ['supplier' => 'konsultan', 'account' => '6260', 'subtotal' => 18_000_000, 'ppn' => true, 'terms' => 14, 'what' => 'Jasa audit dan konsultasi pajak triwulanan', 'dept' => 'ADM']);
        }

        // The dividend of 2025, decided at the shareholders' meeting: two approvals, paid five days later.
        if ($first->year === 2026 && $first->month === 4) {
            $this->push($first->addDays(19), 0, 'bill', ['supplier' => 'saham', 'account' => '3150', 'subtotal' => 500_000_000, 'ppn' => false, 'terms' => 5, 'what' => 'Pembagian dividen tahun buku 2025', 'dept' => 'ADM']);
        }

        if ($first->month === 1 && $first->year >= 2026) {
            $this->push($first->addDays(5), 0, 'bill', ['supplier' => 'sewa', 'account' => '1150', 'subtotal' => 36_000_000, 'ppn' => false, 'terms' => 7, 'what' => 'Sewa gedung kantor dibayar di muka 12 bulan', 'dept' => 'ADM']);
        }

        // Payroll on the 25th, paid on the 28th (or the last day of a short month).
        $gross = $first->year >= 2026 ? mt_rand(138, 142) * 1_000_000 : mt_rand(118, 122) * 1_000_000;
        $this->push($first->addDays(24), 0, 'payroll', ['gross' => $gross]);

        // Cash moves between accounts.
        $this->push($first->addDays(2), 1, 'transfer', ['from' => 'bca', 'to' => 'petty', 'amount' => 8_000_000]);

        if ($first->month % 3 === 0) {
            $this->push($first->addDays(17), 1, 'transfer', ['from' => 'bca', 'to' => 'mandiri', 'amount' => 100_000_000]);
        }

        // VAT of the month before is settled on the 15th.
        $previous = $first->subMonth()->format('Y-m');
        if (isset($this->vat[$previous]) && $this->vat[$previous]['out'] > $this->vat[$previous]['in']) {
            $this->push($first->addDays(14), 1, 'vat', ['month' => $previous]);
        }
    }

    private function inPromotion(CarbonImmutable $date): bool
    {
        $month = $date->format('Y-m');

        return $month >= DemoFoundation::PROMO_FROM && $month <= DemoFoundation::PROMO_TO;
    }

    // ------------------------------------------------------------- processing

    private function process(CarbonImmutable $first, CarbonImmutable $until): void
    {
        while (true) {
            $due = array_filter($this->queue, fn (array $e) => $e['date'] >= $first->toDateString() && $e['date'] <= $until->toDateString());

            if ($due === []) {
                break;
            }

            usort($due, fn (array $a, array $b) => [$a['date'], $a['order'], $a['n']] <=> [$b['date'], $b['order'], $b['n']]);
            $event = $due[0];
            $this->queue = array_values(array_filter($this->queue, fn (array $e) => $e['n'] !== $event['n']));

            match ($event['kind']) {
                'invoice' => $this->invoice($event),
                'receipt' => $this->receipt($event),
                'bill' => $this->bill($event),
                'payment' => $this->payment($event),
                'payroll' => $this->payroll($event),
                'transfer' => $this->transfer($event),
                'vat' => $this->vatSettlement($event),
                default => throw new \LogicException("Unknown demo event kind {$event['kind']}."),
            };
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function push(CarbonImmutable $date, int $order, string $kind, array $data): array
    {
        static $n = 0;
        $event = ['n' => ++$n, 'date' => $date->toDateString(), 'order' => $order, 'kind' => $kind, ...$data];
        $this->queue[] = $event;

        return $event;
    }

    // --------------------------------------------------------------- handlers

    /** @param array<string, mixed> $e */
    private function invoice(array $e): void
    {
        $customers = array_keys($this->f->customers);
        $key = $this->pick(['rsud' => 22, 'klinik' => 14, 'toko' => 12, 'apotek' => 14, 'lab' => 10, 'univ' => 8, 'hotel' => 8, 'dinkes' => 12]);
        $party = $this->f->customers[$key];
        $subtotal = (int) $e['subtotal'];
        $ppn = intdiv($subtotal * 11 + 50, 100);
        $number = sprintf('FP-%s-%04d', substr($e['date'], 0, 4), ++$this->invoiceSeq);
        $branch = $this->pick(['JKT' => 50, 'SBY' => 30, 'MDN' => 20]);
        $slow = in_array($key, ['rsud', 'dinkes', 'univ'], true);
        $terms = $slow ? 45 : 30;
        $date = CarbonImmutable::parse($e['date']);
        $goods = $e['revenue'] === '4110';

        // During the promotion study every third sale of goods is counted to the campaign.
        $promo = $goods && $this->inPromotion($date) && $this->invoiceSeq % 3 === 0;

        $this->post('sales_invoice', $e['date'], ($goods ? 'Penjualan alat kesehatan dan perlengkapan' : 'Jasa instalasi dan kalibrasi').' - '.$this->customerName($key), array_filter([
            'customer' => $party,
            'invoice_number' => $number,
            'due_date' => $date->addDays($terms)->toDateString(),
            'branch' => $this->f->dimensionValues[$branch],
            'department' => $this->f->dimensionValues['SALES'],
            'activity' => $promo ? $this->f->dimensionValues['PROMO'] : null,
            'subtotal' => (string) $subtotal,
            'ppn' => (string) $ppn,
            'total' => (string) ($subtotal + $ppn),
            'revenue_account' => $this->f->account($e['revenue']),
        ], fn ($v) => $v !== null));

        if ($goods) {
            $this->goodsSold[$date->format('Y-m')] = ($this->goodsSold[$date->format('Y-m')] ?? 0) + $subtotal;
        }

        $this->vat[$date->format('Y-m')]['out'] = ($this->vat[$date->format('Y-m')]['out'] ?? 0) + $ppn;
        $this->vat[$date->format('Y-m')]['in'] ??= 0;
        $total = $subtotal + $ppn;
        $this->invoices[$number] = ['party' => $party, 'total' => $total, 'paid' => 0];

        // How it will be collected: mostly near the due date, some in two parts, some very late.
        $roll = mt_rand(1, 100);
        $disputed = in_array($key, ['hotel', 'univ', 'dinkes'], true) && $date->toDateString() >= '2026-02-01' && $date->toDateString() <= '2026-07-31' && mt_rand(1, 100) <= 18;
        $payDay = fn (CarbonImmutable $from) => $this->snap($from);
        if ($disputed) {
            // Disputed or waiting for a budget decision: still open, and ageing.
        } elseif ($roll <= 8) {
            $when = $payDay($date->addDays($terms + mt_rand(75, 150)));
            $this->collect($key, $party, $when, $number, $total);
        } elseif ($roll <= 20) {
            $first = intdiv($total * 6, 10 * 1000) * 1000;
            $this->collect($key, $party, $payDay($date->addDays($terms + mt_rand(-5, 10))), $number, $first);
            $this->collect($key, $party, $payDay($date->addDays($terms + mt_rand(30, 55))), $number, $total - $first);
        } else {
            $this->collect($key, $party, $payDay($date->addDays($terms + mt_rand(-8, 30))), $number, $total);
        }
    }

    /** The customer's usual payment days: the 10th, 20th and 28th. */
    private function snap(CarbonImmutable $date): CarbonImmutable
    {
        foreach ([10, 20, 28] as $day) {
            if ($date->day <= $day) {
                return $date->setDay(min($day, $date->daysInMonth));
            }
        }

        return $date->addMonthNoOverflow()->setDay(10);
    }

    private function collect(string $key, string $party, CarbonImmutable $date, string $number, int $amount): void
    {
        $date = $date->max(CarbonImmutable::parse(DemoFoundation::START));

        if ($date->toDateString() > $this->f->today->toDateString()) {
            return;
        }

        foreach ($this->queue as &$event) {
            if ($event['kind'] === 'receipt' && $event['party'] === $party && $event['date'] === $date->toDateString()) {
                $event['allocations'][] = [$number, $amount];

                return;
            }
        }

        unset($event);
        $this->push($date, 1, 'receipt', ['party' => $party, 'key' => $key, 'allocations' => [[$number, $amount]], 'bank' => mt_rand(1, 100) <= 97 ? 'bca' : 'mandiri']);
    }

    /** @param array<string, mixed> $e */
    private function receipt(array $e): void
    {
        $allocations = [];
        $total = 0;

        foreach ($e['allocations'] as [$number, $amount]) {
            $allocations[] = ['open_item_id' => $this->openItem($e['party'], $number), 'amount' => (string) $amount];
            $total += $amount;
            $this->invoices[$number]['paid'] += $amount;
        }

        $this->post('customer_receipt', $e['date'], 'Penerimaan pembayaran '.$this->customerName($e['key']), [
            'customer' => $e['party'],
            'bank' => $this->f->cash[$e['bank']],
            'receipt_number' => sprintf('TT-%s-%04d', substr($e['date'], 0, 4), ++$this->receiptSeq),
            'total' => (string) $total,
            'allocations' => $allocations,
        ], by: 'preparer');
    }

    /** @param array<string, mixed> $e */
    private function bill(array $e): void
    {
        $supplier = $this->f->suppliers[$e['supplier']];
        $subtotal = (int) $e['subtotal'];
        $ppn = $e['ppn'] ? intdiv($subtotal * 11 + 50, 100) : 0;
        $total = $subtotal + $ppn;
        $number = sprintf('%s-%s-%04d', strtoupper(substr($e['supplier'], 0, 3)), substr($e['date'], 2, 2).substr($e['date'], 5, 2), ++$this->billSeq);
        $date = CarbonImmutable::parse($e['date']);
        $terms = (int) $e['terms'];

        $this->post('purchase_invoice', $e['date'], $e['what'].' - '.$this->supplierName($e['supplier']), array_filter([
            'supplier' => $supplier,
            'bill_number' => $number,
            'due_date' => $date->addDays($terms)->toDateString(),
            'department' => isset($e['dept']) ? $this->f->dimensionValues[$e['dept']] : null,
            'activity' => isset($e['activity']) ? $this->f->dimensionValues[$e['activity']] : null,
            'subtotal' => (string) $subtotal,
            'ppn' => (string) $ppn,
            'total' => (string) $total,
            'expense_account' => $this->f->account($e['account']),
        ], fn ($v) => $v !== null));

        $month = $date->format('Y-m');
        $this->vat[$month]['in'] = ($this->vat[$month]['in'] ?? 0) + $ppn;
        $this->vat[$month]['out'] ??= 0;
        $this->bills[$number] = ['party' => $supplier, 'total' => $total, 'paid' => 0];

        // Paid on the due date, give or take a few days; cash expenses at once, from petty cash where said.
        $payOn = $date->addDays($terms + ($terms > 7 ? mt_rand(-3, 4) : 0));

        // Furniture delivered in the middle of 2026 is held back until the supplier fixes it.
        if ($e['supplier'] === 'furnitur' && $e['date'] >= '2026-06-01' && $e['date'] <= '2026-08-31') {
            return;
        }

        $this->settle($e['supplier'], $supplier, $payOn, $number, $total, $e['from'] ?? 'bca');
    }

    private function settle(string $key, string $party, CarbonImmutable $date, string $number, int $amount, string $from): void
    {
        if ($date->toDateString() > $this->f->today->toDateString()) {
            return;
        }

        foreach ($this->queue as &$event) {
            if ($event['kind'] === 'payment' && $event['party'] === $party && $event['date'] === $date->toDateString() && $event['from'] === $from) {
                $event['allocations'][] = [$number, $amount];

                return;
            }
        }

        unset($event);
        $this->push($date, 1, 'payment', ['party' => $party, 'key' => $key, 'from' => $from, 'allocations' => [[$number, $amount]]]);
    }

    /** @param array<string, mixed> $e */
    private function payment(array $e): void
    {
        $allocations = [];
        $total = 0;

        foreach ($e['allocations'] as [$number, $amount]) {
            $allocations[] = ['open_item_id' => $this->openItem($e['party'], $number), 'amount' => (string) $amount];
            $total += $amount;
            $this->bills[$number]['paid'] += $amount;
        }

        $this->post('supplier_payment', $e['date'], 'Pembayaran '.$this->supplierName($e['key']), [
            'supplier' => $e['party'],
            'bank' => $this->f->cash[$e['from']],
            'payment_number' => sprintf('PY-%s-%04d', substr($e['date'], 0, 4), ++$this->paymentSeq),
            'total' => (string) $total,
            'allocations' => $allocations,
        ]);
    }

    /** @param array<string, mixed> $e */
    private function payroll(array $e): void
    {
        $date = CarbonImmutable::parse($e['date']);
        $payDate = $date->addDays(3)->min($date->endOfMonth());
        $number = 'GAJI-'.$date->format('Y-m');
        $gross = (int) $e['gross'];

        $this->post('payroll', $e['date'], 'Gaji dan tunjangan karyawan '.$date->translatedFormat('F Y'), [
            'payroll' => $this->f->suppliers['payroll'],
            'reference' => $number,
            'pay_date' => $payDate->toDateString(),
            'department' => $this->f->dimensionValues['OPS'],
            'gross' => (string) $gross,
        ]);

        $this->bills[$number] = ['party' => $this->f->suppliers['payroll'], 'total' => $gross, 'paid' => 0];
        $this->settle('payroll', $this->f->suppliers['payroll'], $payDate, $number, $gross, 'bca');
    }

    /** @param array<string, mixed> $e */
    private function transfer(array $e): void
    {
        $amount = (string) $e['amount'];

        $this->post('cash_transfer', $e['date'], 'Transfer dari '.$e['from'].' ke '.$e['to'], [
            'from' => $this->f->cash[$e['from']],
            'to' => $this->f->cash[$e['to']],
            'amount_out' => $amount,
            'amount_in' => $amount,
        ]);
    }

    /** @param array<string, mixed> $e */
    private function vatSettlement(array $e): void
    {
        $vat = $this->vat[$e['month']];
        $net = $vat['out'] - $vat['in'];
        $date = CarbonImmutable::parse($e['date']);
        $number = 'SSP-'.$e['month'];
        $kpp = $this->f->suppliers['kpp'];

        $this->post('vat_settlement', $e['date'], 'Penyelesaian PPN masa '.CarbonImmutable::parse($e['month'].'-01')->translatedFormat('F Y'), [
            'tax_office' => $kpp,
            'reference' => $number,
            'due_date' => $date->setDay(20)->toDateString(),
            'output' => (string) $vat['out'],
            'input' => (string) $vat['in'],
            'net' => (string) $net,
        ]);

        $this->bills[$number] = ['party' => $kpp, 'total' => $net, 'paid' => 0];
        $this->settle('kpp', $kpp, $date->addDays(3), $number, $net, 'bca');
    }

    // ---------------------------------------------------------------- month end

    /** The month's cost of goods sold, from what was sold at the usual margin. */
    private function monthEnd(CarbonImmutable $until, CarbonImmutable $first): void
    {
        $goods = $this->goodsSold[$first->format('Y-m')] ?? 0;
        $cogs = $this->round((int) ($goods * 0.66), 1000);

        $this->books->post(
            'adjustment',
            $until->toDateString(),
            'Harga pokok penjualan '.$first->translatedFormat('F Y'),
            lines: [
                ['account_id' => $this->f->account('5110'), 'debit' => (string) $cogs, 'description' => 'HPP barang terjual'],
                ['account_id' => $this->f->account('1131'), 'credit' => (string) $cogs, 'description' => 'Pengurang persediaan'],
            ],
            by: 'accountant',
        );
        $this->entries++;
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function post(string $type, string $date, string $description, array $values, string $by = 'preparer'): array
    {
        $this->entries++;

        return $this->books->post($type, $date, $description, $values, by: $by);
    }

    private function openItem(string $party, string $number): string
    {
        if (! isset($this->openItems[$party][$number])) {
            $page = 1;

            do {
                $list = $this->f->api->as($this->f->users['preparer'])->get($this->f->url('/open-items'), ['filter[party_id]' => $party, 'filter[status]' => 'open', 'per_page' => 200, 'page' => $page]);

                foreach ($list['data'] as $item) {
                    $this->openItems[$party][$item['document_number']] = $item['id'];
                }

                $page++;
            } while (($list['meta']['current_page'] ?? 1) < ($list['meta']['last_page'] ?? 1));
        }

        return $this->openItems[$party][$number] ?? throw new \RuntimeException("No open item {$number} for the party.");
    }

    public function forgetOpenItems(): void
    {
        $this->openItems = [];
    }

    private function round(int $value, int $to): int
    {
        return (int) (round($value / $to) * $to);
    }

    /** @param array<string, int> $weights */
    private function pick(array $weights): string
    {
        $roll = mt_rand(1, array_sum($weights));

        foreach ($weights as $key => $weight) {
            if (($roll -= $weight) <= 0) {
                return (string) $key;
            }
        }

        return (string) array_key_first($weights);
    }

    private function customerName(string $key): string
    {
        return match ($key) {
            'rsud' => 'RSUD Harapan Sehat', 'klinik' => 'Klinik Pratama Mitra Sehat', 'toko' => 'CV Toko Medika Jaya',
            'apotek' => 'PT Apotek Sejahtera', 'lab' => 'PT Laboratorium Nusantara', 'univ' => 'Universitas Bina Insani',
            'hotel' => 'PT Hotel Grand Melati', default => 'Dinas Kesehatan Kota',
        };
    }

    private function supplierName(string $key): string
    {
        return match ($key) {
            'alkes' => 'PT Global Alkes Indonesia', 'furnitur' => 'CV Mebel Prima Karya', 'it' => 'PT Teknologi Data Mandiri',
            'logistik' => 'PT Cepat Logistik Nusantara', 'sewa' => 'Bapak Hartono', 'listrik' => 'PT PLN', 'telkom' => 'PT Telkom',
            'konsultan' => 'KAP Rahman dan Rekan', 'iklan' => 'PT Kreasi Iklan Digital', 'bank' => 'Bank BCA', 'kpp' => 'KPP Pratama',
            'atk' => 'CV Sumber ATK Mandiri', 'saham' => 'para pemegang saham', default => 'Payroll Karyawan',
        };
    }
}
