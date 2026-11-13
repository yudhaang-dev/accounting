<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\MasterData\Contracts\DimensionSets;
use App\Support\Company\CompanyContext;
use Carbon\CarbonImmutable;
use Database\Seeders\Demo\DemoApi;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Volume data for performance tests (D3e, tests/Performance/README.md). Unlike the demo seeder it is about size,
 * not about a story: each company is set up through the API (workspace, chart from the `general` pack, fiscal
 * years, dimensions, parties), then journals, balances, documents and open items are written in bulk SQL in
 * batches. The triggers stay on, so the balance check of every journal still runs. Reads of the result are
 * correct to the cent: balances are the sum of the lines written.
 *
 * Run: `php artisan db:seed --class=VolumeSeeder`, sizes from `config/perf.php` (`PERF_*` in the environment).
 * It adds companies and never removes data. It prints the sign-in of each company's owner and a manifest
 * (`storage/app/perf-manifest.json`) that the k6 scripts read.
 */
class VolumeSeeder extends Seeder
{
    /** @var array<int, array{id: mixed, start: mixed, end: mixed}> */
    private array $periods = [];

    public function run(): void
    {
        if (app()->environment('production') && ! config('perf.allow_production')) {
            $this->command->error('Seeder volume tidak berjalan di production (PERF_ALLOW_PRODUCTION=true untuk staging).');

            return;
        }

        $password = config('perf.password') ?: Str::password(16, symbols: false);
        $manifest = [];
        $run = Str::lower(Str::random(5));

        for ($n = 1; $n <= config('perf.companies'); $n++) {
            $started = microtime(true);
            $manifest[] = $this->company($run, $n, $password);
            $this->command->info(sprintf('Perusahaan %d/%d selesai dalam %.0f detik', $n, config('perf.companies'), microtime(true) - $started));
        }

        file_put_contents(storage_path('app/perf-manifest.json'), json_encode(['password' => $password, 'companies' => $manifest], JSON_PRETTY_PRINT));
        $this->command->info('Manifest: storage/app/perf-manifest.json (kata sandi semua pengguna perf ada di dalamnya).');
    }

    /**
     * @return array<string, mixed>
     */
    private function company(string $run, int $n, string $password): array
    {
        $api = new DemoApi;
        $user = new User;
        $user->forceFill(['name' => "Perf Owner {$n}", 'email' => "owner{$n}.{$run}@perf.test", 'password' => $password, 'email_verified_at' => now()])->save();
        DB::table('ia_platform_owners')->insertOrIgnore(['user_id' => $user->id]);

        $created = $api->as($user)->post('/api/v1/workspaces', [
            'workspace_name' => "Perf {$n}",
            'company_name' => "PT Perf {$n} {$run}",
            'functional_currency' => 'IDR',
            'timezone' => 'Asia/Jakarta',
            'default_locale' => 'id',
        ]);
        $companyId = $created['data']['company_id'];
        $url = fn (string $path = ''): string => "/api/v1/companies/{$companyId}{$path}";

        $pack = $api->get($url('/template-packs/general'))['data'];
        $api->post($url('/template-packs/general/install'), ['components' => array_column($pack['components'], 'id'), 'confirmed' => true]);

        $api->post($url('/fiscal-calendar'), ['pattern' => 'monthly', 'start_month' => 1]);

        for ($y = 0; $y < config('perf.years'); $y++) {
            $api->post($url('/fiscal-years'), ['start_date' => (config('perf.start_year') + $y).'-01-01']);
        }

        $api->post($url('/activate'));

        $values = $this->dimensions($api, $url);
        $parties = $this->parties($api, $url, $companyId);
        $sets = $this->dimensionSets($companyId, $values);
        $events = $this->eventClient($api, $url, $companyId);

        $accounts = DB::table('gl_accounts')->where('company_id', $companyId)->pluck('id', 'code')->all();
        $this->periods = DB::table('gl_periods')->where('company_id', $companyId)->orderBy('start_date')
            ->get(['id', 'start_date', 'end_date'])->map(fn ($p): array => ['id' => $p->id, 'start' => $p->start_date, 'end' => $p->end_date])->all();

        $journals = $this->journals($companyId, $user->id, $accounts, $sets);
        $items = $this->openItems($companyId, $user->id, $accounts, $parties);

        return [
            'email' => $user->email,
            'company_id' => $companyId,
            'journals' => $journals,
            'open_items' => $items,
            'periods' => array_map(fn (array $p): array => ['id' => $p['id'], 'start' => $p['start'], 'end' => $p['end']], $this->periods),
            'accounts' => ['cash' => $this->account($accounts, '1111'), 'bank' => $this->account($accounts, '1112'), 'sales' => $this->account($accounts, '4110'), 'receivable' => $this->account($accounts, '1121')],
            'dimension_values' => $values,
            'party_id' => $parties[0],
            'events' => $events,
        ];
    }

    /**
     * Five hierarchical dimension types with a region/leaf tree each; returns leaf value IDs per type key.
     *
     * @return array<string, list<string>>
     */
    private function dimensions(DemoApi $api, \Closure $url): array
    {
        $types = [];

        foreach (['branch' => 'Cabang', 'activity' => 'Kegiatan', 'channel' => 'Kanal'] as $key => $label) {
            $types[$key] = $api->post($url('/dimension-types'), ['key' => $key, 'label' => $label, 'is_hierarchical' => true])['data']['id'];
        }

        foreach ($api->get($url('/dimension-types'))['data'] as $type) {
            $types[$type['key']] ??= $type['id'];
        }

        $leaves = [];

        foreach (['branch', 'activity', 'channel', 'department', 'project'] as $key) {
            // The system types `department` and `project` are flat: twelve values without a parent.
            if (in_array($key, ['department', 'project'], true)) {
                for ($l = 1; $l <= 12; $l++) {
                    $leaves[$key][] = $api->post($url('/dimension-values'), ['dimension_type_id' => $types[$key], 'code' => strtoupper($key).'-'.$l, 'name' => "{$key} {$l}"])['data']['id'];
                }

                continue;
            }

            for ($r = 1; $r <= 3; $r++) {
                $region = $api->post($url('/dimension-values'), ['dimension_type_id' => $types[$key], 'code' => strtoupper($key).'-R'.$r, 'name' => "{$key} region {$r}"])['data']['id'];

                for ($l = 1; $l <= 4; $l++) {
                    $leaves[$key][] = $api->post($url('/dimension-values'), ['dimension_type_id' => $types[$key], 'code' => strtoupper($key).'-R'.$r.'-L'.$l, 'name' => "{$key} {$r}.{$l}", 'parent_id' => $region])['data']['id'];
                }
            }
        }

        return $leaves;
    }

    /**
     * @return list<string>
     */
    private function parties(DemoApi $api, \Closure $url, string $companyId): array
    {
        // The `general` pack already installs the party roles and a default posting profile.
        $ids = [];

        for ($i = 1; $i <= config('perf.parties'); $i++) {
            $ids[] = $api->post($url('/parties'), ['name' => "Pelanggan perf {$i}", 'roles' => ['customer']])['data']['id'];
        }

        return $ids;
    }

    /**
     * A structured sales type and an integration client allowed to send it as financial events: the machine write path
     * the k6 write scenario drives (TE-FR-12). Returns what the script needs to call it.
     *
     * @return array{client_id: string, client_secret: string, type_key: string, type_version: int}
     */
    private function eventClient(DemoApi $api, \Closure $url, string $companyId): array
    {
        $accounts = DB::table('gl_accounts')->where('company_id', $companyId)->pluck('id', 'code')->all();
        $type = $api->post($url('/transaction-types'), ['key' => 'perf_sale', 'label' => 'Penjualan perf'])['data'];
        $draft = $api->post($url("/transaction-types/{$type['id']}/versions"), [
            'mode' => 'structured',
            'fields' => [
                ['name' => 'customer', 'type' => 'party', 'label' => 'Pelanggan'],
                ['name' => 'invoice_number', 'type' => 'reference', 'label' => 'Nomor faktur', 'required' => false],
                ['name' => 'total', 'type' => 'amount', 'label' => 'Total'],
            ],
            'posting_rules' => [
                ['type' => 'amount', 'side' => 'debit', 'amount_field' => 'total', 'account' => ['source' => 'control_account', 'party_field' => 'customer'], 'apply_dimensions' => true, 'reuses_field' => true],
                ['type' => 'amount', 'side' => 'credit', 'amount_field' => 'total', 'account' => ['source' => 'fixed', 'account_id' => $this->account($accounts, '4110')], 'apply_dimensions' => true, 'reuses_field' => true],
            ],
            'subledger_route' => ['kind' => 'invoice', 'control_role' => 'receivables_control', 'party_field' => 'customer', 'document_number_field' => 'invoice_number', 'due_date_field' => null, 'cash_account_field' => null],
        ])['data'];
        $version = (int) max(0, ...array_column($draft['versions'], 'version'));
        $api->post($url("/transaction-types/{$type['id']}/versions/{$version}/publish"));

        $client = $api->post($url('/integration-clients'), [
            'name' => 'Perf ERP',
            'source_system' => 'perf',
            'scopes' => ['financial-events:write', 'financial-events:read:self'],
            'transaction_type_keys' => ['perf_sale'],
        ])['data'];

        return ['client_id' => $client['client_id'], 'client_secret' => $client['client_secret'], 'type_key' => 'perf_sale', 'type_version' => $version];
    }

    /**
     * A pool of dimension sets resolved by the application's own resolver, so signatures and set items are exact.
     *
     * @param  array<string, list<string>>  $values
     * @return list<string>
     */
    private function dimensionSets(string $companyId, array $values): array
    {
        $date = config('perf.start_year').'-06-30';
        $resolver = app(DimensionSets::class);
        $sets = [$resolver->emptySet($companyId)];

        app(CompanyContext::class)->run($companyId, function () use (&$sets, $values, $resolver, $companyId, $date): void {
            for ($i = 0; $i < config('perf.dimension_sets'); $i++) {
                $pick = [];

                foreach ($values as $key => $leaves) {
                    // About half the dimensions are left out so combinations differ in size too.
                    if (random_int(0, 1) === 1) {
                        $pick[$key] = $leaves[array_rand($leaves)];
                    }
                }

                $sets[] = $resolver->resolve($companyId, $pick, $date);
            }
        });

        return array_values(array_unique($sets));
    }

    /**
     * @param  array<string, string>  $accounts  code => id
     */
    private function account(array $accounts, string $code): string
    {
        return $accounts[$code] ?? throw new \RuntimeException("The chart has no account {$code}.");
    }

    private function periodOf(string $date): string
    {
        foreach ($this->periods as $period) {
            if ($date >= $period['start'] && $date <= $period['end']) {
                return $period['id'];
            }
        }

        throw new \RuntimeException("No period holds {$date}.");
    }

    private function randomDate(): string
    {
        $from = CarbonImmutable::create((int) config('perf.start_year'), 1, 1);
        $days = $from->diffInDays($from->addYears((int) config('perf.years'))->subDay());

        return $from->addDays(random_int(0, (int) $days))->toDateString();
    }

    /**
     * @param  array<string, string>  $accounts  code => id
     * @param  list<string>  $sets
     */
    private function journals(string $companyId, int $userId, array $accounts, array $sets): int
    {
        $revenue = $this->leaves($companyId, 'income', ['4110', '4120', '4130']);
        $expense = $this->leaves($companyId, 'expense');
        $money = [$this->account($accounts, '1111'), $this->account($accounts, '1112')];
        $total = (int) config('perf.journals');
        $batch = (int) config('perf.batch');
        $written = 0;

        while ($written < $total) {
            $size = min($batch, $total - $written);
            $journals = [];
            $lines = [];
            $balances = [];

            for ($i = 0; $i < $size; $i++) {
                $number = $written + $i + 1;
                $date = $this->randomDate();
                $period = $this->periodOf($date);
                $journalId = (string) Str::ulid();
                $amount = random_int(10, 5_000) * 1000;
                $cash = $money[array_rand($money)];
                $set = $sets[array_rand($sets)];

                // Sales (cash in), expense (cash out), or a sale with a cost of sale (four lines).
                $kind = random_int(1, 3);
                $legs = match ($kind) {
                    1 => [[$cash, $amount, 0], [$revenue[array_rand($revenue)], 0, $amount]],
                    2 => [[$expense[array_rand($expense)], $amount, 0], [$cash, 0, $amount]],
                    default => [[$cash, $amount, 0], [$revenue[array_rand($revenue)], 0, $amount], [$expense[array_rand($expense)], (int) ($amount * 0.6), 0], [$money[array_rand($money)], 0, (int) ($amount * 0.6)]],
                };

                $journals[] = [
                    'id' => $journalId, 'company_id' => $companyId, 'number' => "PF/{$number}", 'accounting_date' => $date, 'period_id' => $period,
                    'description' => "Perf journal {$number}", 'source' => 'entry', 'posted_by' => $userId, 'posted_at' => now(), 'created_at' => now(),
                ];

                foreach ($legs as $index => [$account, $debit, $credit]) {
                    $lines[] = $this->line($companyId, $journalId, $index + 1, $account, $period, $date, $debit, $credit, $set);
                    $this->addBalance($balances, $account, $period, $set, $debit, $credit);
                }
            }

            $this->flush($companyId, $journals, $lines, $balances);
            $written += $size;
        }

        return $total;
    }

    /**
     * Open invoices: journal Dr receivable / Cr sales, with a document and an open item per invoice.
     *
     * @param  array<string, string>  $accounts
     * @param  list<string>  $parties
     */
    private function openItems(string $companyId, int $userId, array $accounts, array $parties): int
    {
        $total = (int) config('perf.open_items');
        $batch = (int) config('perf.batch');
        $empty = app(DimensionSets::class)->emptySet($companyId);
        $written = 0;

        while ($written < $total) {
            $size = min($batch, $total - $written);
            $journals = [];
            $lines = [];
            $balances = [];
            $documents = [];
            $items = [];

            for ($i = 0; $i < $size; $i++) {
                $number = $written + $i + 1;
                $date = $this->randomDate();
                $due = CarbonImmutable::parse($date)->addDays(30)->toDateString();
                $period = $this->periodOf($date);
                $journalId = (string) Str::ulid();
                $documentId = (string) Str::ulid();
                $party = $parties[array_rand($parties)];
                $amount = random_int(100, 20_000) * 1000;
                $paid = random_int(0, 2) === 0 ? random_int(1, 9) * (int) ($amount / 10) : 0;
                $remaining = $amount - $paid;

                $journals[] = [
                    'id' => $journalId, 'company_id' => $companyId, 'number' => "PI/{$number}", 'accounting_date' => $date, 'period_id' => $period,
                    'description' => "Perf invoice {$number}", 'source' => 'subledger', 'posted_by' => $userId, 'posted_at' => now(), 'created_at' => now(),
                ];

                foreach ([[$this->account($accounts, '1121'), $amount, 0], [$this->account($accounts, '4110'), 0, $amount]] as $index => [$account, $debit, $credit]) {
                    $lines[] = $this->line($companyId, $journalId, $index + 1, $account, $period, $date, $debit, $credit, $empty);
                    $this->addBalance($balances, $account, $period, $empty, $debit, $credit);
                }

                $documents[] = [
                    'id' => $documentId, 'company_id' => $companyId, 'kind' => 'invoice', 'control_role' => 'receivables_control', 'party_id' => $party,
                    'journal_id' => $journalId, 'document_number' => "INV-{$number}", 'document_date' => $date, 'due_date' => $due, 'accounting_date' => $date,
                    'currency' => 'IDR', 'amount' => $amount, 'functional_amount' => $amount, 'exchange_rate' => 1, 'description' => "Perf invoice {$number}",
                    'created_by' => $userId, 'created_at' => now(), 'updated_at' => now(),
                ];

                $items[] = [
                    'id' => (string) Str::ulid(), 'company_id' => $companyId, 'document_id' => $documentId, 'party_id' => $party, 'control_role' => 'receivables_control',
                    'kind' => 'invoice', 'side' => 'debit', 'document_number' => "INV-{$number}", 'document_date' => $date, 'due_date' => $due, 'currency' => 'IDR',
                    'original_amount' => $amount, 'remaining_amount' => $remaining, 'original_functional' => $amount, 'remaining_functional' => $remaining,
                    'exchange_rate' => 1, 'status' => $remaining > 0 ? 'open' : 'settled', 'created_at' => now(), 'updated_at' => now(),
                ];
            }

            $this->flush($companyId, $journals, $lines, $balances, $documents, $items);
            $written += $size;
        }

        return $total;
    }

    /**
     * Postable leaf accounts of a class, optionally only these codes.
     *
     * @param  list<string>|null  $codes
     * @return list<string>
     */
    private function leaves(string $companyId, string $class, ?array $codes = null): array
    {
        $query = DB::table('gl_accounts as a')->where('a.company_id', $companyId)->where('a.class', $class)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('gl_accounts as c')->whereColumn('c.parent_id', 'a.id'));

        if ($codes !== null) {
            $query->whereIn('a.code', $codes);
        }

        $ids = array_values($query->pluck('a.id')->all());

        return $ids !== [] ? $ids : throw new \RuntimeException("The chart has no postable {$class} account.");
    }

    /**
     * @return array<string, mixed>
     */
    private function line(string $companyId, string $journalId, int $number, string $account, string $period, string $date, int $debit, int $credit, string $set): array
    {
        return [
            'id' => (string) Str::ulid(), 'company_id' => $companyId, 'journal_id' => $journalId, 'line_number' => $number, 'account_id' => $account,
            'period_id' => $period, 'accounting_date' => $date, 'debit' => $debit, 'credit' => $credit, 'currency' => 'IDR',
            'transaction_amount' => $debit > 0 ? $debit : $credit, 'exchange_rate' => 1, 'dimension_set_id' => $set, 'created_at' => now(),
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $balances
     */
    private function addBalance(array &$balances, string $account, string $period, string $set, int $debit, int $credit): void
    {
        $key = "{$account}|{$period}|{$set}";
        $balances[$key] ??= ['account_id' => $account, 'period_id' => $period, 'dimension_set_id' => $set, 'debit' => 0, 'credit' => 0];
        $balances[$key]['debit'] += $debit;
        $balances[$key]['credit'] += $credit;
    }

    /**
     * One transaction per batch, so the deferred balance trigger checks every journal at its commit.
     *
     * @param  list<array<string, mixed>>  $journals
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, array<string, mixed>>  $balances
     * @param  list<array<string, mixed>>  $documents
     * @param  list<array<string, mixed>>  $items
     */
    private function flush(string $companyId, array $journals, array $lines, array $balances, array $documents = [], array $items = []): void
    {
        DB::transaction(function () use ($companyId, $journals, $lines, $balances, $documents, $items): void {
            foreach (array_chunk($journals, 2000) as $chunk) {
                DB::table('gl_journals')->insert($chunk);
            }

            foreach (array_chunk($lines, 2000) as $chunk) {
                DB::table('gl_journal_lines')->insert($chunk);
            }

            foreach (array_chunk($documents, 2000) as $chunk) {
                DB::table('sl_documents')->insert($chunk);
            }

            foreach (array_chunk($items, 2000) as $chunk) {
                DB::table('sl_open_items')->insert($chunk);
            }

            ksort($balances, SORT_STRING);

            foreach ($balances as $row) {
                DB::statement(
                    'INSERT INTO gl_account_balances (company_id, account_id, period_id, dimension_set_id, currency, debit, credit, transaction_debit, transaction_credit, updated_at)
                     VALUES (?, ?, ?, ?, \'IDR\', ?, ?, ?, ?, now())
                     ON CONFLICT (company_id, account_id, period_id, dimension_set_id, currency) DO UPDATE SET
                         debit = gl_account_balances.debit + EXCLUDED.debit, credit = gl_account_balances.credit + EXCLUDED.credit,
                         transaction_debit = gl_account_balances.transaction_debit + EXCLUDED.transaction_debit,
                         transaction_credit = gl_account_balances.transaction_credit + EXCLUDED.transaction_credit, updated_at = now()',
                    [$companyId, $row['account_id'], $row['period_id'], $row['dimension_set_id'], $row['debit'], $row['credit'], $row['debit'], $row['credit']],
                );
            }
        });
    }
}
