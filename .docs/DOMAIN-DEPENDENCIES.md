# Domain Dependencies

> **Jenis dokumen:** arsitektur domain / aturan mini DDD untuk coding agent. Berlaku bersama [PRODUCT.md](PRODUCT.md), [CHART-OF-ACCOUNTS.md](CHART-OF-ACCOUNTS.md), [CONSOLIDATION.md](CONSOLIDATION.md), dan kontrak integrasi di [API-CONTRACT.md](API-CONTRACT.md). Arah panah di dokumen ini berarti **modul di kiri boleh bergantung pada interface modul di kanan**; panah bukan urutan aliran data.

## Prinsip

1. Bangun sebagai satu aplikasi modular. Nama domain tidak menyiratkan service, database, atau deployment terpisah.
2. Setiap data dan aturan bisnis memiliki satu pemilik. Modul lain menggunakan interface pemilik; jangan menulis langsung ke tabel atau model milik modul lain.
3. Dependensi harus membentuk graf berarah tanpa siklus. Modul tidak boleh mengimpor atau memanggil balik modul yang bergantung padanya.
4. Gunakan interface kecil dengan input/output eksplisit. Rincian validasi, transaksi, dan aturan domain tetap berada pada modul pemilik.
5. Identitas lintas domain berupa ID stabil atau DTO kontrak; jangan membagikan model ORM, tabel, atau objek internal.

## Modul dan kepemilikan

| Modul | Pemilik | Boleh bergantung pada |
|---|---|---|
| **Organisasi & Dimensi** | Identitas workspace dan `company_id` (satu perusahaan pembukuan per workspace pada rilis awal), hierarki departemen/divisi, dan proyek | Kontrak teknis bersama yang kecil; tidak bergantung pada domain bisnis lain |
| **General Ledger** | Bagan akun, periode buku, jurnal, posting/pembalikan, saldo konversi, penutupan periode/tahun, pemetaan peran `retained_earnings`, jurnal penutup, dan saldo akun | Interface baca Organisasi & Dimensi |
| **Subledger** | Rincian piutang, utang, serta transaksi kas/bank, pemetaan akun kontrol piutang/utang, dan rekonsiliasi ke akun kontrol | Interface validasi/posting General Ledger dan interface baca Organisasi & Dimensi; rekonsiliasi rekening koran bukan cakupan awal |
| **Transaction Intake & Posting** | Jenis Transaksi Keuangan per perusahaan, skema field berversi, Aturan Posting field-ke-baris jurnal, validasi Peristiwa Keuangan, referensi sumber, dan idempotensi penerimaan | Interface validasi akun, periode, dan posting General Ledger; interface Subledger untuk transaksi yang memerlukan rincian piutang/utang/kas; interface baca Organisasi & Dimensi |
| **Budget & Forecast** | Versi proyeksi, baris rencana, status, dan persetujuannya | Interface baca akun General Ledger dan interface baca Organisasi & Dimensi |
| **Reporting & Analysis** | Query, agregasi, pemetaan baris laporan, akun kas/setara kas, klasifikasi arus kas, perhitungan laba tahun berjalan, laporan keuangan, dan proyeksi vs realisasi | Interface baca General Ledger, Budget & Forecast, dan Organisasi & Dimensi |
| **Consolidation & Group Reporting (Post-MVP)** | Grup dan cakupan entitas konsolidasi, pemetaan akun grup, translasi mata uang, eliminasi/penyesuaian, snapshot dan laporan grup | Interface baca Reporting & Analysis dan Organisasi & Dimensi |

Identity memiliki user, membership ke banyak workspace, role per workspace, dan principal mesin klien integrasi. Identity juga menyimpan grant akses user ke grup konsolidasi dengan `group_id`; Consolidation memiliki relasi grup-ke-entitas. Principal mesin dibatasi ke perusahaan, scope, dan allowlist Jenis Transaksi sesuai [API-CONTRACT.md](API-CONTRACT.md); `source_system` ditetapkan pada principal dan bukan bukti otorisasi. Otorisasi, audit append-only, ekspor, dan penyimpanan metadata lampiran adalah kemampuan lintas modul. Letakkan pemeriksaan akses pada lapisan aplikasi/permintaan; jangan membuat domain bisnis saling memanggil untuk menentukan hak user. Audit menerima catatan generik dan tidak memanggil kembali domain pengirim. Membership workspace tidak membentuk grup konsolidasi.

## Graf dependensi yang diizinkan

```text
Reporting & Analysis ──► General Ledger ──► Organisasi & Dimensi
          ├───────────► Budget & Forecast ──► General Ledger
          └────────────────────────────────► Organisasi & Dimensi

Subledger ──► General Ledger
    └───────► Organisasi & Dimensi

Transaction Intake & Posting ──► General Ledger
              ├───────────────► Subledger
              └───────────────► Organisasi & Dimensi
```

Organisasi & Dimensi adalah modul hulu. Tidak ada panah balik dari Organisasi ke modul bisnis. General Ledger tidak mengetahui Subledger, Transaction Intake & Posting, Budget & Forecast, atau Reporting. Semua referensi yang melintasi modul menggunakan ID/interface yang dipublikasikan pemilik.

Tambahan graf Post-MVP: `Consolidation & Group Reporting -> Reporting & Analysis` dan `Consolidation & Group Reporting -> Organisasi & Dimensi`. Domain sumber tidak bergantung kembali pada domain konsolidasi.

## Aturan alur transaksi

- **Posting dari Subledger:** Subledger mengirim perintah posting ke interface General Ledger dengan tanggal pembukuan, baris debit/kredit, ID dimensi, dan referensi sumber. General Ledger memvalidasi dan memutuskan hasil posting, lalu mengembalikan ID jurnal/status. Simpan referensi sumber secara opak (`source_type`, `source_id`); General Ledger tidak memuat model Subledger.
- **Penerimaan peristiwa keuangan:** Transaction Intake & Posting memiliki definisi Jenis Transaksi, skema field, Aturan Posting, dan referensi/idempotensi sumber. Ia menerima nilai nominal yang sudah dihitung, memvalidasi schema/versi/scope/dimensi, lalu membentuk perintah posting melalui interface General Ledger; ia tidak menulis jurnal atau saldo secara langsung. General Ledger tidak memanggil kembali Transaction Intake & Posting.
- **Rute ke Subledger:** jika sebuah transaksi memerlukan rincian piutang, utang, atau transaksi kas/bank yang dimiliki Subledger, Transaction Intake & Posting meneruskan kontrak ke interface Subledger. Subledger membentuk rincian dan mengirim posting ke General Ledger. Transaksi intake tidak memposting lagi nilai kontrol yang sama.
- **Aturan posting transaksi:** Transaction Intake & Posting menyimpan pemetaan field nominal -> satu atau lebih baris ID akun perusahaan/sisi debit-kredit pada versi Jenis Transaksi. Nilai digunakan apa adanya; penggunaan ulang satu field pada beberapa baris harus dinyatakan eksplisit. General Ledger memvalidasi akun/periode dan menjaga jurnal seimbang. Ini berbeda dari pemetaan peran akun sistem: target `retained_earnings`, akun kontrol Subledger, dan klasifikasi laporan tetap dimiliki domain masing-masing.
- **Batas integrasi pihak ketiga:** sistem sumber mengubah payload vendor menjadi kontrak Peristiwa Keuangan yang kanonis dan memanggil API. MVP tidak memiliki konektor vendor atau workflow operasional sumber. Identitas klien mesin dibatasi pada perusahaan/jenis transaksi; `source_system` harus cocok dengan identitas sumber yang diizinkan bagi principal tersebut. `source_system` dan `source_transaction_id` menjadi referensi serta kunci idempotensi, bukan bukti otorisasi.
- **Fakta jurnal:** setelah commit, General Ledger dapat menerbitkan fakta seperti `JournalPosted` atau `JournalReversed`. Reporting boleh mengonsumsinya untuk memperbarui hasil baca. General Ledger tidak memanggil Reporting.
- **Saldo konversi:** General Ledger memvalidasi dan memegang posting batch saldo cutover yang seimbang. Subledger tetap memiliki rincian open item piutang/utang, mencocokkan jumlahnya ke total kontrol, lalu mengirim total melalui interface posting yang sama. Posting kontrol hanya terjadi sekali. General Ledger tidak membaca model Subledger.
- **Penutupan buku:** General Ledger memiliki status periode, jurnal penutup tahunan, saldo penutupan/pembukaan, serta pembukaan kembali yang diaudit. Reporting membaca hasil dan status; ia tidak memerintahkan penutupan atau menulis jurnal.
- **Readiness penutupan:** use case aplikasi mengumpulkan hasil rekonsiliasi Subledger dan status pemeriksaan sebelum mengirim command tutup ke General Ledger. General Ledger memvalidasi invarian buku besarnya sendiri; ia tidak memanggil Subledger atau Reporting.
- **Fakta penutupan:** setelah commit, General Ledger dapat menerbitkan fakta `OpeningBalancesPosted`, `PeriodClosed`, `PeriodReopened`, atau `FiscalYearClosed`. Consumer idempoten; jangan membuat pemanggilan balik ke General Ledger.
- **Pemetaan peran akun:** katalog key bersama hanya metadata kontrak; ia tidak menyimpan mapping. Tiap domain mendefinisikan key stabil untuk peran yang dimilikinya dan menyimpan target ID akun per perusahaan. General Ledger memiliki target saldo laba untuk jurnal penutup, Subledger memiliki akun kontrolnya, dan Reporting & Analysis memiliki pemetaan penyajian/arus kas. Pemilik memvalidasi ID terhadap interface General Ledger; General Ledger tidak membaca konfigurasi internal domain lain dan tidak ada domain pusat pemetaan.
- **Versi transaksi:** setiap Peristiwa Keuangan menyimpan key/versi paket Jenis Transaksi (skema, validasi, Aturan Posting) yang dipakai. Perubahan konfigurasi berlaku untuk kiriman baru; jurnal historis dan hasil yang telah diposting tetap dapat ditelusuri. Versi pemetaan peran akun milik General Ledger/Subledger/Reporting tetap dikelola terpisah oleh pemiliknya.
- **Perhitungan otomatis:** domain pemilik menghitung dari ID akun terpetakan dan jurnal/saldo ledger. Kode akun template boleh menjadi saran setup; kode tersebut tidak menjadi syarat algoritme atau branching bisnis.
- **Konsolidasi grup (Post-MVP):** Consolidation & Group Reporting membaca paket laporan entitas dari Reporting & Analysis dan ID entitas dari Organisasi & Dimensi. Snapshot menjadi milik domain konsolidasi; domain ini tidak memposting atau mengubah jurnal General Ledger entitas.
- **Proyeksi:** Budget & Forecast menyimpan rencana; tidak mengirim rencana sebagai jurnal. Reporting menghitung aktual dari jurnal posted dan membandingkannya dengan versi rencana yang dipilih.
- **Referensi dimensi:** General Ledger dan Budget & Forecast menyimpan ID departemen/proyek. Keduanya memvalidasi melalui interface baca Organisasi & Dimensi; perubahan nama atau status dimensi tidak mengubah histori transaksi.
- **Pihak antar-entitas (Post-MVP):** bila transaksi intragrup didukung, sumber memberi `counterparty_entity_id`; General Ledger menyimpannya sebagai ID referensi tanpa bergantung pada Consolidation. Reporting memasukkannya ke paket baca untuk konsolidasi. Tanpa ID pihak lawan, transaksi tidak boleh dieliminasi otomatis.
- Perintah lintas modul harus memiliki hasil dan kegagalan yang jelas, serta idempotensi untuk retry. Jangan gunakan callback sinkron yang kembali ke modul pemanggil. Jika proses kelak menjadi asinkron, gunakan outbox/fakta domain dan consumer idempoten.

## Invarian pemilik

- Hanya **General Ledger** yang dapat membuat jurnal posted, membalik jurnal, mengunci/membuka periode, mengesahkan saldo konversi, menutup tahun buku, dan menjaga keseimbangan debit-kredit.
- **Transaction Intake & Posting** tidak pernah mengubah buku besar langsung; ia hanya meminta posting melalui interface General Ledger atau Subledger. Klien integrasi tidak mendapat jalur khusus untuk melewati otorisasi, validasi, periode, atau approval jurnal.
- Pemilik konfigurasi mengelola versinya sendiri: General Ledger untuk target penutupan, Subledger untuk akun kontrol, dan Reporting & Analysis untuk baris laporan, akun kas/setara kas, serta klasifikasi arus kas.
- **Subledger** memiliki rincian dokumen sumber dan rekonsiliasi akun kontrol; ia tidak mengubah saldo General Ledger secara langsung.
- **Budget & Forecast** memiliki versi proyeksi dan aturan penerbitannya; perubahan versi terbit menghasilkan versi baru.
- **Organisasi & Dimensi** memiliki ID dan hierarki. Referensi yang sudah dipakai transaksi dinonaktifkan, bukan dihapus.
- **Reporting & Analysis** bersifat baca-saja terhadap domain transaksi dan rencana. Koreksi dilakukan pada pemilik data, kemudian laporan dihitung ulang.
- **Consolidation & Group Reporting (Post-MVP)** tidak menyamakan keanggotaan user dengan hubungan grup dan tidak mengubah buku entitas. Detail aturan ada di [CONSOLIDATION.md](CONSOLIDATION.md).

## Larangan dan pembedaan deadlock

- Dilarang: siklus interface/import; domain A memanggil B yang memanggil balik A; handler fakta yang memicu rantai event tanpa batas; domain laporan menulis balik jurnal atau rencana.
- Hindari modul `Common`/`Shared` yang menampung aturan bisnis semua domain. Kontrak bersama hanya berisi tipe sederhana yang stabil, bukan model domain.
- Graf tanpa siklus mencegah siklus dependensi modul dan membantu menghindari rekursi/event loop. **Graf ini sendiri tidak menjamin bebas deadlock database**: deadlock database berasal dari transaksi bersamaan yang mengambil kunci dalam urutan bertentangan. Tangani terpisah dengan transaksi singkat, urutan penguncian konsisten, constraint/idempotensi, dan retry terbatas.

## Perubahan batas

Sebelum menambah dependensi, pastikan kebutuhan tidak dapat dilayani interface baca/perintah yang sudah ada. Jika perubahan membuat siklus, pindahkan aturan ke pemilik yang tepat atau perkenalkan kontrak baca/fakta yang tidak memanggil balik. Perbarui graf dan tabel pemilik pada dokumen ini saat batas domain berubah.
