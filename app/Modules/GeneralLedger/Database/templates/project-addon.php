<?php

/*
| Chart of accounts add-on for project revenue and advances (suggestion only, P-01, X-AC-03). An `additive` template only adds accounts to the chart a
| company already has, so it is installed through a template pack and never offered as a chart of its own
| (ADR-0019). A code the company already uses is skipped, and one with a different name or class is reported as a
| conflict, never overwritten. Kept in sync with .docs/CHART-OF-ACCOUNTS.md, "Modul tambahan".
*/

$account = fn (string $code, string $name, string $class, string $normal, string $parent, bool $optional, string $note): array => [
    'code' => $code, 'name' => $name, 'class' => $class, 'normal' => $normal, 'parent' => $parent, 'optional' => $optional, 'note' => $note,
];

return [

    'key' => 'project-addon',
    'name' => 'Proyek',
    'version' => 1,
    'additive' => true,

    'accounts' => [
        $account('1137', 'Biaya Proyek Belum Ditagih', 'asset', 'debit', '1130', true, 'Opsional; daun'),
        $account('2143', 'Uang Muka Proyek', 'liability', 'credit', '2140', false, 'Daun'),
        $account('4126', 'Pendapatan Proyek', 'income', 'credit', '4120', false, 'Daun'),
    ],
];
