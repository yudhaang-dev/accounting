# TODO — Rencana Implementasi

> Urutan kerja implementasi. Setiap item ≈ satu PR dan merujuk ID requirement; teks requirement tidak disalin. Status awal: belum dimulai.

**Definisi selesai:** kode + test Pest untuk setiap AC yang dirujuk + spesifikasi OpenAPI terbarui + layar SPA terkait (bila ada) + CI lulus (Pint, Larastan, Pest, deptrac).

**Urutan:** M0 menyiapkan fondasi; M1 membangun irisan end-to-end tipis untuk menguji arsitektur; M2–M8 memperdalam modul sesuai graf dependensi. Requirement yang sebagian dikerjakan lebih awal dilengkapi di milestone berikutnya.

## M0 — Fondasi

- [x] **M0-01** Bersihkan starter kit: hapus Inertia dan Wayfinder (paket PHP/JS, middleware, halaman); Fortify mode headless dengan respons JSON — ADR-0010, ADR-0005
- [x] **M0-02** Pindah ke PostgreSQL (`.env.example`, test, ekstensi `btree_gist`) — ADR-0003
- [x] **M0-03** Pasang paket ADR-0004 dan verifikasi kompatibilitasnya dengan Laravel 13 — ADR-0004 — `spatie/laravel-webhook-server` ditunda ke B-02, `laravel/horizon` ke persiapan produksi Linux
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
- [x] **M1-08** Neraca saldo dengan drill-down dan ekspor CSV (Platform Export) — RP-FR-05, RP-AC-01, X-FR-02, API-AC-07 — penanda batch saldo konversi di M8-08
- [x] **M1-09** SPA: pemilih workspace, wizard setup perusahaan (profil, konfirmasi template COA, kalender), akun, periode, form dan daftar entri beserta aksinya, detail jurnal, neraca saldo
  - [x] **M1-09a** Shell SPA dan pemilih workspace
  - [x] **M1-09b** Wizard setup perusahaan (profil, konfirmasi template COA, kalender)
  - [x] **M1-09c** Daftar akun dan periode
  - [x] **M1-09d** Form dan daftar entri beserta aksinya (submit, approve, post) — jenis transaksi pertama dibuat dari form oleh pemegang `policies.manage` sampai ada perancang di M5-14
  - [x] **M1-09e** Detail jurnal
  - [x] **M1-09f** Neraca saldo (drill-down dan ekspor CSV)
- [x] **M1-10** Test feature end-to-end untuk seluruh alur M1 — belum dijalankan di CI (butuh layanan PostgreSQL dan unduhan browser)

## M2 — Data induk

- [x] **M2-01** Tipe dan nilai dimensi: hierarki, masa berlaku, aturan hanya-daun, nonaktif — MD-FR-02, MD-FR-03, MD-AC-03, MD-AC-04
- [x] **M2-02** Resolusi dimension set dan query hierarki; dipakai baris entri, jurnal, dan filter neraca saldo — MD-FR-04, MD-FR-05, MD-AC-01, MD-AC-02
- [x] **M2-03** Mata uang ISO 4217, aktivasi per perusahaan, skala dan pembulatan — MD-FR-06
- [x] **M2-04** Jenis kurs dan kurs (manual, CSV, API `exchange-rates:write`), pencarian tanpa fallback, batas umur — MD-FR-07, MD-FR-08, MD-AC-06
- [x] **M2-05** SPA: dimensi, mata uang, kurs, pemilih dimensi di form entri

## M3 — Identitas & akses

- [x] **M3-01** Role sebagai data dan endpoint katalog permission — IA-FR-02, IA-AC-03
- [x] **M3-02** Siklus membership: undangan, pencabutan, kedaluwarsa — IA-FR-01, IA-AC-02
- [x] **M3-03** Cakupan data per nilai dimensi beserta turunannya, diterapkan sebagai filter di semua modul — IA-FR-03, IA-AC-04, X-AC-01 — laporan M8 menerima `DataScope` dan menandai `restricted`; entri klien integrasi (M3-05) butuh cakupan sendiri
- [x] **M3-04** Kebijakan keamanan: 2FA wajib per permission, batas sesi idle, allowlist IP klien — IA-FR-04, IA-FR-05, IA-AC-05 — 2FA hanya memeriksa 2FA terpasang di akun, bukan tantangan per sesi
- [x] **M3-05** Klien integrasi: Passport Client Credentials di `/api/v1/oauth/token`, scope, allowlist, rotasi dan pencabutan langsung — IA-FR-06, IA-AC-06, IA-AC-07, API-TR-22, API-TR-23, API-TR-24. Deploy: `php artisan passport:keys`, migrasi Passport, atur `API_TOKEN_TTL_SECONDS`
- [x] **M3-06** SPA: anggota dan role, cakupan data, kebijakan keamanan, klien integrasi (secret tampil sekali)

## M4 — Buku besar

- [x] **M4-01** Atribut posting akun: sumber yang diizinkan, kewajiban dimensi, batasan mata uang, penanda revaluasi — GL-FR-02, GL-AC-02 — layar SPA di M4-12, yang perlu menimpa tipe `dimension_requirements` dari OpenAPI
- [x] **M4-02** Status `soft_closed` dengan kebijakan per key Jenis Transaksi, tutup/buka periode beralasan, pola kalender 4-4-5 dan rentang bebas — GL-FR-03, GL-FR-04, GL-AC-04 — penandaan hasil turunan saat membuka kembali di M8-11; layar SPA di M4-12
- [x] **M4-03** Pembalikan jurnal di sisi GL — GL-FR-06, GL-AC-11
- [x] **M4-04** Kebijakan override akun kontrol — GL-FR-07 — alur entri membawa alasan override di M5
- [x] **M4-05** Baris valas, pembulatan ke `fx_rounding`, saldo per mata uang — GL-FR-08, GL-AC-05 — endpoint saldo per mata uang belum ada; penolakan aktivasi mata uang tanpa pemetaan peran belum
- [x] **M4-06** Pemetaan peran GL berversi dan interface validasi target peran — GL-FR-10, X-AC-02, X-AC-03
- [x] **M4-07** Revaluasi akun non-kontrol dengan kebijakan pembalikan otomatis — GL-FR-09, GL-AC-06 — revaluasi open item di M6-07
- [x] **M4-08** Impor saldo konversi: `cutover_date`, `ledger_start_date`, pemetaan kode sumber, saldo YTD, idempoten — GL-FR-11, GL-AC-07 — baris akun kontrol baru dapat diimpor setelah Subledger menyuplai total open item (M6-10); penanda batch di neraca saldo menyusul di M8-08
- [x] **M4-09** Impor histori (ringkasan per periode atau transaksi) dan pencocokan dengan neraca saldo sumber pada cutover — GL-FR-11 — histori pada akun kontrol baru dapat diimpor setelah Subledger menyuplai total open item (M6-10)
- [x] **M4-10** Tutup tahun: orkestrasi kesiapan, jurnal penutup per akun × set dimensi, saldo tahun berikutnya — GL-FR-12, GL-FR-13, GL-AC-09 — kesiapan baru memeriksa entri belum selesai; rekonsiliasi subledger menyusul di M6-09
- [x] **M4-11** Buka kembali tahun buku — GL-FR-14, GL-AC-10 — penandaan snapshot laporan untuk dihitung ulang menyusul di M8-11
- [x] **M4-12** SPA: atribut akun, manajemen periode, revaluasi, wizard saldo konversi, checklist tutup tahun — wizard impor belum memetakan dimensi (API sudah mendukungnya)
  - [x] **M4-12a** Atribut posting pada form akun
  - [x] **M4-12b** Manajemen periode: tutup sementara, tutup, buka kembali
  - [x] **M4-12c** Pengaturan buku besar: kebijakan dan pemetaan peran akun
  - [x] **M4-12d** Revaluasi per periode
  - [x] **M4-12e** Wizard saldo konversi dan histori
  - [x] **M4-12f** Checklist tutup dan buka kembali tahun buku

## M5 — Pencatatan transaksi

- [x] **M5-01** Mode `structured`: skema field (subset JSON Schema), penerbitan dan penghentian versi — TE-FR-02, TE-FR-04, TE-AC-01, TE-AC-06 — perancang SPA di M5-14
- [x] **M5-02** Aturan posting: sumber akun, penerapan dimensi, pemakaian ulang eksplisit, baris nol dilewati, baris `fx_difference` — TE-FR-05, TE-AC-02
- [x] **M5-03** Mata uang dan kurs entri — TE-FR-06
- [x] **M5-04** Kebijakan approval/SoD: aturan per key, sumber, dan ambang; tahap dan permission; posting otomatis setelah approval — TE-FR-09, TE-AC-05
- [x] **M5-05** Validasi lengkap saat submit dan posting — TE-FR-08, TE-AC-03
- [x] **M5-06** Pembalikan, penggantian, dan pembalikan terjadwal — TE-FR-11, TE-AC-09 — pembalikan jurnal sumber subledger masih langsung ke GL, dialihkan di M6-08
- [x] **M5-07** Endpoint Peristiwa Keuangan beserta status HTTP-nya — TE-FR-12, API-TR-16, API-TR-17, API-TR-18, API-TR-19, API-TR-20, API-AC-03, API-AC-05, API-AC-06
- [x] **M5-08** Idempotensi kunci sumber dengan payload kanonis, aman terhadap kiriman bersamaan — TE-FR-13, TE-AC-04, API-AC-04
- [x] **M5-09** Changes feed berbasis cursor — TE-FR-15, TE-AC-08
- [x] **M5-10** Jurnal berulang — TE-FR-14
- [x] **M5-11** Penomoran entri per Jenis Transaksi — TE-FR-16 — pengaturan seri dan titik pemberian nomor lewat layar SPA di M5-14
- [x] **M5-12** Lampiran entri (Platform Attachments) — X-FR-04 — `Attachments` siap dipakai dokumen subledger di M6-04; layar SPA di M5-14
- [x] **M5-13** Template Jenis Transaksi yang wajib dikonfirmasi — TE-FR-01 — template `general` baru berisi jenis tanpa akun tetap; Penjualan Kredit menunggu rute subledger di M6-04; layar SPA di M5-14
- [x] **M5-14** SPA: perancang Jenis Transaksi, form dinamis dari skema, kotak approval, kebijakan approval, jadwal berulang, alur pembalikan
  - [x] **M5-14a** Daftar Jenis Transaksi: buat jenis, impor template dengan tinjauan dan konfirmasi, seri nomor dan kebijakan titik penomoran — TE-FR-01, TE-FR-16
  - [x] **M5-14b** Perancang versi: editor field, aturan posting, kebijakan mata uang, terbitkan dan hentikan — TE-FR-02, TE-FR-04, TE-FR-05, TE-FR-06 — tipe field `party`, `cash_account`, dan `allocations` belum ditawarkan sampai lookup subledger ada (M6)
  - [x] **M5-14c** Form entri dinamis dari skema versi `structured`, termasuk mata uang dan kurs — TE-FR-04, TE-FR-06, TE-AC-01 — field `party`, `cash_account`, dan `allocations` tampil sebagai catatan sampai lookup subledger ada (M6)
  - [x] **M5-14d** Lampiran pada detail entri dan kotak approval (entri menunggu tahap yang bisa disetujui user) — X-FR-04, TE-FR-09
  - [x] **M5-14e** Kebijakan approval: aturan per key, sumber, ambang, tahap, SoD, posting otomatis — TE-FR-09, TE-AC-05
  - [x] **M5-14f** Pembalikan jurnal, entri pengganti, `auto_reverse_on`, dan daftar pembalikan terjadwal yang gagal — TE-FR-11, TE-AC-09
  - [x] **M5-14g** Jadwal berulang: buat, ubah, lewati, dan kegagalan yang terlihat — TE-FR-14

## M6 — Subledger

- [x] **M6-01** Pihak dan referensi eksternal per `source_system` — SL-FR-01, SL-AC-08 — profil posting pada pihak menyusul di M6-02; layar SPA di M6-12
- [ ] **M6-02** Profil posting dan peran akun kontrol — SL-FR-02
- [ ] **M6-03** Rekening kas/bank dan transaksi kas berstatus `cleared` — SL-FR-03, SL-FR-11
- [ ] **M6-04** Dokumen dan open item; rute subledger dari entri; sumber akun kontrol dan rekening pada aturan posting — SL-FR-04, TE-FR-05, TE-FR-10
- [ ] **M6-05** Alokasi, uang muka, dan pembatalan alokasi — SL-FR-05, SL-AC-03
- [ ] **M6-06** Selisih kurs terealisasi — SL-FR-06, SL-AC-02, TE-AC-10
- [ ] **M6-07** Revaluasi open item — SL-FR-07, SL-AC-06
- [ ] **M6-08** Pembalikan dokumen — SL-FR-12, SL-AC-07, TE-AC-07
- [ ] **M6-09** Rekonsiliasi akun kontrol dan waiver; integrasi ke kesiapan tutup tahun — SL-FR-09, SL-AC-05, GL-AC-09
- [ ] **M6-10** Impor open item awal dan pencocokan total kontrol dengan batch saldo konversi — SL-FR-10, SL-AC-01, GL-AC-07
- [ ] **M6-11** Laporan aging dengan bucket kebijakan — SL-FR-08, SL-AC-04
- [ ] **M6-12** SPA: pihak, profil posting, rekening, open item dan alokasi, rekonsiliasi, aging

## M7 — Anggaran & proyeksi

- [ ] **M7-01** Rencana dan versi (salin saat mengubah versi terbit) — PL-FR-01, PL-AC-01
- [ ] **M7-02** Baris rencana, impor/ekspor CSV, salin dari versi lain atau realisasi — PL-FR-02, PL-FR-03, PL-AC-02
- [ ] **M7-03** Penerbitan, kebijakan penerbit, dan versi pembanding default — PL-FR-04, PL-AC-03
- [ ] **M7-04** SPA: editor grid rencana, impor, penerbitan

## M8 — Pelaporan

- [ ] **M8-01** Engine definisi laporan: baris, kolom, dasar nilai, tanda, kumpulan akun, baris pengecualian, versi — RP-FR-01, RP-FR-02, RP-AC-07
- [ ] **M8-02** Filter dan agregasi: dimensi dengan hierarki, cakupan data, key Jenis Transaksi, sumber — RP-FR-04, RP-AC-03
- [ ] **M8-03** Peran `cash_equivalents` dan klasifikasi mutasi — RP-FR-03
- [ ] **M8-04** Laba rugi — RP-FR-07, RP-AC-05
- [ ] **M8-05** Neraca dan `current_year_earnings` — RP-FR-08, RP-AC-01, RP-AC-06
- [ ] **M8-06** Arus kas metode langsung dan tidak langsung — RP-FR-09, RP-AC-02
- [ ] **M8-07** Perubahan ekuitas — RP-FR-10, RP-AC-09
- [ ] **M8-08** Laporan buku besar dan mode kertas kerja neraca saldo — RP-FR-05, RP-FR-06, RP-AC-11
- [ ] **M8-09** Proyeksi vs realisasi dan aturan selisih — RP-FR-11, RP-FR-12, RP-AC-04
- [ ] **M8-10** Cakupan histori laporan — RP-FR-13, RP-AC-10
- [ ] **M8-11** Snapshot laporan terbit dan hitung ulang — RP-FR-14, X-FR-03, RP-AC-08, X-AC-04
- [ ] **M8-12** Template definisi laporan dari [CHART-OF-ACCOUNTS.md](CHART-OF-ACCOUNTS.md) — RP-FR-01
- [ ] **M8-13** SPA: penampil laporan (filter, pembanding, drill-down, ekspor), editor definisi dan klasifikasi, penerbitan snapshot

## Backlog

### Fase 2

- [ ] **B-01** Rekonsiliasi bank lewat impor mutasi — SL-FR-13
- [ ] **B-02** Webhook bertanda tangan lewat outbox — API-TR-25
- [ ] **B-03** Endpoint batch event — TE-FR-17, API-TR-26
- [ ] **B-04** Atribusi pembayaran ke akun biaya asal untuk metrik kas — RP-FR-11

### Post-MVP

- [ ] **B-05** Modul konsolidasi: seluruh [CONSOLIDATION.md](CONSOLIDATION.md), grant grup, `counterparty_company_id`, `ReportPackage` — IA-FR-07, GL-FR-15
