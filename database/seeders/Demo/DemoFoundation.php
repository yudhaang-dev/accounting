<?php

namespace Database\Seeders\Demo;

use App\Models\User;
use App\Modules\IdentityAccess\Domain\Membership;
use App\Modules\IdentityAccess\Domain\Workspace;
use Carbon\CarbonImmutable;
use Closure;
use Database\Seeders\DemoSeeder;

/**
 * The setup an accountant does before the company is used (PANDUAN-AKUNTAN-SETUP): the people and their roles,
 * the company, chart of accounts, fiscal calendar, account attributes, ledger roles, dimensions, posting
 * profile, parties, and cash and bank accounts. Everything is by the API, as the screens do it.
 */
final class DemoFoundation
{
    public const OWNER_EMAIL = 'direktur@demo.test';

    public const START = '2025-01-01';

    /** The months of the promotion study (first and last month, `Y-m`). */
    public const PROMO_FROM = '2026-05';

    public const PROMO_TO = '2026-10';

    /** @var array<string, User> */
    public array $users = [];

    public string $companyId = '';

    public string $workspaceId = '';

    /** @var array<string, array{id: string, lock_version: int}> */
    public array $accounts = [];

    /** @var array<string, string> */
    public array $dimensionTypes = [];

    /** @var array<string, string> branch / department value code => id */
    public array $dimensionValues = [];

    /** @var array<string, string> role name => id */
    public array $roles = [];

    /** @var array<string, string> */
    public array $customers = [];

    /** @var array<string, string> */
    public array $suppliers = [];

    /** @var array<string, string> */
    public array $cash = [];

    public string $profileId = '';

    /** @var Closure(string): void */
    private Closure $say;

    public function __construct(
        public readonly DemoApi $api,
        public readonly CarbonImmutable $today,
        Closure $say,
    ) {
        $this->say = $say;
    }

    public function url(string $path = ''): string
    {
        return "/api/v1/companies/{$this->companyId}{$path}";
    }

    public function say(string $line): void
    {
        ($this->say)($line);
    }

    /** The ID of the account with this code. */
    public function account(string $code): string
    {
        return $this->accounts[$code]['id'] ?? throw new \RuntimeException("Account {$code} is not in the chart.");
    }

    public function run(): void
    {
        $this->people();
        $this->company();
        $this->roles();
        $this->members();
        $this->chartOfAccounts();
        $this->calendar();
        $this->activate();
        $this->accountAttributes();
        $this->dimensions();
        $this->ledgerRoles();
        $this->postingProfile();
        $this->parties();
    }

    private function people(): void
    {
        $this->say('Pengguna demo (kata sandi: '.DemoSeeder::PASSWORD.')');

        foreach ([
            'owner' => ['Andi Wijaya', self::OWNER_EMAIL],
            'accountant' => ['Maya Kusuma', 'akuntan@demo.test'],
            'preparer' => ['Rina Pratiwi', 'admin.keuangan@demo.test'],
            'approver' => ['Budi Santoso', 'manajer.keuangan@demo.test'],
            'auditor' => ['Hendra Gunawan', 'auditor@demo.test'],
            'branch' => ['Siti Rahayu', 'kepala.surabaya@demo.test'],
        ] as $key => [$name, $email]) {
            $user = new User;
            $user->forceFill(['name' => $name, 'email' => $email, 'password' => DemoSeeder::PASSWORD, 'email_verified_at' => now()])->save();
            $this->users[$key] = $user;
        }
    }

    private function company(): void
    {
        $this->say('Perusahaan dan ruang kerja');

        $created = $this->api->as($this->users['owner'])->post('/api/v1/workspaces', [
            'workspace_name' => 'Sinar Nusantara',
            'company_name' => 'PT Sinar Nusantara Perkasa',
            'functional_currency' => 'IDR',
            'timezone' => 'Asia/Jakarta',
            'default_locale' => 'id',
        ]);
        $this->companyId = $created['data']['company_id'];
        $this->workspaceId = $created['data']['id'] ?? (string) Workspace::query()->where('company_id', $this->companyId)->value('id');

        $company = $this->api->get($this->url())['data'];
        $this->api->patch($this->url(), [
            'lock_version' => $company['lock_version'],
            'legal_name' => 'PT Sinar Nusantara Perkasa',
            'tax_id' => '01.234.567.8-091.000',
        ]);
    }

    private function roles(): void
    {
        $this->say('Role');

        foreach ($this->api->as($this->users['owner'])->get($this->url('/roles'))['data'] as $role) {
            $this->roles[$role['name']] = $role['id'];
        }

        $permissions = $this->api->get($this->url('/roles/'.$this->roles['viewer']))['data']['permissions'] ?? [];
        $created = $this->api->post($this->url('/roles'), [
            'name' => 'akuntan',
            'description' => 'Pengaturan buku besar, penutupan periode dan tahun, rekonsiliasi, anggaran, dan laporan.',
            'permissions' => array_values(array_unique([
                ...$permissions,
                'accounts.manage', 'dimensions.manage', 'parties.manage', 'posting-profiles.manage', 'cash-accounts.manage',
                'cash-transactions.clear', 'bank-statements.manage', 'allocations.manage', 'reconciliations.run', 'reconciliations.waive',
                'aging-policy.manage', 'periods.manage', 'periods.close', 'periods.reopen', 'periods.revalue', 'journals.post-soft-closed',
                'opening-balances.import', 'fiscal-years.close', 'fiscal-years.reopen', 'plans.manage', 'plans.publish',
                'planning-policy.manage', 'recurring-schedules.manage', 'reports.export', 'reports.publish', 'report-definitions.manage',
                'entries.create', 'entries.submit', 'entries.reverse', 'transaction-types.read', 'audit.read', 'policies.manage',
            ])),
        ])['data'];
        $this->roles['akuntan'] = $created['id'];
    }

    private function members(): void
    {
        $this->say('Anggota ruang kerja');

        $assign = [
            'accountant' => ['akuntan'],
            'preparer' => ['preparer'],
            'approver' => ['approver'],
            'auditor' => ['auditor'],
            'branch' => ['viewer'],
        ];

        foreach ($assign as $key => $names) {
            Membership::query()->create(['workspace_id' => $this->workspaceId, 'user_id' => $this->users[$key]->id]);
        }

        $memberships = $this->api->as($this->users['owner'])->get($this->url('/memberships'))['data'];

        foreach ($memberships as $membership) {
            $key = collect($this->users)->search(fn (User $user) => (int) $user->id === (int) ($membership['user']['id'] ?? $membership['user_id'] ?? 0));

            if (! isset($assign[$key])) {
                continue;
            }

            $this->api->patch($this->url('/memberships/'.$membership['id']), [
                'lock_version' => $membership['lock_version'],
                'role_ids' => array_map(fn (string $name) => $this->roles[$name], $assign[$key]),
            ]);
        }
    }

    private function chartOfAccounts(): void
    {
        $this->say('Bagan akun dari template Umum Indonesia');

        $this->api->as($this->users['accountant']);
        $this->api->post($this->url('/accounts/import-template'), ['template' => 'general-id', 'confirmed' => true]);
        $this->loadAccounts();
    }

    public function loadAccounts(): void
    {
        $this->accounts = [];
        $page = 1;

        do {
            $list = $this->api->get($this->url('/accounts'), ['per_page' => 200, 'page' => $page]);

            foreach ($list['data'] as $account) {
                $this->accounts[$account['code']] = ['id' => $account['id'], 'lock_version' => $account['lock_version']];
            }

            $page++;
        } while (($list['meta']['current_page'] ?? 1) < ($list['meta']['last_page'] ?? 1));
    }

    private function calendar(): void
    {
        $this->say('Kalender fiskal bulanan, tahun fiskal 2025 dan 2026');

        $this->api->post($this->url('/fiscal-calendar'), ['pattern' => 'monthly', 'start_month' => 1]);
        $this->api->post($this->url('/fiscal-years'), ['start_date' => '2025-01-01']);
        $this->api->post($this->url('/fiscal-years'), ['start_date' => '2026-01-01']);

        if ($this->today->year > 2026) {
            for ($year = 2027; $year <= $this->today->year; $year++) {
                $this->api->post($this->url('/fiscal-years'), ['start_date' => "{$year}-01-01"]);
            }
        }
    }

    private function activate(): void
    {
        $this->say('Aktivasi perusahaan');

        $this->api->as($this->users['owner'])->post($this->url('/activate'));
    }

    /**
     * Updates posting attributes of accounts, one request each.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function patchAccount(string $code, array $attributes): void
    {
        $account = $this->accounts[$code] ?? throw new \RuntimeException("Account {$code} is not in the chart.");
        $saved = $this->api->patch($this->url('/accounts/'.$account['id']), ['lock_version' => $account['lock_version'], ...$attributes])['data'];
        $this->accounts[$code]['lock_version'] = $saved['lock_version'];
    }

    private function accountAttributes(): void
    {
        $this->say('Atribut posting akun: kontrol piutang dan utang hanya dari Subledger');

        $this->api->as($this->users['accountant']);

        foreach (['1121', '2110'] as $code) {
            $this->patchAccount($code, ['allowed_sources' => ['subledger']]);
        }
    }

    private function dimensions(): void
    {
        $this->say('Dimensi: cabang bertingkat dan departemen');

        $this->api->as($this->users['accountant']);

        $branch = $this->api->post($this->url('/dimension-types'), ['key' => 'branch', 'label' => 'Cabang', 'is_hierarchical' => true])['data'];
        $this->dimensionTypes = ['branch' => $branch['id']];

        // The system provides `department` and `project`; they only need values.
        foreach ($this->api->get($this->url('/dimension-types'))['data'] as $type) {
            $this->dimensionTypes[$type['key']] ??= $type['id'];
        }

        $value = function (string $type, string $code, string $name, ?string $parent = null): void {
            $created = $this->api->post($this->url('/dimension-values'), array_filter([
                'dimension_type_id' => $this->dimensionTypes[$type],
                'code' => $code,
                'name' => $name,
                'parent_id' => $parent !== null ? $this->dimensionValues[$parent] : null,
            ]))['data'];
            $this->dimensionValues[$code] = $created['id'];
        };

        $value('branch', 'NAS', 'Nasional');
        $value('branch', 'JAWA', 'Wilayah Jawa', 'NAS');
        $value('branch', 'SUMATERA', 'Wilayah Sumatera', 'NAS');
        $value('branch', 'JKT', 'Cabang Jakarta', 'JAWA');
        $value('branch', 'SBY', 'Cabang Surabaya', 'JAWA');
        $value('branch', 'MDN', 'Cabang Medan', 'SUMATERA');

        // An activity is a strategy with tactics beneath it; the plans of the promotion study are measured on it (ADR-0017).
        $activity = $this->api->post($this->url('/dimension-types'), ['key' => 'activity', 'label' => 'Kegiatan', 'is_hierarchical' => true])['data'];
        $this->dimensionTypes['activity'] = $activity['id'];
        $value('activity', 'PROMO', 'Strategi: promosi Mei–Oktober 2026');
        $value('activity', 'PROMO-DIG', 'Taktik: iklan digital', 'PROMO');

        $value('department', 'SALES', 'Penjualan');
        $value('department', 'OPS', 'Operasional');
        $value('department', 'ADM', 'Administrasi dan Keuangan');
    }

    private function ledgerRoles(): void
    {
        $this->say('Peran akun buku besar');

        $this->api->as($this->users['accountant']);
        $this->api->put($this->url('/account-roles/retained_earnings'), ['lock_version' => 0, 'account_id' => $this->account('3130')]);
    }

    private function postingProfile(): void
    {
        $this->say('Profil posting: akun kontrol piutang dan utang');

        $this->api->as($this->users['accountant']);
        $profile = $this->api->post($this->url('/posting-profiles'), ['name' => 'Pihak ketiga'])['data'];
        $this->profileId = $profile['id'];

        foreach (['receivables_control' => '1121', 'payables_control' => '2110'] as $role => $code) {
            $this->api->put($this->url("/posting-profiles/{$this->profileId}/accounts/{$role}"), ['lock_version' => 0, 'account_id' => $this->account($code)]);
        }
    }

    private function parties(): void
    {
        $this->say('Peran pihak, pelanggan, dan pemasok');

        $this->api->as($this->users['accountant']);
        $this->api->post($this->url('/party-roles/import-template'), ['confirmed' => true, 'keys' => ['customer', 'supplier', 'employee', 'lender', 'shareholder']]);

        foreach ([
            'rsud' => ['RSUD Harapan Sehat', '01.100.200.3-011.000'],
            'klinik' => ['Klinik Pratama Mitra Sehat', '02.200.300.4-012.000'],
            'toko' => ['CV Toko Medika Jaya', '03.300.400.5-013.000'],
            'apotek' => ['PT Apotek Sejahtera', '04.400.500.6-014.000'],
            'lab' => ['PT Laboratorium Nusantara', '05.500.600.7-015.000'],
            'univ' => ['Universitas Bina Insani', '06.600.700.8-016.000'],
            'hotel' => ['PT Hotel Grand Melati', '07.700.800.9-017.000'],
            'dinkes' => ['Dinas Kesehatan Kota', null],
        ] as $key => [$name, $tax]) {
            $this->customers[$key] = $this->api->post($this->url('/parties'), array_filter([
                'name' => $name, 'roles' => ['customer'], 'tax_id' => $tax,
                'external_refs' => [['source_system' => 'erp', 'external_ref' => 'C-'.(100 + count($this->customers))]],
            ]))['data']['id'];
        }

        foreach ([
            'alkes' => ['PT Global Alkes Indonesia', '11.100.200.3-021.000'],
            'furnitur' => ['CV Mebel Prima Karya', '12.200.300.4-022.000'],
            'it' => ['PT Teknologi Data Mandiri', '13.300.400.5-023.000'],
            'logistik' => ['PT Cepat Logistik Nusantara', '14.400.500.6-024.000'],
            'sewa' => ['Bapak Hartono (Sewa Gedung)', null],
            'listrik' => ['PT PLN (Persero)', '15.500.600.7-025.000'],
            'telkom' => ['PT Telekomunikasi Indonesia', '16.600.700.8-026.000'],
            'konsultan' => ['KAP Rahman dan Rekan', '17.700.800.9-027.000'],
            'iklan' => ['PT Kreasi Iklan Digital', '18.100.200.3-028.000'],
            'bank' => ['PT Bank Central Asia Tbk (biaya bank)', '19.200.300.4-029.000'],
            'kpp' => ['KPP Pratama (setoran PPN)', null],
            'payroll' => ['Payroll Karyawan', null],
            'atk' => ['CV Sumber ATK Mandiri', null],
            'saham' => ['Pemegang Saham (dividen)', null],
        ] as $key => [$name, $tax]) {
            $this->suppliers[$key] = $this->api->post($this->url('/parties'), array_filter([
                'name' => $name, 'roles' => $key === 'saham' ? ['supplier', 'shareholder'] : ['supplier'], 'tax_id' => $tax,
                'external_refs' => [['source_system' => 'erp', 'external_ref' => 'S-'.(100 + count($this->suppliers))]],
            ]))['data']['id'];
        }
    }

    /** After the opening balances: a cash account's ledger account takes only Subledger postings from then on. */
    public function cashAccounts(): void
    {
        $this->say('Rekening kas dan bank');

        $this->api->as($this->users['accountant']);

        foreach (['1111', '1112', '1113'] as $code) {
            $this->patchAccount($code, ['allowed_sources' => ['subledger']]);
        }

        $make = fn (string $key, array $body) => $this->cash[$key] = $this->api->post($this->url('/cash-accounts'), [...$body, 'currency' => 'IDR'])['data']['id'];

        $make('petty', ['name' => 'Kas Kecil Kantor Pusat', 'kind' => 'cash', 'account_id' => $this->account('1111')]);
        $make('bca', ['name' => 'BCA Operasional', 'kind' => 'bank', 'bank_name' => 'BCA', 'account_number' => '035-123-4567', 'account_id' => $this->account('1112')]);
        $make('mandiri', ['name' => 'Mandiri Tabungan Khusus', 'kind' => 'bank', 'bank_name' => 'Bank Mandiri', 'account_number' => '129-000-8812-345', 'account_id' => $this->account('1113')]);
    }
}
