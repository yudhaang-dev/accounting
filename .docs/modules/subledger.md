# Subledger — Pihak, Open Item, Kas/Bank, Rekonsiliasi

> Modul `Subledger` (prefix `SL`). Bergantung pada `GeneralLedger` dan `MasterData`. Diisi oleh entri yang sudah disetujui dari `TransactionEntry`.

## Tujuan & batas

Menyimpan rincian di balik akun kontrol piutang, utang, dan kas/bank, lalu memastikan rincian itu cocok dengan buku besar. Modul ini bukan sistem penagihan, pembelian, atau CRM: nilai dokumen berasal dari entri, dan master pihak dibuat minimal. Integrasi API bank di luar produk; rekonsiliasi rekening koran lewat impor adalah `F2`.

## Data milik modul

| Data | Isi utama |
| --- | --- |
| `Party` | Nama, peran pelanggan/pemasok, referensi eksternal per `source_system`, identitas pajak opsional, profil posting, status; `counterparty_company_id` (`Post`) |
| `PostingProfile` | Nama dan akun kontrol piutang/utang; profil default dipetakan lewat peran `receivables_control` dan `payables_control` |
| `CashAccount` | Kas atau rekening bank: nama, jenis (`cash`/`bank`), bank dan nomor rekening, mata uang, akun GL, status |
| `OpenItem` | Pihak, jenis (piutang/utang), jenis dokumen, nomor dan tanggal dokumen, jatuh tempo, mata uang, nilai awal dan sisa (transaksi dan fungsional), kurs tercatat, status |
| `CashTransaction` | Rekening, arah, nilai, tanggal, referensi, status `cleared` |
| `Allocation` | Dokumen pelunas, open item yang dilunasi, nilai, tanggal, selisih kurs terealisasi |
| `ReconciliationRun` | Akun kontrol, tanggal, saldo GL, total rincian, selisih, status, waiver |
| `AgingPolicy` | Bucket umur dan dasar perhitungan (jatuh tempo atau tanggal dokumen) |

Jenis dokumen adalah mekanisme sistem: `invoice`, `credit_note`, `payment`, `advance`, `write_off`.

## Interface publik

- `SubledgerPosting` — validasi dan pencatatan dokumen dari entri, lalu posting lewat `GeneralLedger`; dipakai `TransactionEntry`.
- `SubledgerReversal` — pembalikan efek dokumen beserta jurnalnya.
- `ReconciliationStatus` — status rekonsiliasi akun kontrol per tanggal, untuk kesiapan tutup buku.
- `OpeningOpenItems` — impor open item awal dan total per akun kontrol, untuk saldo konversi.

## Kebutuhan

- **SL-FR-01 Pihak.** Master minimal: nama, peran pelanggan/pemasok (boleh keduanya), referensi eksternal per `source_system` agar sistem sumber dapat merujuk pihaknya sendiri, identitas pajak opsional, profil posting, dan status. Pihak yang pernah dipakai hanya dapat dinonaktifkan.
- **SL-FR-02 Profil posting dan akun kontrol.** Setiap pihak memakai profil posting yang menentukan akun kontrol piutang/utang (mis. pihak ketiga, pihak berelasi, valas). Profil default dipetakan lewat peran `receivables_control` dan `payables_control`. Akun kontrol harus akun daun aktif dengan kelas sesuai dan hanya menerima sumber `subledger` (GL-FR-02). Pemetaan berversi.
- **SL-FR-03 Rekening kas/bank.** Setiap `CashAccount` punya mata uang dan satu akun GL (aset, daun, hanya sumber `subledger`). Transaksi kas/bank selalu memakai mata uang rekening.
- **SL-FR-04 Dokumen dan open item.** Entri dengan rute subledger membentuk dokumen sesuai jenisnya: `invoice` membuat open item; `credit_note`, `payment`, dan `write_off` melunasi open item lewat alokasi; pembayaran yang tidak teralokasi penuh menjadi `advance` yang dapat dialokasikan kemudian. Setiap dokumen menghasilkan satu jurnal lewat `GeneralLedger` dengan sumber `subledger`.
- **SL-FR-05 Alokasi.** Alokasi boleh sebagian dan banyak-ke-banyak, dalam mata uang yang sama (MVP); total alokasi tidak boleh melebihi sisa open item. Alokasi atas dokumen yang sudah terposting (mis. menerapkan uang muka) adalah perintah subledger berizin dan hanya membuat jurnal bila ada selisih kurs (`system.fx_realized`). Pembatalan alokasi tercatat dan diaudit.
- **SL-FR-06 Selisih kurs terealisasi.** Saat alokasi valas, selisih antara nilai tercatat open item dan nilainya pada kurs pelunasan dibukukan ke peran `fx_realized_gain`/`fx_realized_loss`. Nilai tercatat memakai kurs awal atau kurs revaluasi terakhir, sesuai kebijakan revaluasi GL (GL-FR-09).
- **SL-FR-07 Revaluasi open item.** Pada akhir periode, user berizin menjalankan revaluasi open item valas dengan jenis kurs kebijakan. Selisihnya diposting dengan key `system.fx_revaluation` ke akun kontrol dan peran selisih kurs belum terealisasi, serta dibalik otomatis di awal periode berikutnya bila kebijakan menetapkan. Menjalankan ulang untuk periode yang sama tidak menggandakan hasil.
- **SL-FR-08 Aging.** Laporan umur piutang/utang per tanggal (termasuk tanggal lampau) dengan bucket dan dasar dari `AgingPolicy`, dalam mata uang transaksi dan fungsional, per pihak dan dokumen, dengan drill-down. Aging menjadi dasar estimasi cadangan kerugian piutang [R7]; nilai cadangan tetap diinput akuntan.
- **SL-FR-09 Rekonsiliasi akun kontrol.** Bandingkan saldo GL setiap akun kontrol dengan total rinciannya (sisa open item atau saldo transaksi kas/bank) per tanggal. Selisih ditampilkan beserta penyebab yang dapat ditelusuri. Selisih yang belum selesai memblokir tutup tahun kecuali ada waiver beralasan dari user berizin (diaudit).
- **SL-FR-10 Open item awal (cutover).** Impor open item per tanggal saldo konversi dengan pemetaan pihak sumber, mata uang, kurs, dan nilai sisa. Total per akun kontrol harus sama dengan baris akun kontrol pada batch saldo konversi (GL-FR-11). Impor idempoten per batch.
- **SL-FR-11 Transaksi kas/bank.** Setiap penerimaan/pengeluaran tercatat per rekening dengan referensi dokumen dan status `cleared` (tanggal dan aktor) yang dapat diubah user berizin.
- **SL-FR-12 Pembalikan dokumen.** Pembalikan yang dirutekan dari `TransactionEntry` membatalkan efek dokumen pada open item dan transaksi kas/bank, lalu memposting pembalikan lewat `GeneralLedger`. Dokumen yang masih memiliki alokasi aktif ditolak sampai alokasinya dibatalkan.
- **SL-FR-13 Rekonsiliasi bank (`F2`).** Impor mutasi rekening dari CSV dengan pemetaan kolom per rekening, pencocokan (manual dan saran menurut nilai, tanggal, referensi), dan laporan rekonsiliasi (saldo buku vs saldo rekening koran beserta item outstanding).

## Kriteria penerimaan

- **SL-AC-01** Impor open item awal yang totalnya sama dengan baris akun kontrol diterima; selisih sekecil apa pun menolak seluruh batch; impor ulang batch yang sama tidak menggandakan saldo.
- **SL-AC-02** Invoice USD 1.000 berkurs 15.000 yang dilunasi pada kurs 15.500 menutup open item sepenuhnya dan membukukan laba selisih kurs terealisasi 500.000 dalam jurnal pelunasan yang sama.
- **SL-AC-03** Pembayaran 600 atas invoice 1.000 menyisakan open item 400; pembayaran 1.200 atas invoice 1.000 tanpa alokasi lain menyisakan uang muka 200.
- **SL-AC-04** Aging per tanggal lampau hanya memperhitungkan alokasi bertanggal ≤ tanggal tersebut; mengubah bucket tidak memerlukan perubahan kode.
- **SL-AC-05** Jurnal override ke akun kontrol (GL-FR-07) muncul sebagai selisih rekonsiliasi dan memblokir tutup tahun sampai diselesaikan atau diberi waiver teraudit.
- **SL-AC-06** Untuk kedua kebijakan revaluasi (dibalik otomatis atau tidak), jumlah selisih kurs belum terealisasi dan terealisasi sama dengan selisih dari kurs awal ke kurs pelunasan; tidak ada penghitungan ganda.
- **SL-AC-07** Pembalikan invoice yang masih memiliki alokasi ditolak; setelah alokasi dibatalkan, pembalikan menghapus efek open item dan membalik jurnalnya.
- **SL-AC-08** Referensi pihak eksternal yang sama dari satu `source_system` selalu dipetakan ke `Party` yang sama; referensi yang sama dari `source_system` lain dianggap pihak berbeda.
