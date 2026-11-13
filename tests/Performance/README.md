# Uji performa (D3e)

Tidak dijalankan oleh `composer test` maupun CI. Ukur di Linux/staging; angka dari Windows (Laragon) hanya untuk memastikan skripnya berjalan.

## 1. Siapkan data

Pakai database terpisah (`DB_DATABASE=accounting_perf`, `php artisan migrate --force` dulu). Seeder hanya menambah data.

```bash
# smoke (default): 2 perusahaan, 5.000 jurnal, 500 open item masing-masing
php artisan db:seed --class=VolumeSeeder --force

# volume target D3e: 20 perusahaan, ±1 juta baris jurnal, 50 ribu open item, 3 tahun buku
PERF_COMPANIES=20 PERF_JOURNALS=17000 PERF_OPEN_ITEMS=2500 PERF_YEARS=3 php artisan db:seed --class=VolumeSeeder --force
```

Ukuran lain: `PERF_PARTIES`, `PERF_DIMENSION_SETS`, `PERF_BATCH`, `PERF_START_YEAR` (lihat `config/perf.php`). Jurnal rata-rata 2,7 baris; sesuaikan `PERF_JOURNALS` ke target baris. Di `production` seeder menolak jalan kecuali `PERF_ALLOW_PRODUCTION=true` (server staging). Hasilnya: `storage/app/perf-manifest.json` (kata sandi pengguna perf, ID perusahaan, akun, periode, kredensial klien integrasi). Berkas itu rahasia; jangan di-commit.

Kebenaran data: jumlah `gl_account_balances` harus sama dengan jumlah `gl_journal_lines` (trigger keseimbangan tetap menyala saat seeding).

## 2. Konkurensi (proses PHP terpisah)

```bash
php tests/Performance/concurrency.php all            # atau: same-accounts | same-entry | close-period
php tests/Performance/concurrency.php same-accounts --workers=16 --each=200
```

| Skenario | Dibuktikan | Kode keluar 1 bila |
| --- | --- | --- |
| `same-accounts` | posting bersamaan ke akun dan set dimensi yang sama (ADR-0008): tidak ada deadlock, saldo = jumlah baris, nomor jurnal unik | ada kegagalan, saldo menyimpang, jurnal tak seimbang |
| `same-entry` | kunci entri yang sama diposting bersamaan: tepat satu jurnal per entri, sisanya replay | jurnal ganda |
| `close-period` | periode ditutup saat posting berjalan: tidak ada jurnal yang masuk setelah penutupan selesai | jurnal lolos ke periode tertutup |

Temuan pertama skenario `close-period` (sudah diperbaiki): posting membaca status periode tanpa kunci, sehingga jurnal masih lolos setelah penutupan. Kini posting memegang `pg_advisory_xact_lock_shared` per periode dan penutupan/pembukaan memegang sisi eksklusifnya (kunci advisory antre adil; kunci baris `FOR SHARE` membuat penutupan kelaparan di bawah beban posting).

## 3. Beban k6

Pasang k6 (https://grafana.com/docs/k6/latest/set-up/install-k6/). Di server yang diuji naikkan batas laju: `API_RATE_LIMIT_USER=1000000`, `API_RATE_LIMIT_CLIENT=1000000`.

```bash
BASE_URL=https://staging.example k6 run tests/Performance/k6/read.js     # VUS=10 DURATION=2m
BASE_URL=https://staging.example k6 run tests/Performance/k6/write.js    # RATE=20 DURATION=2m
BASE_URL=https://staging.example k6 run tests/Performance/k6/mixed.js
```

Ambang di `k6/lib.js` (`TARGET`) adalah target p95 usulan D3e, belum disepakati. Skrip belum pernah dijalankan (k6 belum terpasang di mesin pengembangan); jalankan dengan `--duration 10s` dulu untuk memeriksa skripnya.

## 4. Analisis query

```bash
php tests/Performance/explain.php --slow=20 --top=3 [--plans]
```

Per endpoint: waktu total, waktu database, jumlah query, bentuk query yang berulang (N+1), dan SELECT terlambat dengan `EXPLAIN (ANALYZE, BUFFERS)` bila `--plans`.

## Belum dicakup

`outbox:relay`, hitung ulang snapshot, run konsolidasi, ukuran bundle SPA dan render tabel besar, ekspor PDF/XLSX di bawah beban, rekonsiliasi (POST) di bawah beban. Catat angka sebelum dan sesudah di sini atau di ADR setelah target disepakati.
