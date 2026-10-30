<?php

namespace Database\Seeders\Demo;

use Carbon\CarbonImmutable;

/**
 * An integration client: the ERP of the Surabaya branch registers with the right to send one kind of event and
 * only for Surabaya, gets a token with its credentials, and sends its sales as financial events (TE-FR-12,
 * API-TR-16 to API-TR-24). The events become entries in the same approval flow as everything else; the older
 * ones are approved, the latest wait in the approval inbox.
 */
final class DemoIntegration
{
    public function __construct(private readonly DemoFoundation $f, private readonly DemoBooks $books, private readonly CarbonImmutable $today) {}

    public function run(): void
    {
        $this->f->say('Klien integrasi: ERP cabang Surabaya mengirim penjualan sebagai peristiwa keuangan');

        $api = $this->f->api->as($this->f->users['owner']);
        $client = $api->post($this->f->url('/integration-clients'), [
            'name' => 'ERP Penjualan Cabang Surabaya',
            'source_system' => 'erp',
            'scopes' => ['financial-events:write', 'financial-events:read:self'],
            'transaction_type_keys' => ['erp_sales'],
            'data_scope' => [$this->f->dimensionValues['SBY']],
        ])['data'];

        $token = $api->clientToken($client['client_id'], $client['client_secret'], $client['scopes']);
        $version = (int) collect(collect($api->as($this->f->users['owner'])->get($this->f->url('/transaction-types'))['data'])->firstWhere('key', 'erp_sales')['versions'])->where('status', 'published')->max('version');

        $events = [];

        foreach ([['klinik', 24_500_000], ['lab', 31_200_000], ['toko', 12_750_000], ['apotek', 18_900_000], ['klinik', 27_300_000], ['lab', 16_400_000]] as $i => [$customer, $subtotal]) {
            $ppn = intdiv($subtotal * 11 + 50, 100);
            $date = $this->today->subDays(10 - $i)->max($this->today->startOfMonth())->toDateString();

            $events[] = $api->asClient($token, 'POST', $this->f->url('/financial-events'), [
                'transaction_type' => ['key' => 'erp_sales', 'version' => $version],
                'accounting_date' => $date,
                'currency' => 'IDR',
                'party' => $this->f->customers[$customer],
                'dimensions' => ['branch' => $this->f->dimensionValues['SBY']],
                'amounts' => ['subtotal' => (string) $subtotal, 'ppn' => (string) $ppn, 'total' => (string) ($subtotal + $ppn)],
                'references' => ['source_transaction_id' => sprintf('ERP-SBY-%s-%03d', $this->today->format('Ym'), $i + 1), 'source_document_number' => sprintf('SBY/%s/%03d', $this->today->format('ym'), $i + 1)],
            ])['data'];
        }

        // The finance manager approves the older ones; the latest wait for their turn.
        foreach (array_slice($events, 0, 3) as $event) {
            $entry = $this->f->api->as($this->f->users['preparer'])->get($this->f->url("/entries/{$event['event_id']}"))['data'];
            $this->books->approve($entry);
        }
    }
}
