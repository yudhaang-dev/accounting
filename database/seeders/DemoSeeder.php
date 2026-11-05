<?php

namespace Database\Seeders;

use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\Demo\DemoApi;
use Database\Seeders\Demo\DemoBank;
use Database\Seeders\Demo\DemoCatalog;
use Database\Seeders\Demo\DemoClosing;
use Database\Seeders\Demo\DemoFoundation;
use Database\Seeders\Demo\DemoIntegration;
use Database\Seeders\Demo\DemoOpening;
use Database\Seeders\Demo\DemoOperations;
use Database\Seeders\Demo\DemoPlanning;
use Database\Seeders\Demo\DemoReports;
use Database\Seeders\Demo\DemoShowcase;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * A presentable demo: one company, PT Sinar Nusantara Perkasa, with books from 1 January 2025 to today in
 * rupiah only. Everything is created through the application's own API (see DemoApi), so it can be shown
 * screen by screen: setup, accounts, dimensions, parties, cash and bank, transactions with approval,
 * receivables and payables, bank reconciliation, period and year-end closing, budget, and reports.
 *
 * It only adds data and never removes any, so it is safe beside real data; running it twice stops at once.
 * Run it with `php artisan db:seed --class=DemoSeeder`, and sign in as `direktur@demo.test` / `password`.
 */
class DemoSeeder extends Seeder
{
    public const PASSWORD = 'password';

    public function run(): void
    {
        if (User::query()->where('email', DemoFoundation::OWNER_EMAIL)->exists()) {
            $this->say('Data demo sudah ada ('.DemoFoundation::OWNER_EMAIL.'). Tidak ada yang diubah.');

            return;
        }

        if (DB::table('users')->count() > 0 && ! $this->command->confirm('Database ini sudah berisi data. Data demo ditambahkan di samping data itu tanpa mengubahnya. Lanjutkan?', true)) {
            return;
        }

        CarbonImmutable::setLocale('id');

        $started = microtime(true);
        // `DEMO_TODAY` (YYYY-MM-DD) shortens a trial run; by default the books run up to today.
        $today = CarbonImmutable::parse(config('demo.today') ?: 'now', 'Asia/Jakarta')->startOfDay();
        $api = new DemoApi;

        $foundation = new DemoFoundation($api, $today, fn (string $line) => $this->say($line));
        $foundation->run();
        (new DemoCatalog($foundation))->run();
        (new DemoOpening($foundation))->run();
        $foundation->loadAccounts();
        $foundation->cashAccounts();

        $operations = new DemoOperations($foundation);
        $operations->seedOpening();
        $operations->schedules();

        (new DemoReports($foundation))->run();
        (new DemoPlanning($foundation))->run();

        $bank = new DemoBank($foundation);
        $bank->mapping();
        $closing = new DemoClosing($foundation, $operations->books());
        $closing->policy();

        $current = $today->startOfMonth();

        for ($month = CarbonImmutable::parse(DemoFoundation::START, 'Asia/Jakarta'); $month <= $current; $month = $month->addMonth()) {
            $this->say('Transaksi '.$month->format('Y-m'));
            $last = $month->endOfMonth()->startOfDay();
            $until = $last->min($today);
            $lap = microtime(true);
            $took = function (string $what) use (&$lap, $month) {
                if (config('demo.timing')) {
                    $this->say(sprintf('   %s %s: %.1f detik', $month->format('Y-m'), $what, microtime(true) - $lap));
                }
                $lap = microtime(true);
            };

            $operations->month($month);
            $took('transaksi');

            if ($month->month === 12) {
                $closing->incomeTax($month->year);
            }

            $recent = $month >= $current->subMonth();
            $bank->statement($month, $until, $recent);
            $took('rekening koran');
            $closing->reconcile($until);
            $closing->waiveOpeningCash($until);
            $took('rekonsiliasi');

            if ($month->month === 12 && $month < $current) {
                $closing->closeYear($month->year);
                $took('tutup tahun');
            }

            if ($month < $current->subMonth()) {
                $closing->close($month);
                $took('tutup periode');
            } elseif ($month < $current) {
                $closing->softClose($month);
                $took('tutup sementara');
            }
        }

        (new DemoIntegration($foundation, $operations->books(), $today))->run();
        (new DemoShowcase($foundation, $operations->books(), $today))->run();

        $this->command->table(['Peran', 'Email', 'Kata sandi'], [
            ['Direktur (pemilik, pengaturan, penyetuju tahap akhir)', 'direktur@demo.test', self::PASSWORD],
            ['Akuntan (penutupan, rekonsiliasi, anggaran, laporan)', 'akuntan@demo.test', self::PASSWORD],
            ['Admin keuangan (mencatat dan mengajukan)', 'admin.keuangan@demo.test', self::PASSWORD],
            ['Manajer keuangan (penyetuju)', 'manajer.keuangan@demo.test', self::PASSWORD],
            ['Auditor (baca saja)', 'auditor@demo.test', self::PASSWORD],
            ['Kepala cabang Surabaya (hanya melihat Surabaya)', 'kepala.surabaya@demo.test', self::PASSWORD],
        ]);

        $this->say(sprintf('Selesai dalam %.0f detik, %d panggilan API.', microtime(true) - $started, $api->requests));
    }

    private function say(string $line): void
    {
        $this->command->info($line);
    }
}
