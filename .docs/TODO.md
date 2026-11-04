# TODO — Rencana Implementasi

> Urutan kerja implementasi. Setiap item ≈ satu PR dan merujuk ID requirement; teks requirement tidak disalin. Status awal: belum dimulai.

**Definisi selesai:** kode + test Pest untuk setiap AC yang dirujuk + spesifikasi OpenAPI terbarui + layar SPA terkait (bila ada) + CI lulus (Pint, Larastan, Pest, deptrac).

**Spesifikasi OpenAPI** tidak diperiksa oleh `composer test` maupun CI. Setelah API berubah, jalankan `composer openapi:export` dan `pnpm run api:types`, lalu commit keduanya; `composer test:openapi` menjalankan testnya sesuai kebutuhan. **Menjalankan test:** selama bekerja cukup test modul yang disentuh (`php artisan test --compact tests/Feature/<Modul>`); suite penuh sekali di akhir lewat `composer test:parallel`, yang butuh `max_locks_per_transaction` ≥ 256 di PostgreSQL lokal.

**Urutan:** M0 menyiapkan fondasi; M1 membangun irisan end-to-end tipis untuk menguji arsitektur; M2–M8 memperdalam modul sesuai graf dependensi. Requirement yang sebagian dikerjakan lebih awal dilengkapi di milestone berikutnya.

**Penerapan pada perusahaan yang sudah ada** (catatan item selesai dipangkas; yang tersisa hanya yang perlu dilakukan operator):

- Permission baru tidak otomatis masuk role yang sudah ada; tambahkan ke role terkait: `plans.publish`, `planning-policy.manage` (M7-03), `reports.publish` (M8-11), `bank-statements.read` dan `bank-statements.manage` (B-01), `consolidation.join` (B-05c).
- Produksi perlu worker queue (`queue:work`, Horizon di Linux) dan scheduler untuk `outbox:relay` per menit; atur `WEBHOOK_QUEUE`, `WEBHOOK_TRIES`, `WEBHOOK_TIMEOUT_SECONDS` bila perlu (B-02).
- Passport: `php artisan passport:keys`, migrasi Passport, atur `API_TOKEN_TTL_SECONDS` (M3-05).

## M0 — Fondasi

- [x] **M0-01** Bersihkan starter kit: hapus Inertia dan Wayfinder (paket PHP/JS, middleware, halaman); Fortify mode headless dengan respons JSON — ADR-0010, ADR-0005
- [x] **M0-02** Pindah ke PostgreSQL (`.env.example`, test, ekstensi `btree_gist`) — ADR-0003
- [x] **M0-03** Pasang paket ADR-0004 dan verifikasi kompatibilitasnya dengan Laravel 13 — ADR-0004
- [x] **M0-04** Kerangka modul (`app/Modules/*`, `Orchestration`, `Platform`, `Support`), service provider dan route per modul, prefix tabel — ADR-0002
- [x] **M0-05** Penjaga arsitektur di CI: deptrac sesuai graf, Pest arch (import lintas modul hanya ke `Contracts`, tanpa pemeriksaan nama role, prefix tabel) — ADR-0002
- [x] **M0-06** Tipe dasar: `Money` (brick/math) dan cast desimal, model berbasis ULID, scope perusahaan dan konvensi composite FK — API-TR-04, API-TR-05
- [x] **M0-07** Platform Audit: log append-only dengan aktor user/klien dan nilai sebelum/sesudah, dilindungi trigger — X-FR-01, X-AC-05
- [x] **M0-08** Platform Numbering: seri berpola (prefix, token tanggal, reset, padding), tanpa celah dalam transaksi; dipakai GL-FR-05 dan TE-FR-16
- [x] **M0-09** Platform Localization: `lang/id` dan `lang/en`, middleware `Accept-Language`, locale default dari konfigurasi — API-TR-06, API-AC-10, ADR-0001
- [x] **M0-10** Dasar API: prefix `/api/v1` dengan middleware sesi + CSRF (ADR-0011), binding dan validasi scope perusahaan, konvensi resource, command, dan parameter laporan, handler error (422, `code`, 409, 403) beserta katalog kode — API-TR-01, API-TR-02, API-TR-03, API-TR-07, API-TR-08, API-TR-09, API-TR-10, API-TR-11, API-TR-12, API-AC-01, API-AC-02
- [x] **M0-11** `Idempotency-Key`, `lock_version`, dan rate limit — API-TR-13, API-TR-14, API-TR-15, API-AC-08, API-AC-09
- [x] **M0-12** Scramble: publikasi spesifikasi OpenAPI dan pembuatan klien SPA dari spesifikasi di CI — ADR-0004, ADR-0010
- [x] **M0-13** Kerangka SPA: React + TypeScript + Vite, TanStack Router/Query, react-i18next, shadcn; alur CSRF, login, 2FA, passkey, dan logout lewat Fortify — API-TR-21, IA-FR-04, ADR-0010
- [x] **M0-14** Kebijakan retensi per perusahaan (default Indonesia 10 tahun) dan larangan hard delete data keuangan — X-FR-05

## M1 — Irisan end-to-end awal

Alur: login → buat perusahaan → COA dan periode → entri jurnal umum → approval → posting → neraca saldo → ekspor.

- [x] **M1-01** Workspace, membership dasar, `/me`, `/me/workspaces`; pembuatan workspace beserta perusahaan lewat orkestrasi — IA-FR-01, IA-AC-01
- [x] **M1-02** Katalog permission, seed role, `AccessContext`, otorisasi per permission — IA-FR-02
- [x] **M1-03** Profil dan status perusahaan; aktivasi lewat orkestrasi setelah COA dan kalender siap — MD-FR-01, MD-AC-05
- [x] **M1-04** Bagan akun dengan seed template yang wajib dikonfirmasi — GL-FR-01, X-AC-03
- [x] **M1-05** Kalender fiskal bulanan, periode tanpa celah (exclusion constraint), status `open`/`closed` — GL-FR-03, GL-FR-04, GL-AC-08
- [x] **M1-06** Inti posting GL: `Journal`/`JournalLine` dengan kolom mata uang dan `dimension_set_id` (set kosong) sejak awal, trigger keseimbangan dan imutabilitas, nomor jurnal, `AccountBalance` dengan urutan penguncian, idempotensi per entri — GL-FR-05, GL-FR-06, GL-AC-01, GL-AC-03, GL-AC-12, ADR-0007, ADR-0008
- [x] **M1-07** Entri minimal: Jenis Transaksi mode `free_lines`, siklus entri, validasi lewat `PostingValidator`, approval berbasis permission, posting ke GL — TE-FR-01, TE-FR-03, TE-FR-07, TE-FR-10
- [x] **M1-08** Neraca saldo dengan drill-down dan ekspor CSV (Platform Export) — RP-FR-05, RP-AC-01, X-FR-02, API-AC-07
- [x] **M1-09** SPA: pemilih workspace, wizard setup perusahaan (profil, konfirmasi template COA, kalender), akun, periode, form dan daftar entri beserta aksinya, detail jurnal, neraca saldo
  - [x] **M1-09a** Shell SPA dan pemilih workspace
  - [x] **M1-09b** Wizard setup perusahaan (profil, konfirmasi template COA, kalender)
  - [x] **M1-09c** Daftar akun dan periode
  - [x] **M1-09d** Form dan daftar entri beserta aksinya (submit, approve, post) — jenis transaksi pertama dibuat dari form oleh pemegang `policies.manage` sampai ada perancang di M5-14
  - [x] **M1-09e** Detail jurnal
  - [x] **M1-09f** Neraca saldo (drill-down dan ekspor CSV)
- [x] **M1-10** Test feature end-to-end untuk seluruh alur M1 — langkah CI-nya ditambahkan (`pnpm run e2e` setelah `composer ci:check`) tetapi belum pernah berjalan di GitHub Actions; lulus di mesin lokal

## M2 — Data induk

- [x] **M2-01** Tipe dan nilai dimensi: hierarki, masa berlaku, aturan hanya-daun, nonaktif — MD-FR-02, MD-FR-03, MD-AC-03, MD-AC-04
- [x] **M2-02** Resolusi dimension set dan query hierarki; dipakai baris entri, jurnal, dan filter neraca saldo — MD-FR-04, MD-FR-05, MD-AC-01, MD-AC-02
- [x] **M2-03** Mata uang ISO 4217, aktivasi per perusahaan, skala dan pembulatan — MD-FR-06
- [x] **M2-04** Jenis kurs dan kurs (manual, CSV, API `exchange-rates:write`), pencarian tanpa fallback, batas umur — MD-FR-07, MD-FR-08, MD-AC-06
- [x] **M2-05** SPA: dimensi, mata uang, kurs, pemilih dimensi di form entri

## M3 — Identitas & akses

- [x] **M3-01** Role sebagai data dan endpoint katalog permission — IA-FR-02, IA-AC-03
- [x] **M3-02** Siklus membership: undangan, pencabutan, kedaluwarsa — IA-FR-01, IA-AC-02
- [x] **M3-03** Cakupan data per nilai dimensi beserta turunannya, diterapkan sebagai filter di semua modul — IA-FR-03, IA-AC-04, X-AC-01
- [x] **M3-04** Kebijakan keamanan: 2FA wajib per permission, batas sesi idle, allowlist IP klien — IA-FR-04, IA-FR-05, IA-AC-05
- [x] **M3-05** Klien integrasi: Passport Client Credentials di `/api/v1/oauth/token`, scope, allowlist, rotasi dan pencabutan langsung — IA-FR-06, IA-AC-06, IA-AC-07, API-TR-22, API-TR-23, API-TR-24
- [x] **M3-06** SPA: anggota dan role, cakupan data, kebijakan keamanan, klien integrasi (secret tampil sekali)
- [x] **M3-07** Cakupan data klien integrasi: `data_scope` pada pendaftaran dan ubah klien, ditegakkan gerbang akses seperti cakupan membership, pemilih cakupan di layar klien integrasi — IA-FR-03, IA-FR-06, IA-AC-08, X-AC-01

## M4 — Buku besar

- [x] **M4-01** Atribut posting akun: sumber yang diizinkan, kewajiban dimensi, batasan mata uang, penanda revaluasi — GL-FR-02, GL-AC-02
- [x] **M4-02** Status `soft_closed` dengan kebijakan per key Jenis Transaksi, tutup/buka periode beralasan, pola kalender 4-4-5 dan rentang bebas — GL-FR-03, GL-FR-04, GL-AC-04
- [x] **M4-03** Pembalikan jurnal di sisi GL — GL-FR-06, GL-AC-11
- [x] **M4-04** Kebijakan override akun kontrol — GL-FR-07
- [x] **M4-05** Baris valas, pembulatan ke `fx_rounding`, saldo per mata uang — GL-FR-08, GL-AC-05
- [x] **M4-06** Pemetaan peran GL berversi dan interface validasi target peran — GL-FR-10, X-AC-02, X-AC-03
- [x] **M4-07** Revaluasi akun non-kontrol dengan kebijakan pembalikan otomatis — GL-FR-09, GL-AC-06
- [x] **M4-08** Impor saldo konversi: `cutover_date`, `ledger_start_date`, pemetaan kode sumber, saldo YTD, idempoten — GL-FR-11, GL-AC-07
- [x] **M4-09** Impor histori (ringkasan per periode atau transaksi) dan pencocokan dengan neraca saldo sumber pada cutover — GL-FR-11
- [x] **M4-10** Tutup tahun: orkestrasi kesiapan, jurnal penutup per akun × set dimensi, saldo tahun berikutnya — GL-FR-12, GL-FR-13, GL-AC-09
- [x] **M4-11** Buka kembali tahun buku — GL-FR-14, GL-AC-10
- [x] **M4-12** SPA: atribut akun, manajemen periode, revaluasi, wizard saldo konversi, checklist tutup tahun — wizard impor belum memetakan dimensi (API sudah mendukungnya)
  - [x] **M4-12a** Atribut posting pada form akun
  - [x] **M4-12b** Manajemen periode: tutup sementara, tutup, buka kembali
  - [x] **M4-12c** Pengaturan buku besar: kebijakan dan pemetaan peran akun
  - [x] **M4-12d** Revaluasi per periode
  - [x] **M4-12e** Wizard saldo konversi dan histori
  - [x] **M4-12f** Checklist tutup dan buka kembali tahun buku

## M5 — Pencatatan transaksi

- [x] **M5-01** Mode `structured`: skema field (subset JSON Schema), penerbitan dan penghentian versi — TE-FR-02, TE-FR-04, TE-AC-01, TE-AC-06
- [x] **M5-02** Aturan posting: sumber akun, penerapan dimensi, pemakaian ulang eksplisit, baris nol dilewati, baris `fx_difference` — TE-FR-05, TE-AC-02
- [x] **M5-03** Mata uang dan kurs entri — TE-FR-06
- [x] **M5-04** Kebijakan approval/SoD: aturan per key, sumber, dan ambang; tahap dan permission; posting otomatis setelah approval — TE-FR-09, TE-AC-05
- [x] **M5-05** Validasi lengkap saat submit dan posting — TE-FR-08, TE-AC-03
- [x] **M5-06** Pembalikan, penggantian, dan pembalikan terjadwal — TE-FR-11, TE-AC-09
- [x] **M5-07** Endpoint Peristiwa Keuangan beserta status HTTP-nya — TE-FR-12, API-TR-16, API-TR-17, API-TR-18, API-TR-19, API-TR-20, API-AC-03, API-AC-05, API-AC-06
- [x] **M5-08** Idempotensi kunci sumber dengan payload kanonis, aman terhadap kiriman bersamaan — TE-FR-13, TE-AC-04, API-AC-04
- [x] **M5-09** Changes feed berbasis cursor — TE-FR-15, TE-AC-08
- [x] **M5-10** Jurnal berulang — TE-FR-14
- [x] **M5-11** Penomoran entri per Jenis Transaksi — TE-FR-16
- [x] **M5-12** Lampiran entri (Platform Attachments) — X-FR-04
- [x] **M5-13** Template Jenis Transaksi yang wajib dikonfirmasi — TE-FR-01
- [x] **M5-14** SPA: perancang Jenis Transaksi, form dinamis dari skema, kotak approval, kebijakan approval, jadwal berulang, alur pembalikan
  - [x] **M5-14a** Daftar Jenis Transaksi: buat jenis, impor template dengan tinjauan dan konfirmasi, seri nomor dan kebijakan titik penomoran — TE-FR-01, TE-FR-16
  - [x] **M5-14b** Perancang versi: editor field, aturan posting, kebijakan mata uang, terbitkan dan hentikan — TE-FR-02, TE-FR-04, TE-FR-05, TE-FR-06
  - [x] **M5-14c** Form entri dinamis dari skema versi `structured`, termasuk mata uang dan kurs — TE-FR-04, TE-FR-06, TE-AC-01
  - [x] **M5-14d** Lampiran pada detail entri dan kotak approval (entri menunggu tahap yang bisa disetujui user) — X-FR-04, TE-FR-09
  - [x] **M5-14e** Kebijakan approval: aturan per key, sumber, ambang, tahap, SoD, posting otomatis — TE-FR-09, TE-AC-05
  - [x] **M5-14f** Pembalikan jurnal, entri pengganti, `auto_reverse_on`, dan daftar pembalikan terjadwal yang gagal — TE-FR-11, TE-AC-09
  - [x] **M5-14g** Jadwal berulang: buat, ubah, lewati, dan kegagalan yang terlihat — TE-FR-14

## M6 — Subledger

- [x] **M6-01** Pihak dan referensi eksternal per `source_system` — SL-FR-01, SL-AC-08
- [x] **M6-02** Profil posting dan peran akun kontrol — SL-FR-02
- [x] **M6-03** Rekening kas/bank dan transaksi kas berstatus `cleared` — SL-FR-03, SL-FR-11
- [x] **M6-04** Dokumen dan open item; rute subledger dari entri; sumber akun kontrol dan rekening pada aturan posting — SL-FR-04, TE-FR-05, TE-FR-10
- [x] **M6-05** Alokasi, uang muka, dan pembatalan alokasi — SL-FR-05, SL-AC-03
- [x] **M6-06** Selisih kurs terealisasi — SL-FR-06, SL-AC-02, TE-AC-10
- [x] **M6-07** Revaluasi open item — SL-FR-07, SL-AC-06
- [x] **M6-08** Pembalikan dokumen — SL-FR-12, SL-AC-07, TE-AC-07
- [x] **M6-09** Rekonsiliasi akun kontrol dan waiver; integrasi ke kesiapan tutup tahun — SL-FR-09, SL-AC-05, GL-AC-09
- [x] **M6-10** Impor open item awal dan pencocokan total kontrol dengan batch saldo konversi — SL-FR-10, SL-AC-01, GL-AC-07
- [x] **M6-11** Laporan aging dengan bucket kebijakan — SL-FR-08, SL-AC-04
- [x] **M6-12** SPA: pihak, profil posting, rekening, open item dan alokasi, rekonsiliasi, aging
  - [x] **M6-12a** Pihak: daftar, form, referensi eksternal per `source_system`, profil posting pada pihak, nonaktifkan — SL-FR-01, SL-AC-08
  - [x] **M6-12b** Profil posting dan pemetaan akun kontrol berversi, termasuk petunjuk membatasi akun kontrol ke sumber `subledger` — SL-FR-02
  - [x] **M6-12c** Rekening kas/bank dan transaksi kas: daftar, form, tandai dan batalkan `cleared` — SL-FR-03, SL-FR-11
  - [x] **M6-12d** Dokumen dan open item: daftar dengan filter, detail beserta tautan ke jurnal dan entri, status `reversed` dan `is_opening` — SL-FR-04
  - [x] **M6-12e** Alokasi: daftar per open item, alokasi atas dokumen yang sudah terposting (uang muka), pembatalan beralasan, selisih kurs terealisasi — SL-FR-05, SL-FR-06
  - [x] **M6-12f** Perancang Jenis Transaksi dan form entri: tipe field `party`, `cash_account`, `allocations` dengan lookup, editor rute subledger, `write_off`, `auto_reverse_on` untuk entri berrute — TE-FR-05, TE-FR-10
  - [x] **M6-12g** Pembalikan dokumen dari detail dokumen atau jurnal, dengan pesan alokasi aktif yang menghalangi — SL-FR-12, SL-AC-07
  - [x] **M6-12h** Revaluasi open item pada layar periode — SL-FR-07
  - [x] **M6-12i** Rekonsiliasi: jalankan per tanggal, daftar selisih beserta jurnal penyebab, waiver beralasan, tautan dari checklist tutup tahun — SL-FR-09, SL-AC-05
  - [x] **M6-12j** Aging: laporan per pihak dan dokumen dengan drill-down dan ekspor CSV, editor kebijakan bucket — SL-FR-08, SL-AC-04
  - [x] **M6-12k** Wizard saldo konversi: `open_items` beserta pemetaan pihak sumber dan pencocokan total kontrol — SL-FR-10, SL-AC-01

## M7 — Anggaran & proyeksi

- [x] **M7-01** Rencana dan versi (salin saat mengubah versi terbit) — PL-FR-01, PL-AC-01
- [x] **M7-02** Baris rencana, impor/ekspor CSV, salin dari versi lain atau realisasi — PL-FR-02, PL-FR-03, PL-AC-02
- [x] **M7-03** Penerbitan, kebijakan penerbit, dan versi pembanding default — PL-FR-04, PL-AC-03
- [x] **M7-04** SPA: editor grid rencana, impor, penerbitan — PL-FR-01, PL-FR-02, PL-FR-03, PL-FR-04

## M8 — Pelaporan

- [x] **M8-01** Engine definisi laporan: baris, kolom, dasar nilai, tanda, kumpulan akun, baris pengecualian, versi — RP-FR-01, RP-FR-02, RP-AC-07
- [x] **M8-02** Filter dan agregasi: dimensi dengan hierarki, cakupan data, key Jenis Transaksi, sumber — RP-FR-04, RP-AC-03
- [x] **M8-03** Peran `cash_equivalents` dan klasifikasi mutasi — RP-FR-03
- [x] **M8-04** Laba rugi — RP-FR-07, RP-AC-05
- [x] **M8-05** Neraca dan `current_year_earnings` — RP-FR-08, RP-AC-01, RP-AC-06
- [x] **M8-06** Arus kas metode langsung dan tidak langsung — RP-FR-09, RP-AC-02
- [x] **M8-07** Perubahan ekuitas — RP-FR-10, RP-AC-09
- [x] **M8-08** Laporan buku besar dan mode kertas kerja neraca saldo — RP-FR-05, RP-FR-06, RP-AC-11
- [x] **M8-09** Proyeksi vs realisasi dan aturan selisih — RP-FR-11, RP-FR-12, RP-AC-04
- [x] **M8-10** Cakupan histori laporan — RP-FR-13, RP-AC-10
- [x] **M8-11** Snapshot laporan terbit dan hitung ulang — RP-FR-14, X-FR-03, RP-AC-08, X-AC-04
- [x] **M8-12** Template definisi laporan dari [CHART-OF-ACCOUNTS.md](CHART-OF-ACCOUNTS.md) — RP-FR-01
- [x] **M8-13** SPA: penampil laporan (filter, pembanding, drill-down, ekspor), editor definisi dan klasifikasi, penerbitan snapshot
  - [x] **M8-13a** Filter laporan bersama: rentang tanggal atau `as_of`, dimensi (nilai induk mencakup turunannya), key Jenis Transaksi, sumber, versi rencana; dipakai semua penampil di bawah — RP-FR-04, RP-AC-03
  - [x] **M8-13b** Penampil definisi laporan: daftar definisi, hasil per kolom (periode, YTD, per tanggal, pembanding, selisih), baris `unmapped`, `balance_checks` neraca dan `cash_checks` arus kas tidak langsung, penanda `restricted` dan cakupan histori (`available: false`) — RP-FR-01, RP-FR-07, RP-FR-08, RP-FR-13, RP-AC-01, RP-AC-05, RP-AC-06, RP-AC-10
  - [x] **M8-13c** Pembanding rencana: kolom `plan`, versi rencana, tampilan `variances` dan aturan favorable — RP-FR-11, RP-FR-12, RP-AC-04
  - [x] **M8-13d** Neraca saldo yang sudah ada diperluas: mode kertas kerja (`adjustment_keys[]`), penanda batch saldo konversi dan cakupan histori; buku besar per akun dengan saldo berjalan, tautan ke entri, dan ekspor CSV — RP-FR-05, RP-FR-06, RP-AC-11
  - [x] **M8-13e** Arus kas langsung dan perubahan ekuitas: penampil hasil klasifikasi, baris belum diklasifikasikan beserta rinciannya, rekonsiliasi kas, pesan pemetaan `cash_equivalents` belum lengkap — RP-FR-09, RP-FR-10, RP-AC-02, RP-AC-09
  - [x] **M8-13f** Pemetaan `cash_equivalents`: tampilan versi berlaku, ubah dengan `lock_version`, masalah kelayakan akun — RP-FR-03, RP-FR-09
  - [x] **M8-13g** Editor definisi laporan: baris (judul, kumpulan akun, subtotal, `current_year_earnings`, `cash_equivalents`), kolom, dasar nilai, versi baru saat struktur berubah, pesan validasi per baris — RP-FR-01, RP-FR-02, RP-AC-07
  - [x] **M8-13h** Editor klasifikasi mutasi: baris, aturan per akun dan key Jenis Transaksi, komponen ekuitas — RP-FR-03, RP-FR-10
  - [x] **M8-13i** Impor template definisi laporan: tinjau definisi, konfirmasi, pesan kode akun yang hilang dari bagan akun — RP-FR-01, X-AC-03
  - [x] **M8-13j** Snapshot: terbitkan dari definisi (`reports.publish`), daftar dan isi snapshot, hitung ulang beralasan, penanda perlu dihitung ulang; pesan untuk user dengan cakupan data — RP-FR-14, RP-AC-08, X-AC-04
  - [x] **M8-13k** Ekspor dan drill-down: ekspor CSV dari penampil definisi dan klasifikasi bila API menyediakannya, drill-down baris laporan ke neraca saldo, buku besar, dan jurnal — X-FR-02, API-AC-07

## Backlog

### Fase 2

- [x] **B-01** Rekonsiliasi bank lewat impor mutasi — SL-FR-13, SL-AC-09
- [x] **B-02** Webhook bertanda tangan lewat outbox — API-TR-25
- [x] **B-03** Endpoint batch event — TE-FR-17, API-TR-26
- [x] **B-04** Atribusi pembayaran ke akun biaya asal untuk metrik kas — RP-FR-11, SL-FR-14

### Post-MVP

- [x] **B-05** Modul konsolidasi: seluruh [CONSOLIDATION.md](CONSOLIDATION.md), grant grup, `counterparty_company_id`, `ReportPackage` — IA-FR-07, GL-FR-15
  - [x] **B-05a** Fondasi: `counterparty_company_id` pada baris jurnal dan `Party`, grant grup dengan ability `read`/`run` — IA-FR-07, GL-FR-15, IA-AC-01
  - [x] **B-05b** `ReportPackage` di Reporting (periode, tanggal batas, status tutup buku, mata uang fungsional, saldo, cakupan histori, versi pemetaan) — CO-FR-03
  - [x] **B-05c** Modul `Consolidation`: grup, perimeter dengan persetujuan, pemetaan akun grup berversi; deptrac dan ADR-0002 diperbarui — CO-FR-02
  - [x] **B-05d** Run roll-up manajemen: entitas efektif tepat sekali, akun tanpa pemetaan sebagai pengecualian, penolakan mata uang fungsional berbeda, run berversi, audit — CO-FR-01, CO-FR-04, CO-FR-09, CO-AC-01, CO-AC-02, CO-AC-03, CO-AC-04, CO-AC-05
  - [x] **B-05e** SPA: grup, perimeter, pemetaan, run dan hasil roll-up beserta ekspor CSV — CO-FR-10, X-FR-02
  - [x] **B-05f** Tahap B (translasi, eliminasi, penyesuaian, keluaran formal) — CO-FR-05 sampai CO-FR-08, CO-FR-10
    - [x] **B-05f1** Kebijakan translasi, jenis kurs dan kurs grup berversi, akun selisih translasi di bagan akun grup — CO-FR-06
    - [x] **B-05f2** Translasi di run: kurs penutup, rata-rata, dan historis; run tidak lagi menolak mata uang fungsional berbeda bila kebijakan dan kurs lengkap — CO-FR-06, CO-AC-03
    - [x] **B-05f3** Eliminasi intragrup: `counterparty_company_id` dibawa dari entri dan peristiwa ke baris jurnal, pencocokan menurut entitas lawan, periode, mata uang, nilai, dan toleransi per grup, selisih sebagai pengecualian — CO-FR-07, GL-FR-15
      - [x] **B-05f3a** Pembawa lawan transaksi: baris entri `free_lines` memuat `counterparty_company_id`; di entri `structured` baris akun kontrol membawa perusahaan grup dari pihaknya, dan baris lain bila aturannya menamai `counterparty_party_field`; peristiwa memakai jalur yang sama lewat field `party` — GL-FR-15, TE-FR-05
      - [x] **B-05f3b** Saldo antarentitas dalam paket laporan, pencocokan pasangan menurut toleransi grup, baris eliminasi di run, dan pengecualian selisih — CO-FR-07
    - [x] **B-05f4** Penyesuaian konsolidasi dengan sumber, alasan, persetujuan, dan audit; hanya memengaruhi hasil grup — CO-FR-08, CO-AC-04
    - [x] **B-05f5** Run formal dan keluarannya — CO-FR-05, CO-FR-10
      - [x] **B-05f5a** Run formal: label formal hanya untuk satu induk dengan kendali penuh, penanda draf selama ada paket belum tertutup atau parsial — CO-FR-05
      - [x] **B-05f5c** Arus kas grup metode tidak langsung — CO-FR-10
      - [x] **B-05f5b** Keluaran grup dari hasil run: neraca saldo (sudah ada), neraca, laba rugi, laporan eliminasi dan penyesuaian, rekonsiliasi per entitas, ekspor CSV — CO-FR-10
    - [x] **B-05f6** SPA untuk kebijakan translasi, kurs, eliminasi, penyesuaian, dan hasil formal — belum ada layar untuk mengelola grant user lain pada grup (menunggu keputusan siapa yang berhak memberi grant)
      - [x] **B-05f6a** Translasi: kebijakan berversi, jenis kurs, dan kurs grup — CO-FR-06
      - [x] **B-05f6b** Run formal dan toleransi eliminasi: pilihan `formal` pada dialog run, `elimination_tolerance` pada pengaturan grup, `cash_flow_class` pada form akun grup, penolakan run formal yang menyebut penyebabnya — CO-FR-05, CO-FR-07, CO-FR-10
      - [x] **B-05f6c** Halaman run: label dan penanda draf, kurs dan translasi per entitas, eliminasi dan pengecualian selisih — CO-FR-05, CO-FR-06, CO-FR-07
      - [x] **B-05f6d** Penyesuaian konsolidasi: usul, setujui, tolak — CO-FR-08, CO-AC-04
      - [x] **B-05f6e** Keluaran formal: neraca, laba rugi, arus kas, laporan eliminasi dan penyesuaian, rekonsiliasi per entitas, ekspor CSV — CO-FR-10
- [x] **B-06** Saldo piutang dan utang per pihak — SL-FR-08
- [x] **B-08** Dokumen `loan` untuk kasbon karyawan dan pinjaman — SL-FR-04, SL-AC-10
- [ ] **B-07** Seeder data demo untuk presentasi — [DEMO.md](DEMO.md) — belum selesai dan belum di-commit (`database/seeders/DemoSeeder.php`, `database/seeders/Demo/*`); dikerjakan terakhir, setelah B-09 sampai B-12 dan B-14 sampai B-17, karena fitur-fitur itu mengubah datanya. Subitem seeder B-09g, B-10f (jenis Biaya Tunai, Penerimaan Tunai Lain, Biaya atau Bunga Bank, dicocokkan dengan rekening koran) dan pembuktian di browser, dan B-15e digabung di sini; seeder B-12e sudah ada dan satu lari penuh sudah sukses (lihat di bawah).
  - **Hutang pekerjaan:**
    - Satu lari penuh sudah sukses (2026-10-31, 359 detik, 5454 panggilan API) dengan perubahan di bawah ini, tetapi angkanya **belum diperiksa**: piutang pelanggan dan tagihan pemasok yang sengaja menunggak (agar bucket umur di atas 90 hari terisi), dividen April 2026 dengan dua tahap persetujuan (pemasok `saham` di `DemoFoundation`), gaji dan iklan 2026 lebih tinggi beserta anggarannya, dan `waiveOpeningCash($today)` setelah perulangan bulan di `DemoSeeder`. Periksa neraca saldo, neraca, arus kas, rekonsiliasi, umur piutang dan utang.
    - Pint terhadap `database/seeders` lulus; Larastan dan deptrac belum diperiksa untuk berkas seeder.
    - Margin 2026 masih tinggi (laba sebelum pajak sekitar 19 persen berbanding sekitar 14 persen pada 2025) pada lari yang terakhir; lihat apakah penyesuaian di atas cukup.
    - Belum dilihat di browser: layar tiap fitur, cakupan data kepala cabang Surabaya, peristiwa klien ERP di kotak persetujuan, kolom piutang dan utang di daftar Pihak.
    - Belum dijalankan di database kerja. Seeder hanya menambah data; uji coba memakai database terpisah `accounting_demo` (dan `accounting_demo_tpl` sebagai cetakan migrasi) di PostgreSQL lokal, yang boleh dihapus.
    - Jalankan di terminal biasa, bukan sebagai proses latar belakang: Windows memperlambatnya sekitar tujuh kali lipat. `DEMO_TODAY` memendekkan pembukuan untuk uji coba, `DEMO_TIMING=1` mencatat detik per tahap. [DEMO.md](DEMO.md) menyebut 4 sampai 6 menit; ukur ulang setelah lari penuh berikutnya.
    - Tidak dicakup karena hanya satu perusahaan dan rupiah: konsolidasi grup, valas, 2FA dan passkey, webhook.
- [x] **B-09** Peran pihak sebagai data, pembatas peran pada field Pihak, dan profil posting pada rute — SL-FR-01, SL-FR-02, TE-FR-04, TE-FR-10 — [ADR-0015](adr/0015-party-roles-as-data.md). `is_customer` dan `is_supplier` dihapus dari `/api/v1` tanpa masa transisi (pengecualian pra-rilis atas API-TR-01). Satu commit per butir, masing-masing dengan test.
  - [x] **B-09a** Dokumen: SL-FR-01 dan SL-FR-02, TE-FR-04 dan TE-FR-10, API-CONTRACT, glosarium PRODUCT ("Peran pihak" `PartyRole`, dibedakan dari peran akun `AccountRole` dan `ControlRole`), dan rujukan PSAK 7 di REFERENCES untuk ditinjau akuntan
  - [x] **B-09b** Periksa dulu: rekonsiliasi akun kontrol dan laporan umur dengan lebih dari satu profil posting (data uji punya profil Karyawan ke akun 1171). Bila open item belum mencatat akun kontrol dokumennya, tambahkan; ini syarat B-09d — terbukti tanpa perubahan kode: rekonsiliasi memakai akun kontrol jurnal dokumen, laporan umur tetap per `control_role` (tidak per akun)
  - [x] **B-09c** Backend peran pihak: tabel peran dan penugasan pada pihak, impor peran bawaan dengan konfirmasi, migrasi `is_customer` dan `is_supplier`, hapus syarat peran di pencatat dokumen dan impor open item awal, API `roles` serta penghapusan bidang lama — SL-FR-01 — OpenAPI dan tipe diekspor di B-09f bersama SPA, yang masih memakai `is_customer`/`is_supplier`; seeder demo (`DemoFoundation`) belum diubah (B-09g)
  - [x] **B-09d** Rute dengan profil posting sendiri: `posting_profile_id` opsional, validasi saat terbit dan saat entri, `MAPPING_REQUIRED` bila pemetaan hilang — SL-FR-02, TE-FR-10 — OpenAPI dan tipe diekspor di B-09f; perancang SPA (B-09f) harus mengirim `subledger_route.posting_profile_id`
  - [x] **B-09e** Field Pihak dengan `allowed_roles`: skema field, validasi terbit, validasi entri dan peristiwa, daftar pilihan pihak disaring — TE-FR-04, TE-FR-08 — daftar pihak disaring lewat `filter[roles][]`; test peristiwa belum ada (jalurnya sama dengan entri)
  - [x] **B-09f** SPA: layar peran pihak, form dan filter pihak, perancang (peran yang boleh dipilih, profil pada rute), form entri, aging dan daftar pihak; OpenAPI dan tipe — filter pihak di laporan umur tidak lagi menyaring menurut peran (peran tidak menentukan sisi buku besar)
  - [x] **B-09g** Template dan panduan: peran bawaan; Kasbon dan Setoran Modal berbentuk piutang pemegang saham memakai peran dan profilnya; seeder demo — template tidak dapat menamai profil posting (milik perusahaan), jadi profil pada rute diatur di versi baru setelah impor (panduan akuntan); `DemoFoundation` sudah memakai `roles` tetapi belum dijalankan, dijalankan bersama B-07
  - [x] **B-09h** Pembuktian di browser dan manual book: kasbon dengan peran dan profil Karyawan, orang berperan ganda yang saldonya tidak bercampur (rute dengan profil sendiri), dan daftar pihak yang disaring menurut peran — hasil di [TEMUAN.md](manual-book/TEMUAN.md); tangkapan layar belum disimpan ke repo
- [x] **B-10** Kas tanpa akun kontrol: jenis transaksi untuk biaya tunai, setoran modal tunai, bunga bank, dan pendapatan tunai tanpa pihak — SL-FR-03, SL-FR-11, TE-FR-10 — [ADR-0016](adr/0016-cash-only-route.md): rute `cash`, bukan dokumen; pembalikan didukung. Satu commit per butir, masing-masing dengan test.
  - [x] **B-10a** Dokumen: SL-FR-03/11/12, TE-FR-10, panduan (hapus "Biaya tunai tanpa pihak")
  - [x] **B-10b** Backend `CashEntryPosting` di Subledger: kontrak `validateCashEntry` dan `postCashEntry`, pemeriksaan rekening, sisi, mata uang, dan baris nyasar, pencatatan transaksi kas; buktikan lebih dulu bahwa tabel kas tidak perlu migrasi — SL-FR-11 — tanpa migrasi terbukti; dipanggil `TransactionEntry` di B-10c, pembalikannya di B-10d
  - [x] **B-10c** Rute `cash` di TransactionEntry: normalisasi dan validasi terbit (tepat satu aturan kas, tanpa aturan akun kontrol, tanpa field pihak), validasi dan posting entri, peristiwa dan changes feed — TE-FR-10 — peristiwa hanya meneruskan `references.source_*`, jadi nomor bukti dari peristiwa tidak sampai ke field; referensi kas = nomor jurnal
  - [x] **B-10d** Pembalikan jurnal hanya-kas dan transfer: transaksi kas berlawanan arah, pembalikan ganda ditolak — SL-FR-12 — dipanggil dari jalur pembalikan entri yang sudah ada (`EntryDocuments::reverse`); SPA tidak berubah
  - [x] **B-10e** SPA: jenis rute "Kas tanpa pihak" di perancang, ringkasan versi, label dua bahasa; OpenAPI dan tipe
  - [x] **B-10f** Template (Biaya Tunai, Penerimaan Tunai Lain, Biaya atau Bunga Bank), seeder demo, panduan, dan pembuktian di browser: biaya tunai, setoran modal tunai, bunga bank dicocokkan dengan rekening koran, rekonsiliasi kas tanpa selisih — template, test, dan panduan selesai; seeder demo dan pembuktian di browser digabung ke B-07 (belum dikerjakan)
- [ ] **B-11** Katalog template per jenis bisnis — P-01, X-AC-03, TE-FR-01, GL-FR-01, RP-FR-01 — paket template per jenis usaha (dagang atau ritel, jasa, kontraktor atau proyek, manufaktur ringan, F&B) plus modul pasang-terpisah (kasbon dan pinjaman, pajak, persediaan dan HPP, proyek), ditinjau dan dikonfirmasi per bagian, berversi, dengan pilihan sederhana untuk usaha kecil tanpa akuntan. Bergantung pada B-09 dan B-10. Perlu ADR sendiri. Batas: tanpa stok per item (persediaan dan HPP hanya akun dan jurnal penyesuaian); pajak hanya akun dan jenis transaksi, bukan mesin pajak.
- [x] **B-12** Rencana kegiatan: periode mandiri, ruang lingkup dimensi, dan evaluasi estimasi awal, revisi, dan aktual — PL-FR-01 sampai PL-FR-04, PL-AC-02, RP-FR-11, RP-FR-12 — [ADR-0017](adr/0017-activity-plans.md). Tidak bergantung pada B-09 dan B-10. Satu commit per butir, masing-masing dengan test.
  - [x] **B-12a** Dokumen: PL-FR-01 sampai PL-FR-04 dan PL-AC-02, RP-FR-11 dan RP-FR-12, glosarium
  - [x] **B-12b** Backend `Planning`: judul, tujuan, periode mandiri, ruang lingkup dimensi (tetap setelah versi pertama terbit), migrasi rencana lama, validasi baris terhadap periode rencana, `PlanReader` — judul tetap kolom dan field `name`; `fiscal_year_id` hilang dari `/plans` (filter `filter[fiscal_year_id]` kini berarti periode rencana beririsan dengan tahun itu); versi default per tahun buku hanya untuk rencana tanpa ruang lingkup yang beririsan dengan tahun itu; baris boleh di periode GL yang beririsan dengan periode rencana. OpenAPI dan tipe diekspor di B-12d bersama SPA, yang masih mengirim `fiscal_year_id`; seeder demo (`DemoPlanning`) sudah disesuaikan tetapi belum dijalankan (B-07)
  - [x] **B-12c** Evaluasi: realisasi dari buku besar per periode dan akun pada ruang lingkup beserta turunannya, perbandingan versi (awal, revisi, aktual), selisih dan persen, selisih bertanda kumulatif; buktikan rentang lintas tahun buku dan kecocokan dengan laba rugi berfilter dimensi — `GET /reports/plan-evaluation?plan_id=` (permission `reports.read`); selisih kumulatif dihitung atas hasil (pendapatan − beban) per periode, positif = menguntungkan; kecocokan dibuktikan terhadap neraca saldo berfilter dimensi yang sama, bukan laporan definisi. OpenAPI dan tipe diekspor di B-12d
  - [x] **B-12d** SPA: form rencana (judul, tujuan, periode, pilih dimensi), daftar, editor baris per periode rencana, layar evaluasi; OpenAPI dan tipe — layar evaluasi di `/plans/{id}/evaluation` (izin `reports.read`); periode, ruang lingkup, dan kolom grid mengikuti periode rencana; dialog kebijakan hanya menawarkan versi dari rencana tanpa ruang lingkup yang beririsan dengan tahun buku. Panduan, seeder, dan pembuktian di browser di B-12e
  - [x] **B-12e** Panduan, seeder demo (mis. kegiatan promosi enam bulan), dan pembuktian di browser dengan studi kasus keputusan bisnis: estimasi awal, revisi, dan aktual, serta strategi induk dengan taktik anak — dimensi `activity` ("Kegiatan", bertingkat) dan field `activity` pada Penjualan Kredit dan Pembelian dibuat oleh seeder demo; lari penuh `DemoSeeder` di `accounting_demo` sukses (359 detik) dan layar evaluasi terbukti di browser; tangkapan layar belum disimpan ke repo
- [x] **B-13** Katalog jenis transaksi pada entri baru — TE-FR-01, TE-FR-03
- [x] **B-14** Katalog jenis transaksi pada jadwal jurnal berulang — TE-FR-01, TE-FR-14
- [ ] **B-15** Rincian akun pada laporan Neraca, Laba Rugi, dan Arus Kas — RP-FR-01, P-07, X-FR-02 — baris `accounts` dirinci per akun daun dengan nama dan nilai pada setiap kolom; jumlah rincian sama dengan nilai baris. Rincian adalah opsi per baris pada definisi (templat bawaan menyalakannya); induk tampil sebagai sub-judul bersubtotal, urut kode, akun bernilai nol di semua kolom disembunyikan; snapshot lama tetap terbaca; ekspor CSV mengikuti. Pada arus kas tidak langsung hanya baris `cash_equivalents` yang dirinci per rekening kas. Satu commit per butir, masing-masing dengan test.
  - [x] **B-15a** Dokumen: RP-FR-01 (opsi rincian pada baris `accounts` dan `cash_equivalents`), glosarium bila perlu, dan CHART-OF-ACCOUNTS.md ("Saran definisi laporan") — opsi bernama `account_detail`, kriteria baru RP-AC-12; glosarium tidak berubah
  - [x] **B-15b** Mesin laporan: nilai per akun daun dan per induk pada setiap kolom (periode, tahun berjalan, pembanding, rencana), jumlah rincian sama dengan nilai baris; buktikan kecocokan dengan neraca saldo dan laba rugi berfilter dimensi; akun tak terpetakan tetap dilaporkan — `ReportEngine` membaca `account_detail` pada baris dan menghasilkan `account_lines` (`account_id`, `code`, `name`, `level`, `is_leaf`, `values`); struktur belum menerima opsi itu (B-15c), jadi test memanggil mesin langsung; kecocokan dengan neraca saldo terbukti, dengan laba rugi berfilter dimensi belum
  - [x] **B-15c** Definisi: opsi rincian pada struktur dan validasi, versi definisi lama tetap berlaku tanpa rincian, templat bawaan menyalakannya; snapshot menyimpan rincian dan snapshot lama tetap terbaca; OpenAPI dan tipe — `account_detail` pada baris `accounts` dan `cash_equivalents` (hanya disimpan bila `true`, sehingga mengubahnya menerbitkan versi baru); snapshot menyimpan `account_lines` di `result` tanpa perubahan kode; templat `general-id` menyalakannya kecuali baris perubahan akun di arus kas tidak langsung
  - [x] **B-15d** SPA: penampil menampilkan baris akun dengan nama, indentasi, sub-judul induk, dan nilai per kolom; kode akun menjadi tautan kecil ke buku besar di baris itu, bukan isi utama; editor definisi memuat opsi; ekspor CSV; label dua bahasa — CSV memuat baris akun bertipe `account`/`account_group` dengan kunci `<baris>:<kode>`; tampilan di browser belum dilihat (B-15e)
  - [ ] **B-15e** Panduan, seeder demo, dan pembuktian di browser: Neraca, Laba Rugi, dan Arus Kas dibaca sampai akun tanpa membuka buku besar, dengan tangkapan layar manual book — panduan selesai; seeder demo dan pembuktian di browser digabung ke B-07 (templat `general-id` sudah menyalakan rincian, jadi seeder tidak perlu diubah, hanya dilihat di browser)
- [ ] **B-16** Ekspor laporan ke PDF dan Excel, selain CSV — X-FR-02, API-TR-09, RP-FR-01 — [ADR-0018](adr/0018-report-export-pdf-excel.md): server, `openspout` dan `dompdf` di Platform/Export, sinkron dengan batas baris. Otorisasi, filter, dan dimensi sama dengan sumbernya; angka sama dengan layar (Excel berupa angka); kop memuat perusahaan, laporan, periode, mata uang, dan waktu. Cakupan awal: Neraca, Laba Rugi, Arus Kas, neraca saldo, dan buku besar. Dikerjakan setelah B-15; periksa dulu stylesheet cetak.
  - [x] **B-16a** Dokumen: ADR-0018, X-FR-02, API-TR-09
  - [ ] **B-16b** Platform/Export: model tabel netral dengan penerjemah CSV, XLSX, dan PDF, kop, batas baris, dan pembuktian hal-hal di ADR (huruf, rumus, lanskap) — X-FR-02
  - [ ] **B-16c** Negosiasi `Accept` pada definisi laporan, neraca saldo, dan buku besar; kode kesalahan batas baris; OpenAPI dan tipe — X-FR-02, API-TR-09, RP-AC-12
  - [ ] **B-16d** SPA: tombol ekspor menawarkan CSV, Excel, dan PDF; pesan batas baris; label dua bahasa
  - [ ] **B-16e** Panduan dan pembuktian di browser dengan berkas yang dibuka di Excel dan penampil PDF
- [x] **B-17** Rekonsiliasi akun kontrol hanya menyimpan selisih — SL-FR-09
