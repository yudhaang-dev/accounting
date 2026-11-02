<?php

/*
| Report definitions for a company that uses the chart of accounts template `general-id` (suggestion
| only, RP-FR-01, X-AC-03). Account selectors name the parent by its template `code` or a class; the
| import turns them into the IDs of the company's own accounts, so a company whose chart lacks a code
| is told which one. A company reviews the definitions and confirms the import; each becomes version 1
| of a definition it can change like any other. See .docs/CHART-OF-ACCOUNTS.md, "Saran definisi laporan".
|
| Not in the suggestions: an OCI section (an income statement adds up income and expense accounts
| only) and the direct cash flow and the statement of changes in equity, which are classifications.
*/

$parent = fn (string $code): array => ['code' => $code];
$class = fn (string $class): array => ['class' => $class];

// Every account row lists its accounts (RP-FR-01); the indirect cash flow does so for cash only.
$accounts = fn (string $key, string $label, array $selectors, string $sign = 'debit', bool $detail = true): array => ['key' => $key, 'label' => $label, 'type' => 'accounts', 'sign' => $sign, 'accounts' => $selectors, 'account_detail' => $detail];

$subtotal = fn (string $key, string $label, array $terms): array => [
    'key' => $key, 'label' => $label, 'type' => 'subtotal', 'sign' => 'debit',
    'terms' => array_map(fn (array $term): array => ['row' => $term[0], 'sign' => $term[1]], $terms),
];

return [

    'key' => 'general-id',
    'name' => 'Umum Indonesia',
    'version' => 1,

    'definitions' => [
        [
            'key' => 'income_statement',
            'name' => 'Laba Rugi',
            'kind' => 'income_statement',
            'structure' => [
                'basis' => 'movement',
                'rows' => [
                    $accounts('revenue', 'Pendapatan Operasional', [$parent('4110'), $parent('4120'), $parent('4130')], 'credit'),
                    $accounts('revenue_deductions', 'Retur dan Pengurang Pendapatan', [$parent('4190')], 'credit'),
                    $subtotal('net_revenue', 'Pendapatan Bersih', [['revenue', 1], ['revenue_deductions', 1]]),
                    $accounts('cost_of_sales', 'Biaya Langsung / HPP', [$parent('5000')]),
                    $subtotal('gross_profit', 'Laba Kotor', [['net_revenue', 1], ['cost_of_sales', -1]]),
                    $accounts('operating_expenses', 'Beban Operasional', [$parent('6000')]),
                    $subtotal('operating_profit', 'Laba Operasi', [['gross_profit', 1], ['operating_expenses', -1]]),
                    $accounts('other_income', 'Penghasilan Lain', [$parent('7100')], 'credit'),
                    $accounts('other_expenses', 'Beban Lain', [$parent('7200')]),
                    $subtotal('profit_before_tax', 'Laba Sebelum Pajak', [['operating_profit', 1], ['other_income', 1], ['other_expenses', -1]]),
                    $accounts('income_tax', 'Beban Pajak Penghasilan', [$parent('7300')]),
                    $subtotal('net_profit', 'Laba Bersih', [['profit_before_tax', 1], ['income_tax', -1]]),
                ],
                'columns' => [
                    ['key' => 'period', 'label' => 'Periode', 'kind' => 'period'],
                    ['key' => 'ytd', 'label' => 'Tahun Berjalan', 'kind' => 'ytd'],
                ],
            ],
        ],
        [
            'key' => 'balance_sheet',
            'name' => 'Neraca',
            'kind' => 'balance_sheet',
            'structure' => [
                'basis' => 'closing',
                'rows' => [
                    $accounts('current_assets', 'Aset Lancar', [$parent('1100')]),
                    $accounts('non_current_assets', 'Aset Tidak Lancar', [$parent('1200')]),
                    $subtotal('total_assets', 'Total Aset', [['current_assets', 1], ['non_current_assets', 1]]),
                    $accounts('current_liabilities', 'Liabilitas Jangka Pendek', [$parent('2100')], 'credit'),
                    $accounts('non_current_liabilities', 'Liabilitas Jangka Panjang', [$parent('2200')], 'credit'),
                    $accounts('equity', 'Ekuitas', [$parent('3000')], 'credit'),
                    ['key' => 'current_year_earnings', 'label' => 'Laba Tahun Berjalan', 'type' => 'current_year_earnings', 'sign' => 'credit'],
                    $subtotal('total_liabilities_and_equity', 'Total Liabilitas dan Ekuitas', [['current_liabilities', 1], ['non_current_liabilities', 1], ['equity', 1], ['current_year_earnings', 1]]),
                ],
                'columns' => [
                    ['key' => 'at', 'label' => 'Per Tanggal', 'kind' => 'as_of'],
                ],
            ],
        ],
        [
            'key' => 'cash_flow_indirect',
            'name' => 'Arus Kas (Metode Tidak Langsung)',
            'kind' => 'cash_flow_indirect',
            'structure' => [
                'basis' => 'movement',
                'rows' => [
                    $accounts('profit', 'Laba Bersih', [$class('income'), $class('expense')], 'credit', false),
                    $accounts('operating_assets', 'Perubahan Aset Operasi', [$parent('1120'), $parent('1130'), $parent('1140'), $parent('1150'), $parent('1160'), $parent('1170')], 'credit', false),
                    $accounts('operating_liabilities', 'Perubahan Liabilitas Operasi', [$parent('2110'), $parent('2120'), $parent('2130'), $parent('2140'), $parent('2150')], 'credit', false),
                    $subtotal('operating_cash', 'Arus Kas dari Aktivitas Operasi', [['profit', 1], ['operating_assets', 1], ['operating_liabilities', 1]]),
                    $accounts('investing_assets', 'Perubahan Aset Tidak Lancar', [$parent('1200')], 'credit', false),
                    $accounts('financing_debt', 'Perubahan Pinjaman dan Liabilitas Sewa', [$parent('2160'), $parent('2170'), $parent('2200')], 'credit', false),
                    $accounts('financing_equity', 'Perubahan Ekuitas', [$parent('3000')], 'credit', false),
                    $subtotal('financing_cash', 'Arus Kas dari Aktivitas Pendanaan', [['financing_debt', 1], ['financing_equity', 1]]),
                    $subtotal('net_increase', 'Kenaikan (Penurunan) Bersih Kas', [['operating_cash', 1], ['investing_assets', 1], ['financing_cash', 1]]),
                    ['key' => 'cash_opening', 'label' => 'Kas dan Setara Kas Awal', 'type' => 'cash_equivalents', 'sign' => 'debit', 'basis' => 'opening', 'account_detail' => true],
                    ['key' => 'cash_closing', 'label' => 'Kas dan Setara Kas Akhir', 'type' => 'cash_equivalents', 'sign' => 'debit', 'basis' => 'closing', 'account_detail' => true],
                ],
                'columns' => [
                    ['key' => 'period', 'label' => 'Periode', 'kind' => 'period'],
                ],
            ],
        ],
    ],
];
