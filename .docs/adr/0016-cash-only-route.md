# ADR-0016 — Rute Hanya-Kas untuk Kas tanpa Akun Kontrol

Status: Diusulkan (2026-10-30)

## Konteks

Akun kas dan bank hanya menerima sumber `subledger` (GL-FR-02, SL-FR-03), supaya setiap gerakan kas tercatat sebagai transaksi kas dan saldo akun GL-nya sama dengan rinciannya (SL-FR-09). Sekarang hanya dua jalur yang sah ke akun kas: dokumen berpihak (`payment`, `advance`, `loan`) dan transfer antar rekening kas. Keduanya mewajibkan akun kontrol atau rekening kas kedua.

Akibatnya akuntan tidak dapat merancang jenis transaksi untuk kas yang tidak melibatkan pihak: biaya tunai, setoran modal tunai, bunga atau biaya bank, pendapatan lain yang diterima tunai. Entri manual ditolak karena sumbernya, dan rute berpihak memaksa pihak yang tidak ada. Keterbatasan ini sudah tercatat di panduan ("Biaya tunai tanpa pihak") dan muncul lagi lewat studi kasus setoran modal (ADR-0015). Tujuan produk menuntut akuntan dapat mengatur jenis transaksi sesuai kebijakan bisnisnya dan operator tinggal mencatat; usaha apa pun mencatat biaya tunai setiap hari, jadi ini celah produk, bukan pilihan akuntansi.

Dampak sampingan: baris rekening koran yang tidak punya padanan, seperti biaya admin dan bunga bank, sekarang tidak dapat dibukukan sama sekali, sehingga rekonsiliasi bank (SL-FR-13) selalu menyisakan item belum dibukukan yang tidak ada jalan menyelesaikannya.

## Keputusan

1. **Rute baru berjenis `cash` ("Kas tanpa pihak").** Bukan dokumen dan tidak masuk `DocumentKind`, seperti `transfer`: tanpa pihak, akun kontrol, open item, alokasi, dan jatuh tempo. Ia hanya mencatat **transaksi kas** dari jurnal entri. Rute memuat satu field rekening kas (`cash_account_field`) dan, opsional, satu field referensi (`document_number_field`) yang menjadi referensi transaksi kas; bila kosong, referensinya nomor jurnal. Referensi yang berarti membantu pencocokan rekening koran (SL-FR-13).
2. **Aturan posting bebas selain satu syarat.** Tepat satu aturan bersumber `cash_account` yang menunjuk field rekening kas; sisinya menentukan arah (debit berarti kas masuk, kredit berarti kas keluar). Aturan lain boleh memakai akun tetap atau field akun seperti biasa, dengan dimensi (mis. biaya tunai ke akun biaya bertag Proyek). Aturan bersumber akun kontrol ditolak saat terbit; untuk pihak, pakai rute berpihak.
3. **Pencatatan mengikuti `CashTransferPosting`.** Komponen baru `CashEntryPosting` di `Subledger` (kontrak `SubledgerPosting` bertambah `validateCashEntry` dan `postCashEntry`, dipakai `TransactionEntry`), memeriksa: rekening kas ada dan aktif; baris ke akun kas semuanya di satu sisi dan dalam mata uang rekening; jurnal tidak menyentuh akun kontrol maupun akun rekening kas lain (baris nyasar ditolak, seperti pada dokumen dan transfer). Lalu satu `CashTransaction` dicatat dengan arah dan nilai dari baris kas, `journal_id` jurnalnya, dan tanpa `document_id`.
4. **Jurnal diposting dengan sumber `subledger`**, seperti dokumen dan transfer. Akibatnya akun lain pada jurnal itu harus menerima sumber `subledger`. Akuntan yang membatasi akun biaya hanya ke sumber `entry` membuat jenis ini ditolak di akun itu; pesan galatnya sudah menyebut sumber dan akunnya (GL-FR-02), dan panduan menjelaskannya.
5. **Mata uang mengikuti aturan pembayaran (TE-FR-06).** Baris kas dalam mata uang rekening; baris lain dalam mata uang entri dengan kurs dari tabel kurs; selisihnya ditutup aturan `fx_difference`. Tidak ada perilaku valas baru.
6. **Pembalikan didukung** untuk entri hanya-kas dan, sekalian, untuk transfer yang sekarang ditolak (M6-08). Jurnal pembalikan dibuat oleh GL, lalu transaksi kas berlawanan arah dicatat per transaksi kas awal (pola `DocumentReversals::returnCash`), supaya rekening tetap sama dengan buku besar (SL-FR-12). Pembalikan ganda ditolak seperti dokumen.
7. **Rekonsiliasi dan rekening koran tidak berubah rumus.** Rekonsiliasi kas membandingkan saldo GL dengan total transaksi kas dalam mata uang rekening; entri hanya-kas menambah keduanya sama besar. Transaksi kas hasil rute ini dapat dicocokkan dengan baris rekening koran seperti lainnya, sehingga biaya admin dan bunga bank dapat dibukukan lalu dicocokkan.
8. **Template.** `daily-operations` menambah jenis siap pakai: Biaya Tunai (rekening, akun biaya yang dipilih, jumlah, nomor bukti), Penerimaan Tunai Lain (akun pendapatan atau ekuitas yang dipilih), dan Biaya atau Bunga Bank. Tetap saran yang dikonfirmasi (X-AC-03), dan akun yang boleh dipilih dipersempit akuntan.
9. **Tanpa migrasi tabel** bila pemeriksaan di implementasi membenarkan bahwa `sl_cash_transactions` sudah cukup (`journal_id` dan `document_id` sudah nullable, dipakai transfer). Yang bertambah hanya nilai rute (`cash`) pada skema versi Jenis Transaksi.

## Konsekuensi

Akuntan dapat merancang biaya tunai, setoran modal tunai, bunga bank, dan penerimaan lain tanpa melonggarkan pengaman akun kas: saldo GL kas tetap sama dengan transaksi kasnya dan rekonsiliasi tetap bermakna. Rekonsiliasi bank dapat diselesaikan sampai tuntas. Pengaman "kas hanya bergerak lewat Subledger" tetap utuh; yang bertambah adalah satu pintu yang divalidasi sama ketatnya dengan dokumen dan transfer.

Perubahan menyentuh `Subledger` (komponen baru, kontrak), `TransactionEntry` (rute, validasi terbit dan entri, pembalikan), SPA (pilihan jenis rute di perancang, tanpa field pihak), OpenAPI, template, dan panduan (butir "Biaya tunai tanpa pihak" dihapus). Pembalikan transfer kas ikut berubah dari ditolak menjadi didukung.

## Alternatif ditolak

- **Mengizinkan sumber `entry` pada akun kas:** merusak invarian bahwa saldo GL kas sama dengan transaksi kasnya (SL-FR-09) dan membuat pencocokan rekening koran tidak lagi menjangkau semua gerakan.
- **Pihak boneka ("Kas umum") agar rute berpihak dapat dipakai:** pintasan yang mencemari open item, laporan umur, dan saldo per pihak.
- **Kebijakan override akun kontrol (GL-FR-07) untuk kas:** override berizin dan beralasan untuk keadaan luar biasa, tampil sebagai selisih rekonsiliasi (SL-AC-05); bukan jalur untuk pencatatan harian.
- **Jenis dokumen baru tanpa pihak:** `Document` dan `OpenItem` mensyaratkan pihak, dan kas tanpa pihak tidak punya saldo terbuka yang perlu dilacak.
