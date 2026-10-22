# ADR-0014 — Konsolidasi Bertahap

Status: Diusulkan (2026-10-22)

## Konteks

B-05 mencakup seluruh [CONSOLIDATION.md](../CONSOLIDATION.md), IA-FR-07, dan GL-FR-15. Dokumen itu menyatakan translasi, eliminasi, dan perimeter formal harus ditetapkan bersama akuntan sebelum menjadi requirement, dan hasil tidak boleh berlabel konsolidasian formal sebelum fitur yang dibutuhkan grup tersedia. Semua modul MVP mengunci akses ke satu `company_id` (P-05), sedangkan laporan grup menembus batas itu.

## Keputusan

Konsolidasi dikerjakan dalam tiga tahap yang dapat dikirim terpisah. Tiap tahap hanya membuka kemampuan yang aturannya sudah pasti.

1. **Fondasi.** `counterparty_company_id` pada baris jurnal dan `Party` sebagai referensi opak tanpa validasi lintas perusahaan (GL-FR-15). Grant grup di `IdentityAccess` (IA-FR-07): tabel `ia_group_grants` berisi `group_id` (ULID opak) dan `user_id`, dengan ability `read` dan `run` yang disimpan pada grant itu sendiri, bukan di katalog permission, karena role adalah data per perusahaan sedangkan grup melintasi perusahaan. `run` mencakup `read`. Grant tidak diturunkan dari membership.
2. **Roll-up manajemen (Tahap A, CO-FR-01 sampai CO-FR-04).** Modul `Consolidation` (`co_`): `Group`, `Perimeter`, `GroupAccountMapping`, `ConsolidationRun`. `Reporting` menerbitkan `ReportPackage` (kontrak di `Contracts`) dari hasil definisi laporan dan neraca saldo per entitas. Hanya entitas dengan mata uang fungsional sama dengan mata uang penyajian grup yang dapat dijumlahkan; selain itu run ditolak (CO-AC-03) sampai Tahap B. Hasil selalu berlabel "roll-up manajemen".
3. **Konsolidasi formal (Tahap B, CO-FR-05 sampai CO-FR-10).** Translasi, eliminasi, penyesuaian, dan keluaran grup. Dimulai hanya setelah akuntan menetapkan kebijakan translasi, toleransi pencocokan, dan batas perimeter; sampai itu, label formal tidak pernah dipakai.

### Batas modul

- `Consolidation` bergantung pada `Reporting` dan `MasterData`, sesuai graf. Tidak ada dependensi ke `IdentityAccess`; pemeriksaan grant grup dan akses workspace dilakukan di lapisan HTTP/orkestrasi (DOMAIN-DEPENDENCIES prinsip 5), dan `group_id` hanya ULID stabil.
- Pembacaan `ReportPackage` lintas perusahaan dijalankan orkestrasi dengan konteks sistem yang sudah divalidasi terhadap grant grup. Ia tidak memberi user akses ke workspace entitas; drill-down ke entitas tetap mensyaratkan akses workspace itu (CO-FR-01).
- Penyesuaian dan eliminasi hanya ada di tabel `co_`; tidak ada jalur ke `GeneralLedger` (CO-AC-04, diuji dengan arch test dan test hitung baris jurnal).
- Run tidak pernah dimutasi. Perubahan perimeter, pemetaan, atau sumber membuat run baru yang mencatat versi paket, pemetaan, dan perimeter (CO-FR-09, X-FR-03).

### Keputusan terbuka untuk akuntan

- Kebijakan translasi: kurs penutup, kurs rata-rata, dan akun ekuitas historis; perlakuan selisih translasi.
- Toleransi pencocokan eliminasi dan perlakuan selisih pasangan.
- Apakah struktur bertingkat dan kepentingan nonpengendali masuk Tahap B atau tetap di luar cakupan.
- Kebijakan grup untuk paket berstatus periode belum ditutup atau cakupan histori parsial: `draft` saja atau ditolak (CO-FR-03).

## Konsekuensi

Roll-up manajemen dapat dipakai lebih awal tanpa klaim kepatuhan. Tahap B menunggu keputusan akuntansi dan tidak menahan Tahap A. `deptrac` dan ADR-0002 perlu memuat `Consolidation` saat kodenya dibuat; tidak ada perubahan graf dependensi.

## Alternatif ditolak

Satu PR untuk seluruh B-05: terlalu besar untuk ditinjau dan memaksa keputusan akuntansi yang belum ada. Menyimpan grant grup di `Consolidation`: akan membuat modul itu bergantung pada identitas atau memindahkan kepemilikan IA-FR-07 dari `IdentityAccess`.
