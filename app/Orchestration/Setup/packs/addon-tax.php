<?php

// The add-on pack for tax accounts and transaction types, not a tax engine (ADR-0019). It only adds to the chart and the transaction types a company already
// has: what exists is skipped and reported, and a clash of code is a conflict the user decides, never overwritten.
// Released against the references of ADR-0020 (`reviewed`).
return [
    'key' => 'addon-tax',
    'name' => 'Pajak',
    'description' => 'Akun utang dan kredit pajak serta jenis transaksi mencatat dan menyetor pajak. Bukan mesin pajak: nilai pajak dihitung di luar sistem.',
    'version' => 1,
    'reviewed' => true,
    'additive' => true,
    'components' => [
        ['id' => 'chart_of_accounts', 'kind' => 'chart_of_accounts', 'template' => 'tax-addon'],
        ['id' => 'transaction_types.tax-operations', 'kind' => 'transaction_types', 'template' => 'tax-operations'],
    ],
];
