# General Ledger — COA, Periode, Jurnal, Valas, Tutup Buku

> Modul `GeneralLedger` (prefix `GL`). Hanya bergantung pada `MasterData`; tidak mengenal modul yang memanggilnya ([DOMAIN-DEPENDENCIES.md](../DOMAIN-DEPENDENCIES.md)).

## Tujuan & batas

Menjadi buku besar perusahaan: bagan akun, kalender fiskal, jurnal yang sudah diposting, saldo, valas, saldo konversi, dan tutup buku. Modul ini menerima perintah posting dari `TransactionEntry`, `Subledger`, dan proses sistemnya sendiri; draf dan approval berada di `TransactionEntry`. Tabel jurnal hanya bertambah (P-03).

## Data milik modul

| Data | Isi utama |
| --- | --- |
| `Account` | Kode unik per perusahaan, nama, `AccountClass`, saldo normal, induk/daun, status, sumber posting yang diizinkan, kewajiban dimensi, batasan mata uang, penanda revaluasi |
| `FiscalCalendar` | Awal tahun buku, pola periode (bulanan, 4-4-5, rentang bebas) |
| `FiscalYear`, `Period` | Rentang tanggal tanpa celah atau tumpang tindih; status periode |
| `Journal` | Nomor, `accounting_date`, periode, tanggal dokumen, deskripsi, sumber (`entry`/`subledger`/`system`), key dan versi Jenis Transaksi, referensi opak, `reverses_journal_id`, aktor, waktu posting |
| `JournalLine` | Akun, debit/kredit fungsional, mata uang transaksi, nilai transaksi, kurs, `dimension_set_id`, deskripsi |
| `AccountBalance` | Saldo teragregasi per akun, periode, `dimension_set_id`, dan mata uang transaksi; diperbarui saat posting |
| `OpeningBalanceBatch` | `cutover_date`, `ledger_start_date`, sumber, pemetaan, status validasi, granularitas histori |
| `ClosingRun`, `RevaluationRun` | Tahun atau periode, jurnal hasil, versi pemetaan, status |
| `LedgerPolicy` | Posting saat `soft_closed`, override akun kontrol, seri nomor jurnal, kebijakan revaluasi, toleransi pembulatan kurs |

## Interface publik

- `PostingService` — posting jurnal seimbang dan pembalikan; dipakai `TransactionEntry` dan `Subledger`.
- `PostingValidator` — validasi akun, periode, sumber, dimensi, dan mata uang tanpa posting.
- `AccountDirectory` — metadata akun dan validasi target peran untuk modul lain.
- `LedgerReader` — saldo dan mutasi per akun, periode, rentang tanggal, dimensi, key Jenis Transaksi, dan sumber; detail jurnal; saldo per mata uang transaksi.
- `PeriodDirectory` — periode dan statusnya.

## Kebutuhan

- **GL-FR-01 Bagan akun.** Kode unik per perusahaan, nama, kelas, saldo normal (default dari kelas; akun kontra boleh berlawanan), dan induk/daun. Akun induk dan anaknya memiliki kelas yang sama. Hanya akun daun aktif yang menerima posting. Akun yang pernah dipakai hanya dapat dinonaktifkan. Template COA hanya saran ([CHART-OF-ACCOUNTS.md](../CHART-OF-ACCOUNTS.md)).
- **GL-FR-02 Atribut posting akun.** Per akun, sebagai data: sumber posting yang diizinkan (`entry`, `subledger`, `system`), kewajiban per tipe dimensi (wajib, opsional, atau dilarang), batasan mata uang (hanya fungsional, satu mata uang asing tertentu, atau bebas), dan penanda revaluasi untuk pos moneter valas.
- **GL-FR-03 Kalender fiskal dan periode.** Periode dibentuk dari kalender perusahaan secara berurutan tanpa celah atau tumpang tindih. Periode baru tidak membuat jurnal pembukaan; saldo awalnya adalah saldo akhir periode sebelumnya.
- **GL-FR-04 Status periode.** `open` menerima posting. `soft_closed` hanya menerima posting dari key Jenis Transaksi dan permission yang ditetapkan kebijakan (mis. koreksi audit). `closed` menolak semua posting. Menutup dan membuka kembali memerlukan permission; membuka kembali wajib beralasan, diaudit, dan menandai hasil turunan (jurnal penutup, snapshot) untuk dihitung ulang.
- **GL-FR-05 Posting jurnal.** Perintah posting divalidasi dan dicatat secara atomik: perusahaan, status periode untuk `accounting_date`, akun daun aktif, sumber yang diizinkan, kewajiban dimensi, batasan mata uang, kurs untuk baris valas, dan keseimbangan tepat dalam mata uang fungsional. Selisih pembulatan konversi dalam toleransi kebijakan dibukukan otomatis ke peran `fx_rounding`; di atas toleransi ditolak. Nomor jurnal diberikan saat posting dari seri berpola sehingga tanpa celah per seri. Saldo teragregasi diperbarui dalam transaksi yang sama dengan urutan penguncian konsisten. Perintah dengan referensi entri yang sama mengembalikan jurnal yang sudah ada.
- **GL-FR-06 Imutabilitas dan pembalikan.** Jurnal posted tidak dapat diubah atau dihapus, ditegakkan juga di database. Pembalikan membuat jurnal bersisi terbalik dengan akun, dimensi, nilai transaksi, dan kurs yang sama dengan jurnal asal, bertanggal sesuai perintah, dan saling tertaut. Satu jurnal hanya dapat dibalik sekali.
- **GL-FR-07 Override akun kontrol.** Bila kebijakan mengizinkan (default: tidak), posting ke akun yang dibatasi sumbernya dari sumber lain memerlukan permission override dan alasan, ditandai pada jurnal, dan diaudit.
- **GL-FR-08 Valas.** Setiap baris menyimpan mata uang transaksi, nilai transaksi, kurs, jenis dan tanggal kurs, serta nilai fungsional; baris mata uang fungsional berkurs 1. Saldo per mata uang transaksi tersedia untuk setiap akun.
- **GL-FR-09 Revaluasi akun non-kontrol.** Pada akhir periode, user berizin menjalankan revaluasi akun bertanda revaluasi yang bukan akun kontrol subledger: saldo mata uang transaksi × kurs penutup (jenis kurs kebijakan) dibandingkan dengan nilai tercatat, lalu selisihnya diposting dengan key `system.fx_revaluation` ke peran `fx_unrealized_gain`/`fx_unrealized_loss`. Kebijakan menetapkan apakah revaluasi dibalik otomatis di awal periode berikutnya; pilihan ini juga menjadi dasar selisih terealisasi di `Subledger` (SL-FR-06). Menjalankan ulang tidak menggandakan hasil.
- **GL-FR-10 Peran akun GL.** `retained_earnings` (satu akun ekuitas daun; wajib sebelum tutup tahun) dan peran selisih kurs (`fx_realized_gain`, `fx_realized_loss`, `fx_unrealized_gain`, `fx_unrealized_loss`, `fx_rounding`; wajib bila perusahaan memakai valas). Pemetaan berversi. GL menyediakan validasi target peran bagi modul lain: akun aktif, daun, perusahaan sama, dan kelas sesuai.
- **GL-FR-11 Saldo konversi dan histori.** Perusahaan berjalan menetapkan `cutover_date` (awal operasi di sistem) dan `ledger_start_date` (awal histori yang dimigrasikan; sama dengan `cutover_date` bila tanpa histori). Batch saldo konversi bertanggal `ledger_start_date` memuat saldo akun neraca, ditambah saldo YTD pendapatan/beban bila dimulai di tengah tahun buku. Kode akun/dimensi sumber dipetakan ke akun dan dimensi perusahaan; kode wajib yang belum dipetakan ditolak. Validasi: seimbang, akun aktif, dimensi wajib, tanggal efektif, dan duplikasi; posting atomik dan idempoten; baris akun kontrol harus sama dengan total open item `Subledger` (SL-FR-10). Histori antara `ledger_start_date` dan `cutover_date` dapat diimpor sebagai ringkasan per periode atau transaksi (`system.history_import`) dengan granularitas tercatat; saldo buku besar pada `cutover_date` harus cocok dengan neraca saldo sumber. Saldo modul di luar produk (mis. persediaan, aset tetap) dimigrasikan sebagai saldo buku besar saja.
- **GL-FR-12 Tutup tahun buku.** Setelah use case kesiapan memastikan neraca saldo seimbang, tidak ada entri yang belum selesai, dan rekonsiliasi subledger beres atau di-waiver, GL memposting satu jurnal penutup `system.closing` bertanggal akhir tahun buku. Jurnal ini menolkan saldo setiap akun pendapatan/beban per set dimensi ke akun peran `retained_earnings`; unik per perusahaan dan tahun, idempoten, mencatat versi pemetaan, dan tidak mengubah jurnal asal.
- **GL-FR-13 Saldo tahun berikutnya.** Saldo akun neraca berlanjut ke tahun berikutnya; akun pendapatan/beban mulai dari nol karena jurnal penutup. Saldo awal dihitung dari ledger tanpa posting ganda.
- **GL-FR-14 Buka kembali tahun buku.** Memerlukan permission dan alasan, membalik jurnal penutup, menandai hasil turunan untuk dihitung ulang, dan diaudit. Penutupan ulang membuat jurnal penutup baru.
- **GL-FR-15 Antarentitas (`Post`).** Baris jurnal dapat membawa `counterparty_company_id` sebagai referensi opak untuk eliminasi konsolidasi.

## Kriteria penerimaan

- **GL-AC-01** Jurnal debit 1.000 dan kredit 1.000 terposting; debit 1.000 dan kredit 999 ditolak tanpa posting parsial.
- **GL-AC-02** Posting ke periode `closed`, ke akun induk/nonaktif, ke akun yang tidak menerima sumber tersebut, atau tanpa dimensi wajib ditolak dengan alasan yang menyebut masalahnya.
- **GL-AC-03** Jurnal tampil menurut `accounting_date`, bukan waktu diterima. Perubahan atau penghapusan jurnal posted ditolak, termasuk lewat query langsung ke database; koreksi hanya muncul sebagai pembalikan dan pengganti yang tertaut.
- **GL-AC-04** Pada periode `soft_closed`, hanya key Jenis Transaksi dan permission yang diizinkan kebijakan yang dapat memposting.
- **GL-AC-05** Baris USD 1.000 pada kurs 15.000 terposting 15.000.000 fungsional; selisih pembulatan konversi dalam toleransi masuk ke `fx_rounding`, di atas toleransi ditolak.
- **GL-AC-06** Revaluasi saldo Bank USD 1.000 dari kurs tercatat 15.000 ke kurs penutup 15.200 memposting laba belum terealisasi 200.000; dengan kebijakan pembalikan otomatis, pembaliknya bertanggal hari pertama periode berikutnya.
- **GL-AC-07** Impor saldo konversi: kode sumber wajib yang belum dipetakan ditolak; impor ulang batch yang sama tidak menggandakan saldo; baris akun kontrol yang berbeda dari total open item ditolak.
- **GL-AC-08** Periode baru menampilkan saldo awal yang sama dengan saldo akhir periode sebelumnya tanpa jurnal pembukaan.
- **GL-AC-09** Tutup tahun ditolak bila neraca saldo tidak seimbang atau masih ada entri yang belum selesai; selisih rekonsiliasi hanya dapat dilewati lewat waiver teraudit. Tutup yang berhasil menghasilkan tepat satu jurnal penutup seimbang; akun pendapatan/beban bersaldo nol dan saldo laba memuat hasilnya.
- **GL-AC-10** Setelah tutup tahun, saldo awal akun neraca tahun berikutnya sama dengan saldo penutupannya. Membuka kembali tahun mencatat aktor dan alasan, membalik jurnal penutup, dan menandai hasil turunan untuk dihitung ulang.
- **GL-AC-11** Jurnal asal ditambah pembaliknya bernilai nol di setiap akun, dimensi, dan mata uang; pembalikan kedua atas jurnal yang sama ditolak.
- **GL-AC-12** Nomor jurnal dalam satu seri unik dan tanpa celah, termasuk saat posting bersamaan.
