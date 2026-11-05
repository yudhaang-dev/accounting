<?php

/*
| Chart of accounts add-on for staff advances and loans (suggestion only, P-01, X-AC-03). An `additive` template only adds accounts to the chart a
| company already has, so it is installed through a template pack and never offered as a chart of its own
| (ADR-0019). A code the company already uses is skipped, and one with a different name or class is reported as a
| conflict, never overwritten. Kept in sync with .docs/CHART-OF-ACCOUNTS.md, "Modul tambahan".
*/

$account = fn (string $code, string $name, string $class, string $normal, string $parent, bool $optional, string $note): array => [
    'code' => $code, 'name' => $name, 'class' => $class, 'normal' => $normal, 'parent' => $parent, 'optional' => $optional, 'note' => $note,
];

return [

    'key' => 'loans-addon',
    'name' => 'Kasbon dan pinjaman',
    'version' => 1,
    'additive' => true,

    'accounts' => [
        $account('1171', 'Piutang Kasbon Karyawan', 'asset', 'debit', '1170', false, 'Daun; profil posting Karyawan'),
        $account('1172', 'Piutang Pemegang Saham', 'asset', 'debit', '1170', true, 'Opsional; daun'),
        $account('2161', 'Pinjaman dari Pemegang Saham atau Pihak Lain', 'liability', 'credit', '2160', false, 'Daun'),
    ],
];
