<?php

// The add-on pack for tax accounts and transaction types, not a tax engine (ADR-0019). It only adds to the chart and the transaction types a company already
// has: what exists is skipped and reported, and a clash of code is a conflict the user decides, never overwritten.
// Not released (`reviewed` false) until an accountant has gone through the accounts and the transaction types.
return [
    'key' => 'addon-tax',
    'name' => 'Pajak',
    'description' => 'Akun utang dan kredit pajak serta jenis transaksi mencatat dan menyetor pajak. Bukan mesin pajak: nilai pajak dihitung di luar sistem.',
    'version' => 1,
    'reviewed' => false,
    'components' => [
        ['id' => 'chart_of_accounts', 'kind' => 'chart_of_accounts', 'template' => 'tax-addon'],
        ['id' => 'transaction_types.tax-operations', 'kind' => 'transaction_types', 'template' => 'tax-operations'],
    ],
];
