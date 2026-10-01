# PRODUCT — Scope, Prinsip, Glosarium

> Wajib dibaca untuk setiap tugas. Kebutuhan rinci ada di dokumen modul ([README](README.md)). Status: draft kebutuhan; bukan pernyataan kepatuhan atau nasihat akuntansi.

## 1. Tujuan

Sistem pembukuan *headless* untuk satu perusahaan per workspace. Sistem menerima nilai keuangan dari input manual di SPA first-party dan dari sistem pihak ketiga melalui API yang sama, membukukannya lewat Jenis Transaksi yang dikonfigurasi perusahaan, lalu menghasilkan laporan keuangan serta proyeksi vs realisasi yang dapat ditelusuri sampai sumbernya dan dipotong menurut dimensi.

## 2. Scope

### MVP

- **Data induk:** profil perusahaan, mata uang fungsional, tipe/nilai dimensi dan dimension set, mata uang dan kurs.
- **Identitas & akses:** user anggota banyak workspace, role dan permission sebagai data, cakupan akses per dimensi, klien integrasi.
- **Pencatatan transaksi:** Jenis Transaksi (terstruktur dan baris bebas), aturan posting, entri dengan approval, event API dengan idempotensi, jurnal berulang, changes feed.
- **Subledger:** pihak, open item piutang/utang, transaksi kas/bank, alokasi pelunasan, selisih kurs terealisasi, aging, rekonsiliasi akun kontrol.
- **Buku besar:** COA, kalender fiskal dan periode, jurnal posted, transaksi valas dan revaluasi, cutover perusahaan berjalan, tutup periode dan tahun buku.
- **Anggaran & proyeksi:** versi rencana per periode, akun, dan dimensi.
- **Pelaporan:** engine definisi laporan; neraca saldo, buku besar, laba rugi termasuk OCI, neraca, arus kas langsung/tidak langsung, perubahan ekuitas, proyeksi vs realisasi; ekspor CSV.
- **Batas:** basis akrual; satu mata uang fungsional per perusahaan (transaksi valas didukung); satu perusahaan per workspace.

### Fase 2 (`F2`)

Rekonsiliasi bank lewat impor mutasi rekening; webhook bertanda tangan; endpoint batch event.

### Post-MVP (`Post`)

Konsolidasi dan pelaporan grup, termasuk translasi ke mata uang penyajian ([CONSOLIDATION.md](CONSOLIDATION.md)).

### Di luar produk

Proses dan perhitungan operasional milik sistem sumber — persediaan/HPP, payroll, procurement, penjualan, perhitungan pajak dan e-Faktur, register aset tetap — serta konektor vendor dan integrasi API bank. Juga di luar: lebih dari satu mata uang fungsional per perusahaan, hedge accounting, dan klaim kepatuhan SAK otomatis; format dan pemetaan ditetapkan serta ditinjau akuntan perusahaan.

## 3. Prinsip

- **P-01 Tanpa hardcode.** Nilai dan kebijakan yang dapat berbeda antarperusahaan adalah data per perusahaan yang berversi: kode/nama akun, penyajian laporan, klasifikasi mutasi, kalender fiskal, akun target peran, approval/SoD, penomoran, skala dan pembulatan mata uang, jenis kurs, tipe dan kewajiban dimensi, retensi, kewajiban 2FA. Template hanya saran yang wajib dikonfirmasi. Yang boleh ada di kode: invarian akuntansi, mekanisme, key peran akun, status sistem, dan key Jenis Transaksi sistem. Otorisasi memeriksa permission, bukan nama role.
- **P-02 Nilai operasional vs pengukuran akuntansi.** Sistem tidak menghitung nilai operasional (HPP dari kuantitas, payroll, pajak, penyusutan dari register aset); nilai itu dipasok sistem sumber atau input user. Sistem wajib melakukan pengukuran akuntansi atas data buku besar: selisih kurs terealisasi dan revaluasi, jurnal penutup, pembalikan, dan laba tahun berjalan.
- **P-03 Imutabilitas.** Jurnal posted tidak pernah diubah atau dihapus; koreksi memakai pembalikan dan entri pengganti yang tertaut. Audit bersifat append-only.
- **P-04 Versi.** Perubahan konfigurasi membuat versi baru dengan tanggal efektif dan aktor. Hasil tersimpan mencatat versi yang dipakai; versi baru tidak mengubah hasil lama.
- **P-05 Isolasi perusahaan.** Semua data keuangan terscope ke `company_id`, dan setiap relasi divalidasi berada di perusahaan yang sama. Membership satu workspace tidak memberi akses ke workspace lain.
- **P-06 Satu API untuk semua klien.** SPA first-party dan sistem pihak ketiga memakai API berversi dan aturan otorisasi yang sama. Menyembunyikan kontrol di UI bukan otorisasi.
- **P-07 Dapat ditelusuri.** Setiap angka laporan dapat ditelusuri ke baris jurnal, entri, dan referensi sumbernya.
- **P-08 Uang.** Gunakan desimal, tidak pernah floating point; nilai di API berupa string desimal; skala mengikuti konfigurasi mata uang. Setiap jurnal seimbang dalam mata uang fungsional.
- **P-09 Bahasa.** Kode, skema DB, API, key, kode error, dan komentar berbahasa Inggris. Bahasa Indonesia hanya untuk teks tampilan (file terjemahan) dan dokumen. Istilah dokumen diikat ke identifier lewat glosarium.
- **P-10 Satu pemilik.** Setiap data dan aturan dimiliki satu modul; modul lain memakai interface pemiliknya ([DOMAIN-DEPENDENCIES.md](DOMAIN-DEPENDENCIES.md)).

## 4. Glosarium

| Istilah | Identifier | Arti |
| --- | --- | --- |
| Workspace | `Workspace` | Ruang akses user; tepat satu perusahaan pada MVP. |
| Perusahaan | `Company`, `company_id` | Entitas pembukuan; batas semua data keuangan. |
| Mata uang fungsional | `functional_currency` | Mata uang buku besar perusahaan. |
| Mata uang transaksi | `transaction_currency` | Mata uang dokumen atau baris; sama dengan mata uang fungsional bila bukan valas. |
| Kurs | `ExchangeRate`, `rate_type` | Kurs per tanggal untuk jenis kurs yang dikonfigurasi. |
| Akun | `Account` | Akun induk atau daun; hanya daun yang menerima posting. |
| Kelas akun | `AccountClass` | Elemen laporan: `asset`, `liability`, `equity`, `income`, `expense`. |
| Peran akun | `AccountRole` | Key stabil (mis. `retained_earnings`) yang dipetakan ke ID akun oleh modul pemiliknya. |
| Dimensi | `DimensionType`, `DimensionValue` | Tipe analisis per perusahaan; `department` dan `project` adalah seed. |
| Set dimensi | `DimensionSet`, `dimension_set_id` | Kombinasi unik nilai dimensi yang disimpan pada baris. |
| Pihak | `Party` | Pelanggan atau pemasok pada subledger. |
| Profil posting | `PostingProfile` | Penentu akun kontrol piutang/utang bagi pihak. |
| Rekening kas/bank | `CashAccount` | Kas atau rekening bank beserta mata uang dan akun GL-nya. |
| Jenis Transaksi | `TransactionType` | Definisi berversi (mode, skema field, validasi, aturan posting); klasifikasi semua jurnal. |
| Aturan Posting | `PostingRule` | Pemetaan field nominal ke akun dan sisi debit/kredit. |
| Entri | `Entry` | Dokumen sebelum posting; status `draft`, `submitted`, `approved`, `posted`, `rejected`, `cancelled`. |
| Peristiwa Keuangan | `FinancialEvent` | Entri dari klien integrasi, dengan referensi sumber. |
| Sistem sumber | `source_system` | Atribut klien integrasi; bagian dari kunci idempotensi. |
| Jurnal | `Journal`, `JournalLine` | Catatan buku besar yang sudah diposting; immutable. |
| Pembalikan | `Reversal` | Jurnal yang membalik jurnal posted dan tertaut ke asalnya. |
| Open item | `OpenItem` | Sisa saldo dokumen piutang atau utang. |
| Alokasi | `Allocation` | Pelunasan pembayaran ke open item. |
| Tanggal pembukuan | `accounting_date` | Penentu periode. |
| Kalender fiskal | `FiscalCalendar` | Aturan pembentukan tahun buku dan periode. |
| Periode | `Period` | Status `open`, `soft_closed`, `closed`. |
| Tahun buku | `FiscalYear` | Rangkaian periode; ditutup dengan jurnal penutup. |
| Tanggal cutover | `cutover_date` | Hari pertama pembukuan di sistem bagi perusahaan berjalan. |
| Awal histori | `ledger_start_date` | Awal histori yang dimigrasikan; sama dengan `cutover_date` bila tanpa histori. |
| Saldo konversi | `OpeningBalance` | Batch saldo per cutover, termasuk saldo YTD laba rugi. |
| Jurnal penutup | `ClosingJournal` | Jurnal sistem akhir tahun ke akun peran `retained_earnings`. |
| Revaluasi | `Revaluation` | Pengukuran ulang pos moneter valas dengan kurs penutup. |
| Selisih kurs terealisasi | `RealizedFxDifference` | Selisih kurs yang timbul saat pelunasan. |
| Laba tahun berjalan | `current_year_earnings` | Dihitung dari akun pendapatan/beban; tidak pernah diposting. |
| Definisi laporan | `ReportDefinition` | Baris dan kolom laporan sebagai data berversi. |
| Klasifikasi mutasi | `MovementClassification` | Pemetaan akun × Jenis Transaksi ke baris laporan (arus kas, perubahan ekuitas). |
| Proyeksi | `Plan`, `PlanVersion` | Rencana pendapatan/biaya; versi bernama (mis. anggaran, forecast) berstatus draf atau terbit. |
| Realisasi | `actual` | Nilai dari jurnal posted. |
| Kebijakan perusahaan | `CompanyPolicy` | Konfigurasi bertipe dan berversi milik modul yang menegakkannya. |
| Klien integrasi | `IntegrationClient` | Principal mesin OAuth; terscope perusahaan, `source_system`, dan allowlist Jenis Transaksi. |

## 5. Aktor

- **User** (SPA first-party): hak ditentukan permission per workspace. Role `accounting_admin`, `preparer`, `approver`, `viewer`, dan `auditor` hanya seed awal; perusahaan dapat mengubah atau menambah role ([identity-access.md](modules/identity-access.md)).
- **Klien integrasi** (sistem pihak ketiga): principal mesin yang mengirim event dan membaca hasil kirimannya sendiri. Ia tidak dapat menyetujui; entrinya mengikuti kebijakan approval perusahaan ([API-CONTRACT.md](API-CONTRACT.md)).

## 6. Kebutuhan lintas modul

- **X-FR-01 Audit.** Catat perubahan konfigurasi dan versinya, siklus entri (submit, approve, reject, post), pembalikan, penerimaan/penolakan event, tutup/buka periode, waiver, serta perubahan akses user dan klien integrasi. Simpan aktor (user atau klien integrasi), waktu, hasil, dan nilai sebelum/sesudah yang relevan. Log bersifat append-only.
- **X-FR-02 Ekspor.** Setiap laporan dan daftar dapat diekspor ke CSV dengan filter dan otorisasi yang sama dengan sumbernya.
- **X-FR-03 Hasil berversi.** Hasil tersimpan (jurnal penutup, snapshot laporan yang diterbitkan, snapshot konsolidasi) mencatat versi konfigurasi yang dipakai. Perhitungan ulang membuat hasil baru; akun yang belum dipetakan tampil sebagai pengecualian, tidak hilang.
- **X-FR-04 Lampiran.** Entri dan dokumen subledger dapat memiliki lampiran. Simpan metadata dan hash isi; lampiran tidak dapat diganti setelah entri diposting.
- **X-FR-05 Retensi.** Data keuangan, lampiran, dan audit disimpan selama masa retensi menurut kebijakan perusahaan (default dari konfigurasi; Indonesia 10 tahun [R10]). Tidak ada hard delete sebelum masa itu berakhir.

### Kriteria penerimaan

- **X-AC-01** User tanpa cakupan perusahaan/dimensi tidak dapat membaca, mengubah, menyetujui, memposting, atau mengekspor data di luar cakupannya; aksi kritis tercatat di audit.
- **X-AC-02** Dua perusahaan dengan kode COA berbeda memetakan peran yang sama ke akun masing-masing dan menghasilkan perhitungan yang benar; perubahan konfigurasi satu perusahaan tidak memengaruhi perusahaan lain.
- **X-AC-03** Fitur yang membutuhkan konfigurasi wajib (pemetaan peran, kebijakan) menolak aktivasi bila konfigurasi hilang atau tidak valid, dan menyebutkan apa yang perlu diperbaiki. Saran template tidak pernah dipakai tanpa konfirmasi.
- **X-AC-04** Mengubah konfigurasi tidak mengubah hasil tersimpan; perhitungan ulang menghasilkan versi hasil baru.
- **X-AC-05** Aplikasi tidak dapat mengubah atau menghapus catatan audit maupun jurnal posted.
