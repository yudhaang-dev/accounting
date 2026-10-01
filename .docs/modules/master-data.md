# Master Data — Perusahaan, Dimensi, Mata Uang & Kurs

> Modul `MasterData` (prefix `MD`). Hulu bagi semua modul; tidak bergantung pada modul bisnis lain ([DOMAIN-DEPENDENCIES.md](../DOMAIN-DEPENDENCIES.md)).

## Tujuan & batas

Menyediakan identitas perusahaan dan data referensi yang dipakai semua modul: dimensi analisis, mata uang, dan kurs. Modul ini tidak menyimpan transaksi, akun, periode, atau kebijakan modul lain. Kewajiban dimensi per akun milik `GeneralLedger`; kewajiban dimensi per Jenis Transaksi milik `TransactionEntry`.

## Data milik modul

| Data | Isi utama |
| --- | --- |
| `Company` | Nama, identitas legal opsional (mis. NPWP), `functional_currency`, `timezone`, `default_locale`, status `setup`/`active`/`inactive` |
| `DimensionType` | `key` stabil, label, hierarkis atau tidak, aturan hanya-daun, status |
| `DimensionValue` | Kode unik per tipe, nama, induk (bila hierarkis), masa berlaku, status |
| `DimensionSet` | Kombinasi unik nilai dimensi per perusahaan; immutable |
| `Currency` | Referensi ISO 4217 (kode, minor unit) |
| `CompanyCurrency` | Mata uang yang diaktifkan perusahaan, skala, mode pembulatan |
| `RateType` | Jenis kurs per perusahaan (mis. kurs tengah BI, kurs pajak) |
| `ExchangeRate` | Kurs per mata uang, jenis kurs, dan tanggal efektif; sumber dan aktor |

## Interface publik

- `CompanyDirectory` — profil, mata uang fungsional, zona waktu, dan status perusahaan.
- `DimensionSets` — validasi dan resolusi kombinasi nilai menjadi `dimension_set_id`; isi set; turunan/leluhur nilai hierarkis.
- `Currencies` — skala dan mode pembulatan per mata uang perusahaan.
- `ExchangeRates` — kurs untuk mata uang, jenis kurs, dan tanggal tertentu.

## Kebutuhan

- **MD-FR-01 Profil perusahaan.** Kelola nama, identitas legal opsional, mata uang fungsional, zona waktu, dan locale default. Mata uang fungsional hanya dapat diubah selama status `setup`. Aktivasi ke `active` dilakukan use case aplikasi setelah prasyarat modul lain terpenuhi (mis. kalender fiskal dan COA); `inactive` membuat data hanya-baca.
- **MD-FR-02 Tipe dimensi.** Perusahaan dapat menambah tipe dimensi (mis. cabang, lini produk) tanpa perubahan skema. `department` (hierarkis) dan `project` hanya seed template yang dapat diubah; kode tidak boleh bercabang berdasarkan key tipe dimensi.
- **MD-FR-03 Nilai dimensi.** Kode unik per tipe per perusahaan, nama, induk tanpa siklus, dan masa berlaku opsional. Nilai yang pernah dipakai hanya dapat dinonaktifkan, tidak dihapus. Perubahan nama tidak mengubah histori karena relasi memakai ID.
- **MD-FR-04 Dimension set.** Resolusi kombinasi nilai bersifat deterministik: kombinasi yang sama selalu menghasilkan `dimension_set_id` yang sama, termasuk kombinasi kosong. Satu tipe paling banyak satu nilai. Validasi: perusahaan sama, nilai aktif dan berlaku pada tanggal transaksi, serta aturan hanya-daun bila diaktifkan untuk tipe tersebut. Set yang sudah ada tidak pernah berubah.
- **MD-FR-05 Hierarki.** Sediakan turunan dan leluhur nilai untuk roll-up laporan dan cakupan akses. Roll-up memakai hierarki saat ini; perubahan hierarki diaudit (X-FR-01), dan snapshot laporan terbit tidak berubah (X-FR-03).
- **MD-FR-06 Mata uang.** Perusahaan mengaktifkan mata uang dari referensi ISO 4217. Skala dan mode pembulatan per mata uang adalah konfigurasi perusahaan, dengan minor unit ISO sebagai saran. Mata uang fungsional selalu aktif.
- **MD-FR-07 Jenis kurs dan kurs.** Jenis kurs adalah data perusahaan. Kurs dinyatakan sebagai nilai mata uang fungsional untuk 1 unit mata uang asing, disimpan dengan presisi desimal tinggi, dan dapat diinput manual, diimpor dari CSV, atau dikirim lewat API dengan sumber dan aktor tercatat. Koreksi kurs membuat versi baru; jurnal menyimpan kurs yang dipakai, sehingga koreksi tidak mengubah data terposting.
- **MD-FR-08 Pencarian kurs.** Kurs untuk suatu tanggal adalah kurs terbaru dengan tanggal efektif ≤ tanggal tersebut pada jenis kurs yang diminta. Batas umur kurs opsional diatur kebijakan perusahaan. Bila tidak ada kurs yang memenuhi, kembalikan error eksplisit; jangan memakai nilai 1, jenis kurs lain, atau kurs silang.

## Kriteria penerimaan

- **MD-AC-01** Kombinasi nilai yang sama dalam urutan input berbeda menghasilkan `dimension_set_id` yang sama; dua nilai untuk satu tipe ditolak.
- **MD-AC-02** Nilai nonaktif atau di luar masa berlaku ditolak untuk pemakaian baru dengan alasan yang menyebut dimensinya; set historis yang memuat nilai itu tetap terbaca.
- **MD-AC-03** Menambah tipe dimensi baru (mis. `branch`) tidak memerlukan migrasi skema dan langsung dapat dipakai di entri, rencana, dan laporan.
- **MD-AC-04** Nilai dimensi yang pernah dipakai tidak dapat dihapus; induk yang membentuk siklus ditolak.
- **MD-AC-05** Mata uang fungsional tidak dapat diubah setelah perusahaan berstatus `active`.
- **MD-AC-06** Permintaan kurs tanpa data yang memenuhi menghasilkan error eksplisit; koreksi kurs tidak mengubah jurnal yang sudah diposting.
