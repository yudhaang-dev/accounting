<?php

// Suggested transaction types for inventory adjustments (TE-FR-01). Nothing here is applied to a company without a
// confirmed import. The system keeps no stock per item (ADR-0019): the value comes from a count or a costing done
// outside the system, and these types only post it. The user picks the accounts, since the template cannot know
// the chart of accounts of a company.
return [
    'key' => 'inventory-operations',
    'name' => 'Transaksi penyesuaian persediaan',
    'version' => 1,
    'types' => [
        [
            'key' => 'stock_count_adjustment',
            'label' => 'Penyesuaian Stok Opname',
            'description' => 'Jurnal bebas untuk menyesuaikan persediaan dengan hasil hitung fisik atau perhitungan HPP di luar sistem. Untuk akuntan.',
            'mode' => 'free_lines',
            'fields' => [],
            'posting_rules' => [],
        ],
        [
            'key' => 'inventory_write_down',
            'label' => 'Penurunan Nilai Persediaan',
            'description' => 'Mencatat barang rusak, kedaluwarsa, atau hilang: beban bertambah, persediaan atau cadangannya berkurang.',
            'mode' => 'structured',
            'fields' => [
                ['name' => 'adjustment_date', 'type' => 'date', 'label' => 'Tanggal'],
                ['name' => 'total', 'type' => 'amount', 'label' => 'Nilai penurunan'],
                ['name' => 'expense_account', 'type' => 'account', 'label' => 'Akun beban penurunan nilai'],
                ['name' => 'inventory_account', 'type' => 'account', 'label' => 'Akun persediaan atau cadangan'],
                ['name' => 'note', 'type' => 'text', 'label' => 'Catatan', 'required' => false],
            ],
            'posting_rules' => [
                ['type' => 'amount', 'side' => 'debit', 'amount_field' => 'total', 'account' => ['source' => 'field', 'field' => 'expense_account'], 'apply_dimensions' => true, 'reuses_field' => true],
                ['type' => 'amount', 'side' => 'credit', 'amount_field' => 'total', 'account' => ['source' => 'field', 'field' => 'inventory_account'], 'apply_dimensions' => true, 'reuses_field' => true],
            ],
        ],
    ],
];
