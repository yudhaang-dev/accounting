<?php

/*
| Chart of accounts add-on for inventory adjustments, with no stock per item (suggestion only, P-01, X-AC-03). An `additive` template only adds accounts to the chart a
| company already has, so it is installed through a template pack and never offered as a chart of its own
| (ADR-0019). A code the company already uses is skipped, and one with a different name or class is reported as a
| conflict, never overwritten. Kept in sync with .docs/CHART-OF-ACCOUNTS.md, "Modul tambahan".
*/

$account = fn (string $code, string $name, string $class, string $normal, string $parent, bool $optional, string $note): array => [
    'code' => $code, 'name' => $name, 'class' => $class, 'normal' => $normal, 'parent' => $parent, 'optional' => $optional, 'note' => $note,
];

return [

    'key' => 'inventory-addon',
    'name' => 'Persediaan dan HPP',
    'version' => 1,
    'additive' => true,

    'accounts' => [
        $account('1138', 'Cadangan Penurunan Nilai Persediaan', 'asset', 'credit', '1130', false, 'Kontra'),
        $account('5125', 'Beban Penurunan Nilai Persediaan', 'expense', 'debit', '5100', false, 'Daun'),
    ],
];
