<?php

namespace Database\Seeders\Demo;

/**
 * Reporting setup (PANDUAN-AKUNTAN-SETUP 14): the financial statements from the template, the cash and cash
 * equivalents mapping, the classifications behind the direct cash flow and the statement of changes in equity,
 * and an income statement against the budget. Definitions publish a snapshot by themselves when a period or a
 * year closes, so the archive of closed months fills as the seeder closes them.
 */
final class DemoReports
{
    public function __construct(private readonly DemoFoundation $f) {}

    public function run(): void
    {
        $this->f->say('Laporan: definisi dari template, pemetaan kas, klasifikasi, dan laporan terhadap anggaran');

        $api = $this->f->api->as($this->f->users['accountant']);

        $api->post($this->f->url('/report-definitions/import-template'), [
            'template' => 'general-id', 'confirmed' => true, 'keys' => ['income_statement', 'balance_sheet', 'cash_flow_indirect'],
        ]);

        $api->put($this->f->url('/reporting/account-role-mappings/cash_equivalents'), [
            'lock_version' => 0,
            'account_ids' => [$this->f->account('1111'), $this->f->account('1112'), $this->f->account('1113')],
        ]);

        $this->classifications();
        $this->budgetReport();
        $this->publishOnClose();
    }

    /** @return list<array{type: string, id: string}> */
    private function accounts(string ...$codes): array
    {
        return array_values(array_map(fn (string $code) => ['type' => 'account', 'id' => $this->f->account($code)], $codes));
    }

    private function classifications(): void
    {
        $api = $this->f->api;
        $classes = fn (string ...$classes) => array_map(fn (string $class) => ['type' => 'class', 'class' => $class], $classes);

        // Direct cash flow: what each movement of cash was for, by the account on the other side.
        $api->post($this->f->url('/movement-classifications'), [
            'key' => 'arus_kas_langsung', 'name' => 'Arus kas metode langsung',
            'structure' => [
                'rows' => [
                    ['key' => 'operating', 'label' => 'Aktivitas operasi'],
                    ['key' => 'investing', 'label' => 'Aktivitas investasi'],
                    ['key' => 'financing', 'label' => 'Aktivitas pendanaan'],
                ],
                'rules' => [
                    ['row' => 'investing', 'accounts' => $this->accounts('1213', '1214', '1221'), 'transaction_type_keys' => []],
                    ['row' => 'financing', 'accounts' => $this->accounts('2210', '3110', '3150'), 'transaction_type_keys' => []],
                    ['row' => 'operating', 'accounts' => [...$this->accounts('1121', '2110', '1141', '2151'), ...$classes('income', 'expense')], 'transaction_type_keys' => []],
                ],
            ],
        ]);

        // Changes in equity: the result of the year, contributions and distributions, over the equity components.
        $api->post($this->f->url('/movement-classifications'), [
            'key' => 'perubahan_ekuitas', 'name' => 'Laporan perubahan ekuitas',
            'structure' => [
                'rows' => [
                    ['key' => 'profit_for_year', 'label' => 'Laba tahun berjalan'],
                    ['key' => 'contribution', 'label' => 'Setoran modal'],
                    ['key' => 'distribution', 'label' => 'Dividen'],
                ],
                'rules' => [
                    ['row' => 'contribution', 'accounts' => $this->accounts('3110'), 'transaction_type_keys' => []],
                    ['row' => 'distribution', 'accounts' => $this->accounts('3150'), 'transaction_type_keys' => []],
                    ['row' => 'profit_for_year', 'accounts' => $classes('income', 'expense'), 'transaction_type_keys' => []],
                ],
                'components' => [
                    ['key' => 'capital', 'label' => 'Modal disetor', 'accounts' => $this->accounts('3110')],
                    ['key' => 'retained', 'label' => 'Saldo laba', 'accounts' => [...$this->accounts('3130'), ...$classes('income', 'expense')]],
                    ['key' => 'dividends', 'label' => 'Dividen', 'accounts' => $this->accounts('3150')],
                ],
            ],
        ]);
    }

    /** Revenue, cost of goods, expenses and profit, against the budget version in force (RP-FR-11). */
    private function budgetReport(): void
    {
        $accounts = fn (string $sign, string ...$codes) => ['sign' => $sign, 'accounts' => $this->accounts(...$codes)];

        $this->f->api->post($this->f->url('/report-definitions'), [
            'key' => 'laba_rugi_vs_anggaran', 'name' => 'Laba rugi terhadap anggaran', 'kind' => 'income_statement',
            'structure' => [
                'basis' => 'movement',
                'rows' => [
                    ['key' => 'revenue', 'label' => 'Pendapatan', 'type' => 'accounts', ...$accounts('credit', '4110', '4120')],
                    ['key' => 'cost_of_goods', 'label' => 'Harga pokok penjualan', 'type' => 'accounts', ...$accounts('debit', '5110')],
                    ['key' => 'gross_profit', 'label' => 'Laba kotor', 'type' => 'subtotal', 'sign' => 'debit', 'favorable' => 'higher', 'terms' => [['row' => 'revenue', 'sign' => 1], ['row' => 'cost_of_goods', 'sign' => -1]]],
                    ['key' => 'payroll', 'label' => 'Gaji dan tunjangan', 'type' => 'accounts', ...$accounts('debit', '6210')],
                    ['key' => 'selling', 'label' => 'Penjualan dan pemasaran', 'type' => 'accounts', ...$accounts('debit', '6110', '6130')],
                    ['key' => 'admin', 'label' => 'Umum dan administrasi', 'type' => 'accounts', ...$accounts('debit', '6220', '6230', '6240', '6260', '6270', '6280')],
                    ['key' => 'depreciation', 'label' => 'Penyusutan', 'type' => 'accounts', ...$accounts('debit', '6310')],
                    ['key' => 'interest', 'label' => 'Beban bunga', 'type' => 'accounts', ...$accounts('debit', '7210')],
                    ['key' => 'profit', 'label' => 'Laba sebelum pajak', 'type' => 'subtotal', 'sign' => 'debit', 'favorable' => 'higher', 'terms' => [
                        ['row' => 'gross_profit', 'sign' => 1], ['row' => 'payroll', 'sign' => -1], ['row' => 'selling', 'sign' => -1],
                        ['row' => 'admin', 'sign' => -1], ['row' => 'depreciation', 'sign' => -1], ['row' => 'interest', 'sign' => -1],
                    ]],
                ],
                'columns' => [
                    ['key' => 'actual', 'label' => 'Realisasi', 'kind' => 'period'],
                    ['key' => 'plan', 'label' => 'Anggaran', 'kind' => 'plan'],
                    ['key' => 'variance', 'label' => 'Selisih', 'kind' => 'difference', 'minuend' => 'actual', 'subtrahend' => 'plan'],
                ],
            ],
        ]);
    }

    /** Snapshots of closed months and years are published as the periods close (RP-FR-14). */
    private function publishOnClose(): void
    {
        foreach ($this->f->api->get($this->f->url('/report-definitions'))['data'] as $definition) {
            if (! in_array($definition['key'], ['income_statement', 'balance_sheet', 'cash_flow_indirect', 'laba_rugi_vs_anggaran'], true)) {
                continue;
            }

            $this->f->api->put($this->f->url('/report-definitions/'.$definition['id']), [
                'lock_version' => $definition['lock_version'],
                'publish_on_period_close' => true,
                'publish_on_year_close' => true,
            ]);
        }
    }
}
