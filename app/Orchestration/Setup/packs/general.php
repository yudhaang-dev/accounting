<?php

// The general pack puts together the templates every company can already import one by one: the chart of
// accounts, the party roles, the everyday transaction types, and the financial statements (ADR-0019). It names
// the template of each module by key and holds no content of its own. Nothing is created until the pack is
// confirmed (X-AC-06).
return [
    'key' => 'general',
    'name' => 'Umum',
    'description' => 'Bagan akun umum Indonesia, peran pihak, jenis transaksi harian dan jurnal umum, serta laba rugi, neraca, dan arus kas.',
    'version' => 1,
    'reviewed' => true,
    'components' => [
        ['id' => 'chart_of_accounts', 'kind' => 'chart_of_accounts', 'template' => 'general-id'],
        ['id' => 'party_roles', 'kind' => 'party_roles', 'template' => 'default'],
        ['id' => 'transaction_types.general', 'kind' => 'transaction_types', 'template' => 'general'],
        ['id' => 'transaction_types.daily-operations', 'kind' => 'transaction_types', 'template' => 'daily-operations'],
        ['id' => 'report_definitions', 'kind' => 'report_definitions', 'template' => 'general-id'],
    ],
];
