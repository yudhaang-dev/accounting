# Domain Dependencies — Kepemilikan Modul & Graf Dependensi

> Aturan batas modul untuk coding agent. Panah `A ─► B` berarti modul A boleh memakai interface publik modul B; panah bukan urutan aliran data. Istilah mengikuti glosarium [PRODUCT.md](PRODUCT.md).

## Prinsip

1. Satu aplikasi modular (modular monolith). Modul bukan service, database, atau deployment terpisah.
2. Setiap data dan aturan bisnis punya satu pemilik (P-10). Modul lain memakai interface pemilik; dilarang membaca atau menulis langsung tabel/model modul lain.
3. Dependensi membentuk graf berarah tanpa siklus. Modul tidak pernah memanggil balik modul yang bergantung padanya.
4. Lintas modul hanya ID stabil dan DTO kontrak; model ORM, tabel, dan objek internal tidak dibagikan.
5. Pemeriksaan hak user dilakukan di lapisan aplikasi/HTTP, bukan oleh modul bisnis yang saling memanggil.
6. Use case yang mengoordinasikan beberapa modul tanpa hubungan dependensi (orkestrasi) berada di lapisan aplikasi di atas modul. Tidak ada modul yang bergantung pada lapisan ini. Struktur kode ada di [ADR-0002](adr/0002-modular-monolith.md).

## Modul dan kepemilikan

| Modul (kode) | Konteks | Memiliki | Bergantung pada |
| --- | --- | --- | --- |
| `MasterData` | Perusahaan & Data Induk | Profil perusahaan, mata uang fungsional, tipe/nilai dimensi, `DimensionSet`, mata uang, jenis kurs, kurs | — |
| `IdentityAccess` | Identitas & Akses | User, workspace & membership, role/permission sebagai data, cakupan akses per perusahaan/dimensi, `IntegrationClient` (scope, `source_system`, allowlist Jenis Transaksi), kebijakan 2FA, grant grup konsolidasi (`Post`) | `MasterData` |
| `TransactionEntry` | Pencatatan Transaksi | `TransactionType` dan versinya (mode, skema field, validasi, `PostingRule`), `Entry` dan siklusnya, kebijakan approval/SoD, `FinancialEvent` dan idempotensi, jurnal berulang, changes feed | `Subledger`, `GeneralLedger`, `MasterData` |
| `Subledger` | Subledger | `Party`, `PostingProfile` dan peran akun kontrol, `CashAccount`, `OpenItem`, transaksi kas/bank, `Allocation`, selisih kurs terealisasi, revaluasi open item, aging, rekonsiliasi akun kontrol, rekonsiliasi bank (`F2`) | `GeneralLedger`, `MasterData` |
| `GeneralLedger` | Buku Besar | `Account` dan pembatasan sumber posting, kalender fiskal dan `Period`, `Journal` posted, saldo, pembalikan, revaluasi akun non-kontrol, `OpeningBalance`, tutup periode/tahun, `ClosingJournal`, peran `retained_earnings` dan peran selisih kurs | `MasterData` |
| `Planning` | Anggaran & Proyeksi | `Plan`, `PlanVersion`, baris rencana, status terbit | `GeneralLedger`, `MasterData` |
| `Reporting` | Pelaporan | `ReportDefinition`, `MovementClassification`, peran `cash_equivalents`, `current_year_earnings`, laporan keuangan, proyeksi vs realisasi, snapshot laporan terbit | `GeneralLedger`, `Planning`, `MasterData` |
| `Consolidation` (`Post`) | Konsolidasi | Grup dan perimeter, pemetaan akun grup, translasi, eliminasi, snapshot grup | `Reporting`, `MasterData` |

**Kemampuan lintas modul (bukan domain bisnis):** audit append-only, lampiran, ekspor, penomoran berpola, lokalisasi, outbox dan webhook (`F2`). Modul bisnis memakainya; kemampuan ini menerima data generik dan tidak pernah memanggil balik modul bisnis.

## Graf dependensi

```text
TransactionEntry ──► Subledger ──► GeneralLedger ──► MasterData
       └───────────────────────► GeneralLedger
Planning ──► GeneralLedger
Reporting ──► GeneralLedger
     └─────► Planning
IdentityAccess ──► MasterData
Consolidation (Post) ──► Reporting
Semua modul bisnis ──► MasterData
```

`MasterData` adalah hulu dan tidak bergantung pada modul bisnis mana pun. `GeneralLedger` tidak mengenal `TransactionEntry`, `Subledger`, `Planning`, `Reporting`, maupun `Consolidation`.

## Aturan alur

- **Entri ke posting.** `TransactionEntry` memvalidasi entri (skema, versi, dimensi, mata uang, akun, periode) lewat interface pemilik, lalu menjalankan approval sesuai kebijakan. Setelah disetujui, entri yang memerlukan rincian dikirim ke `Subledger`; selebihnya langsung ke `GeneralLedger`. Semua terjadi dalam satu transaksi DB. Satu entri menghasilkan tepat satu jurnal posted; pembalikannya adalah jurnal terpisah yang tertaut.
- **Posting lewat Subledger.** `Subledger` mencatat rincian (open item, alokasi, kas/bank), menambahkan baris selisih kurs terealisasi bila ada, lalu mengirim jurnal lengkap ke `GeneralLedger` dengan sumber `subledger`. Nilai kontrol tidak pernah diposting dua kali.
- **Pembatasan sumber posting.** `GeneralLedger` menyimpan daftar sumber yang diizinkan per akun sebagai data dan menolak posting dari sumber lain. `Subledger` memvalidasi bahwa akun yang dipetakannya sebagai akun kontrol hanya menerima sumber `subledger`.
- **Pembalikan.** Permintaan pembalikan adalah entri di `TransactionEntry` (tunduk pada approval) yang dirutekan ke pemilik posting asal: `Subledger` bila jurnal asal berasal dari subledger (open item ikut dibalik), selain itu `GeneralLedger`.
- **Proses sistem.** `GeneralLedger` membuat jurnal penutup dan revaluasi akun non-kontrol; `Subledger` membuat revaluasi open item. Proses ini memakai key Jenis Transaksi sistem dan dijalankan user berizin, bukan lewat entri.
- **Saldo konversi.** Use case aplikasi menjalankan impor dalam satu transaksi DB: `Subledger` mencatat open item awal dan mengembalikan total per akun kontrol; `GeneralLedger` memposting satu batch seimbang dan menolaknya bila baris akun kontrol tidak sama dengan total tersebut. Posting kontrol hanya terjadi sekali.
- **Kesiapan tutup buku.** Use case aplikasi mengumpulkan entri yang belum selesai dari `TransactionEntry` dan hasil rekonsiliasi dari `Subledger`, lalu mengirim command tutup ke `GeneralLedger`. `GeneralLedger` hanya memvalidasi invarian buku besarnya sendiri.
- **Klasifikasi jurnal.** Setiap jurnal menyimpan key dan versi Jenis Transaksi sebagai metadata opak. `GeneralLedger` tidak memvalidasi definisinya; `Reporting` memakainya untuk `MovementClassification` tanpa bergantung pada `TransactionEntry`. Saat konfigurasi, use case aplikasi memvalidasi key ke `TransactionEntry`.
- **Dimensi dan mata uang.** Modul menyimpan `dimension_set_id` dan kode mata uang; validasi dan resolusinya lewat `MasterData`. Perubahan nama atau status dimensi tidak mengubah histori.
- **Pemetaan peran akun.** Tiap modul menyimpan target ID akun untuk peran yang dimilikinya dan memvalidasinya lewat `GeneralLedger` (akun aktif, daun, perusahaan sama, kelas sesuai). Tidak ada modul pusat pemetaan. Kode akun template tidak pernah menjadi syarat algoritme.
- **Fakta domain.** Setelah commit, `GeneralLedger` dapat menerbitkan `JournalPosted`, `JournalReversed`, `OpeningBalancesPosted`, `PeriodClosed`, `PeriodReopened`, dan `FiscalYearClosed`. Consumer bersifat idempoten dan tidak memanggil balik penerbit.
- **Proyeksi.** `Planning` menyimpan rencana dan tidak pernah membuat jurnal. `Reporting` menghitung realisasi dari jurnal posted dan membandingkannya dengan versi rencana yang dipilih.
- **Klien integrasi.** Lapisan aplikasi meneruskan konteks principal (perusahaan, `source_system`, allowlist) ke `TransactionEntry`. `source_system` adalah atribut principal dan bagian kunci idempotensi, bukan bukti otorisasi.
- **Antarentitas (`Post`).** Sumber dapat memberi `counterparty_company_id`; `GeneralLedger` menyimpannya sebagai referensi opak. Tanpa ID pihak lawan, transaksi tidak boleh dieliminasi otomatis.

## Invarian pemilik

- Hanya `GeneralLedger` yang membuat jurnal posted, membalik jurnal, membuka/menutup periode, menutup tahun buku, dan menjaga keseimbangan debit-kredit.
- `TransactionEntry` tidak pernah menulis buku besar langsung; klien integrasi tidak punya jalur untuk melewati validasi, periode, atau approval.
- `Subledger` memiliki rincian dan rekonsiliasi akun kontrol; ia hanya mengubah saldo buku besar lewat posting `GeneralLedger`.
- `Reporting` hanya membaca; koreksi dilakukan di pemilik data, lalu laporan dihitung ulang.
- `MasterData` memiliki ID dan hierarki; referensi yang sudah dipakai dinonaktifkan, tidak dihapus.
- `Consolidation` tidak mengubah buku entitas dan tidak menyamakan membership user dengan keanggotaan grup ([CONSOLIDATION.md](CONSOLIDATION.md)).

## Larangan dan deadlock

- Dilarang: siklus import/interface; A memanggil B yang memanggil balik A; handler fakta yang memicu rantai event tanpa batas; modul baca yang menulis balik.
- Hindari modul `Common`/`Shared` berisi aturan bisnis; kontrak bersama hanya tipe teknis kecil yang stabil (mis. `Money`, ID).
- Graf tanpa siklus tidak menjamin bebas deadlock database. Gunakan transaksi singkat, urutan penguncian konsisten (mis. baris saldo diurutkan menurut `account_id`), constraint dan idempotensi, serta retry terbatas.

## Perubahan batas

Sebelum menambah dependensi, pastikan kebutuhan tidak bisa dilayani interface yang ada. Jika perubahan menimbulkan siklus, pindahkan aturan ke pemilik yang tepat atau pakai fakta domain. Perbarui tabel dan graf di dokumen ini, dan catat ADR bila batas modul berubah.
