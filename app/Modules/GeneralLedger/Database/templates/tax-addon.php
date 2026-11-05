<?php

/*
| Chart of accounts add-on for tax accounts and transaction types, not a tax engine (suggestion only, P-01, X-AC-03). An `additive` template only adds accounts to the chart a
| company already has, so it is installed through a template pack and never offered as a chart of its own
| (ADR-0019). A code the company already uses is skipped, and one with a different name or class is reported as a
| conflict, never overwritten. Kept in sync with .docs/CHART-OF-ACCOUNTS.md, "Modul tambahan".
*/

$account = fn (string $code, string $name, string $class, string $normal, string $parent, bool $optional, string $note): array => [
    'code' => $code, 'name' => $name, 'class' => $class, 'normal' => $normal, 'parent' => $parent, 'optional' => $optional, 'note' => $note,
];

return [

    'key' => 'tax-addon',
    'name' => 'Pajak',
    'version' => 1,
    'additive' => true,

    'accounts' => [
        $account('1143', 'Pajak Dipotong Pihak Lain (Kredit Pajak)', 'asset', 'debit', '1140', true, 'Opsional; daun'),
        $account('2154', 'Utang PPh Final', 'liability', 'credit', '2150', false, 'Daun'),
        $account('2155', 'Utang PPN Kurang Bayar', 'liability', 'credit', '2150', true, 'Opsional; daun'),
        $account('7330', 'Beban Pajak Final', 'expense', 'debit', '7300', false, 'Daun'),
    ],
];
