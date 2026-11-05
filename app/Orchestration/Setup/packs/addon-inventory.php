<?php

// The add-on pack for inventory adjustments, with no stock per item (ADR-0019). It only adds to the chart and the transaction types a company already
// has: what exists is skipped and reported, and a clash of code is a conflict the user decides, never overwritten.
// Released against the references of ADR-0020 (`reviewed`).
return [
    'key' => 'addon-inventory',
    'name' => 'Persediaan dan HPP',
    'description' => 'Akun penurunan nilai persediaan dan jenis transaksi penyesuaian stok opname. Tanpa stok per item: nilainya dari hitungan di luar sistem.',
    'version' => 1,
    'reviewed' => true,
    'components' => [
        ['id' => 'chart_of_accounts', 'kind' => 'chart_of_accounts', 'template' => 'inventory-addon'],
        ['id' => 'transaction_types.inventory-operations', 'kind' => 'transaction_types', 'template' => 'inventory-operations'],
    ],
];
