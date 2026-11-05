<?php

// The add-on pack for project revenue and advances (ADR-0019). It only adds to the chart and the transaction types a company already
// has: what exists is skipped and reported, and a clash of code is a conflict the user decides, never overwritten.
// Released against the references of ADR-0020 (`reviewed`).
return [
    'key' => 'addon-project',
    'name' => 'Proyek',
    'description' => 'Akun pendapatan proyek, uang muka proyek, dan biaya proyek belum ditagih, ditambah tagihan termin dan biaya proyek tunai. Proyeknya sendiri dimensi pada entri.',
    'version' => 1,
    'reviewed' => true,
    'additive' => true,
    'components' => [
        ['id' => 'chart_of_accounts', 'kind' => 'chart_of_accounts', 'template' => 'project-addon'],
        ['id' => 'transaction_types.contractor-operations', 'kind' => 'transaction_types', 'template' => 'contractor-operations'],
    ],
];
