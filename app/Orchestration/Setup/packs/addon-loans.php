<?php

// The add-on pack for staff advances and loans (ADR-0019). It only adds to the chart and the transaction types a company already
// has: what exists is skipped and reported, and a clash of code is a conflict the user decides, never overwritten.
// Not released (`reviewed` false) until an accountant has gone through the accounts and the transaction types.
return [
    'key' => 'addon-loans',
    'name' => 'Kasbon dan Pinjaman',
    'description' => 'Akun kasbon karyawan, piutang pemegang saham, dan pinjaman dari pihak lain, ditambah jenis transaksi kasbon, pinjaman, dan setoran modal.',
    'version' => 1,
    'reviewed' => false,
    'components' => [
        ['id' => 'chart_of_accounts', 'kind' => 'chart_of_accounts', 'template' => 'loans-addon'],
        ['id' => 'party_roles', 'kind' => 'party_roles', 'template' => 'default'],
        ['id' => 'transaction_types.daily-operations', 'kind' => 'transaction_types', 'template' => 'daily-operations'],
    ],
];
