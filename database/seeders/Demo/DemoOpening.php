<?php

namespace Database\Seeders\Demo;

/**
 * The company starts its books on 1 January 2025 from a trial balance of the previous system: the opening
 * balances (GL-FR-11) with the open receivables and payables behind the control accounts (SL-FR-10).
 */
final class DemoOpening
{
    public function __construct(private readonly DemoFoundation $f) {}

    public function run(): void
    {
        $this->f->say('Saldo konversi per 1 Januari 2025 beserta piutang dan utang awal');

        $balances = [
            // code => [debit, credit]
            '1111' => ['15000000', '0'],
            '1112' => ['850000000', '0'],
            '1113' => ['200000000', '0'],
            '1121' => ['330000000', '0'],
            '1131' => ['620000000', '0'],
            '1150' => ['36000000', '0'],
            '1213' => ['480000000', '0'],
            '1214' => ['350000000', '0'],
            '1219' => ['0', '150000000'],
            '2110' => ['0', '285000000'],
            '2120' => ['0', '40000000'],
            '2210' => ['0', '300000000'],
            '3110' => ['0', '1500000000'],
            '3130' => ['0', '606000000'],
        ];

        $c = $this->f->customers;
        $s = $this->f->suppliers;
        $ref = fn (string $id, array $parties, string $prefix) => $prefix.(100 + array_search($id, array_values($parties), true));

        $this->f->api->as($this->f->users['accountant'])->post($this->f->url('/opening-balance-imports'), [
            'source_key' => 'sistem-lama-2024-12',
            'source_system' => 'erp',
            'cutover_date' => DemoFoundation::START,
            'account_mapping' => array_map(fn (string $code) => ['source_code' => "L-{$code}", 'account_code' => $code], array_keys($balances)),
            'lines' => array_map(
                fn (string $code, array $amounts) => ['source_account_code' => "L-{$code}", 'debit' => $amounts[0], 'credit' => $amounts[1]],
                array_keys($balances),
                $balances,
            ),
            'open_items' => [
                ['party_ref' => $ref($c['rsud'], $c, 'C-'), 'control_role' => 'receivables_control', 'kind' => 'invoice', 'document_number' => 'INV-2024-0987', 'document_date' => '2024-12-05', 'due_date' => '2025-01-04', 'amount' => '200000000'],
                ['party_ref' => $ref($c['toko'], $c, 'C-'), 'control_role' => 'receivables_control', 'kind' => 'invoice', 'document_number' => 'INV-2024-0991', 'document_date' => '2024-12-18', 'due_date' => '2025-01-17', 'amount' => '130000000'],
                ['party_ref' => $ref($s['alkes'], $s, 'S-'), 'control_role' => 'payables_control', 'kind' => 'invoice', 'document_number' => 'BILL-2024-311', 'document_date' => '2024-12-12', 'due_date' => '2025-01-11', 'amount' => '185000000'],
                ['party_ref' => $ref($s['furnitur'], $s, 'S-'), 'control_role' => 'payables_control', 'kind' => 'invoice', 'document_number' => 'BILL-2024-120', 'document_date' => '2024-12-20', 'due_date' => '2025-01-19', 'amount' => '100000000'],
            ],
        ]);
    }
}
