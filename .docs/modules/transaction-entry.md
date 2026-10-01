# Transaction Entry — Jenis Transaksi, Entri, Approval, Event

> Modul `TransactionEntry` (prefix `TE`). Bergantung pada `Subledger`, `GeneralLedger`, dan `MasterData`. Kontrak HTTP event ada di [API-CONTRACT.md](../API-CONTRACT.md).

## Tujuan & batas

Satu pintu untuk semua input yang menjadi jurnal: entri manual dari SPA, Peristiwa Keuangan dari sistem pihak ketiga, dan jurnal berulang. Modul ini memiliki definisi Jenis Transaksi, siklus entri, approval, dan idempotensi. Ia tidak menghitung nilai operasional (P-02) dan tidak menulis buku besar langsung: entri yang disetujui dirutekan ke `Subledger` atau `GeneralLedger`.

## Data milik modul

| Data | Isi utama |
| --- | --- |
| `TransactionType` | `key` stabil per perusahaan, label, status |
| `TransactionTypeVersion` | Nomor versi, mode, skema field, `PostingRule`, rute subledger, dimensi wajib, mata uang dan jenis kurs, seri penomoran; status `draft`/`published`/`retired` |
| `Entry` | Key dan versi Jenis Transaksi, nomor, status, `accounting_date`, tanggal dokumen, deskripsi, mata uang dan kurs, nilai field atau baris, dimensi, referensi, sumber (`manual`/`integration`/`recurring`), jejak approval, ID jurnal |
| `ApprovalPolicy` | Aturan approval/SoD berversi per perusahaan |
| `RecurringSchedule` | Templat nilai, frekuensi, rentang, jadwal berikutnya |
| `IdempotencyRecord` | Perusahaan, `source_system`, `source_transaction_id`, hash payload kanonis, ID entri |

Key berawalan `system.` didefinisikan kode: `system.opening_balance`, `system.history_import`, `system.closing`, `system.reversal`, `system.fx_revaluation`, `system.fx_realized`. Selebihnya data perusahaan.

## Interface publik

- `TransactionTypeCatalog` — daftar key dan versi, untuk validasi konfigurasi modul lain lewat lapisan aplikasi.
- `EntryReadiness` — entri yang belum selesai per periode, untuk kesiapan tutup buku.

## Kebutuhan

- **TE-FR-01 Jenis Transaksi.** Perusahaan mendefinisikan Jenis Transaksi dengan `key` stabil dan label tampilan. Template boleh menyarankan jenis umum (mis. Jurnal Umum, Penjualan Kredit, Ringkasan Payroll); saran wajib dikonfirmasi sebelum dipakai.
- **TE-FR-02 Versi paket.** Versi terbit adalah paket immutable: mode, skema field, validasi, aturan posting, rute subledger, dimensi wajib, mata uang yang diizinkan, jenis kurs, dan seri penomoran. Perubahan menerbitkan versi baru; versi lama tetap dapat dipakai sampai dihentikan (`retired`). Penghentian mencegah entri baru tanpa mengubah entri historis.
- **TE-FR-03 Mode.** `structured`: field bernama dipetakan ke jurnal oleh aturan posting. `free_lines`: user mengisi baris debit/kredit (akun, nilai, mata uang, dimensi, deskripsi per baris) yang dapat dibatasi ke kumpulan akun tertentu; dipakai untuk jurnal umum dan penyesuaian. Tidak ada skrip atau formula buatan user.
- **TE-FR-04 Skema field.** Tipe field: `amount`, `date`, `text`, `reference`, `account`, `party`, `cash_account`, `dimension`, `allocations`. Skema dipublikasikan sebagai subset JSON Schema agar SPA dan klien integrasi dapat membangun form dan memvalidasi sendiri. Field `amount` berupa string desimal non-negatif dengan skala ≤ skala mata uang.
- **TE-FR-05 Aturan posting.** Setiap baris aturan menetapkan sisi debit/kredit, field nominal, sumber akun (ID akun tetap, field `account` dengan kumpulan akun yang diizinkan, akun kontrol subledger, atau akun rekening kas/bank), dan apakah dimensi entri diterapkan. Satu field dipakai di beberapa baris hanya bila dinyatakan eksplisit. Baris bernilai nol atau kosong dilewati. Transaksi lintas mata uang dapat memakai baris `fx_difference` yang menampung selisih nilai fungsional ke peran selisih kurs GL. Hasil harus seimbang dalam mata uang fungsional.
- **TE-FR-06 Mata uang entri.** Entri `structured` memakai satu mata uang, kecuali baris berbasis `cash_account` yang mengikuti mata uang rekening; entri `free_lines` boleh berbeda mata uang per baris. Kurs diambil dari input bila kebijakan mengizinkan, selain itu dari tabel kurs untuk jenis kurs versi atau default perusahaan (MD-FR-08). Kurs yang dipakai disimpan pada entri.
- **TE-FR-07 Siklus entri.** Status `draft` → `submitted` → `approved` → `posted`, dengan `rejected` dan `cancelled` sebagai akhir alternatif. Hanya `draft` yang dapat diubah. Penolakan wajib beralasan. Entri manual yang ditolak dapat direvisi kembali ke `draft`; event yang ditolak bersifat final dan koreksinya dikirim sebagai event baru. Draf yang tidak dipakai dibatalkan, tidak dihapus.
- **TE-FR-08 Validasi.** Saat submit dan diulang secara atomik saat posting: versi aktif dan diizinkan, semua ID dalam perusahaan yang sama, skema field, periode dan aturan akun (GL), dimensi (MD), mata uang dan kurs (MD), rincian subledger (SL), dan keseimbangan. Kegagalan mengembalikan error per field tanpa efek parsial.
- **TE-FR-09 Approval dan SoD.** Kebijakan berversi memilih aturan menurut key Jenis Transaksi, sumber entri, dan ambang nominal dalam mata uang fungsional. Hasil aturan: disetujui otomatis, atau satu/lebih tahap approval yang masing-masing mensyaratkan permission. Bila SoD aktif, pembuat tidak dapat menyetujui entrinya sendiri. Kebijakan juga menentukan apakah entri langsung diposting setelah disetujui. Klien integrasi tidak pernah menyetujui. Setiap keputusan mencatat aktor, waktu, dan alasan.
- **TE-FR-10 Posting.** Entri yang disetujui dirutekan ke `Subledger` bila versinya memiliki rute subledger, selain itu ke `GeneralLedger`. Posting atomik dan idempoten: perintah posting berulang untuk entri yang sama mengembalikan jurnal yang sama. Entri menyimpan ID, nomor, dan waktu posting jurnal.
- **TE-FR-11 Pembalikan dan penggantian.** Pembalikan adalah entri `system.reversal` yang merujuk jurnal posted, dengan tanggal pembalikan (input; periodenya harus menerima posting) dan alasan; selalu membalik penuh dan tunduk pada approval. Entri pengganti dapat ditautkan ke entri yang dibalik. Entri dapat memiliki `auto_reverse_on`; pembalikan terjadwal dijalankan sistem dengan approval entri asal. Bila periodenya tidak lagi menerima posting, pembalikan terjadwal gagal secara terlihat dan dilaporkan.
- **TE-FR-12 Peristiwa Keuangan.** Event dari API membuat entri bersumber `integration` dengan versi Jenis Transaksi yang terbit, belum dihentikan, dan ada di allowlist klien. `source_system` berasal dari principal. Nilai sudah dihitung sistem sumber (P-02). Event mengikuti kebijakan approval yang sama dengan entri manual.
- **TE-FR-13 Idempotensi.** Kunci: `company_id` + `source_system` + `source_transaction_id`. Pembandingan memakai payload bisnis kanonis (urutan key dan format desimal tidak berpengaruh). Kiriman identik mengembalikan hasil asli; isi berbeda ditolak sebagai `SOURCE_TRANSACTION_CONFLICT`; payload tidak valid tidak membuat catatan apa pun. Kiriman bersamaan dengan kunci sama hanya menghasilkan satu entri.
- **TE-FR-14 Jurnal berulang.** Jadwal menyimpan templat nilai tetap (tanpa formula), frekuensi, rentang, dan status awal entri (`draft` atau `submitted`). Jadwal berjalan menurut zona waktu perusahaan; entri yang dihasilkan melewati validasi dan approval normal; kegagalan terlihat, tidak dilewati diam-diam.
- **TE-FR-15 Changes feed.** Sediakan daftar perubahan status entri/event berbasis cursor monoton, sehingga polling tidak melewatkan atau menggandakan perubahan. Klien integrasi hanya melihat event miliknya. Webhook bertanda tangan menyusul di `F2`.
- **TE-FR-16 Penomoran entri.** Nomor entri berasal dari seri berpola per Jenis Transaksi (prefix, token tanggal, reset, padding) dan diberikan pada titik yang ditetapkan kebijakan (saat dibuat atau saat submit). Nomor jurnal milik `GeneralLedger`.
- **TE-FR-17 Batch event (`F2`).** Endpoint batch menerima banyak event dengan hasil per item; setiap item tetap idempoten dan atomik secara mandiri.

## Kriteria penerimaan

- **TE-AC-01** SPA membaca skema Jenis Transaksi aktif dan memakainya untuk input; klien integrasi mengirim key/versi yang sama. Perubahan skema menghasilkan versi baru tanpa mengubah entri lama.
- **TE-AC-02** Event dengan nilai yang dipasok dan aturan posting valid menghasilkan jurnal lewat `GeneralLedger` atau `Subledger`; sistem tidak menghitung HPP atau nilai operasional lain.
- **TE-AC-03** Entri dengan versi atau akun tidak valid, dimensi wajib hilang, periode yang tidak menerima posting, kurs tidak tersedia, atau tidak seimbang ditolak tanpa jurnal parsial; error menyebut field dan kodenya.
- **TE-AC-04** Kiriman ulang event dengan kunci dan isi sama tidak menggandakan entri maupun jurnal; kunci sama dengan isi berbeda menghasilkan `SOURCE_TRANSACTION_CONFLICT` tanpa mengubah hasil awal.
- **TE-AC-05** Dengan kebijakan "di atas ambang X perlu dua approval, SoD aktif": entri di atas X tidak terposting sebelum dua approver berbeda menyetujui, dan pembuatnya tidak dapat menjadi salah satu approver.
- **TE-AC-06** Versi yang memakai satu field di dua baris tanpa pernyataan eksplisit tidak dapat diterbitkan; versi `retired` menolak entri baru, sementara entri lama tetap terbaca dengan versinya.
- **TE-AC-07** Pembalikan jurnal yang berasal dari subledger ikut membalik efek open item; pembalikan bertanggal di periode yang tidak menerima posting ditolak.
- **TE-AC-08** Polling changes feed dengan cursor mengembalikan setiap perubahan status tepat sekali dan berurutan; klien hanya melihat event miliknya.
- **TE-AC-09** Pembalikan terjadwal yang tanggalnya jatuh di periode `closed` gagal dan muncul sebagai pengecualian, tidak dilewati.
- **TE-AC-10** Entri transfer dari rekening USD ke rekening IDR dengan baris `fx_difference` terposting seimbang dalam mata uang fungsional, dan selisihnya masuk ke akun peran selisih kurs.
