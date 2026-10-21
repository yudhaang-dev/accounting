# Panduan Pengguna

> Untuk admin keuangan dan akuntan yang memakai aplikasi web pembukuan. Panduan ini mengikuti istilah dan menu di layar. Kebutuhan produk ada di [PRODUCT.md](PRODUCT.md); panduan ini tidak menggantikannya. Bukan nasihat akuntansi atau pajak: pemetaan akun, kebijakan, dan format laporan ditetapkan serta ditinjau akuntan perusahaan.

## Isi

1. [Gambaran singkat](#1-gambaran-singkat)
2. [Masuk dan memilih ruang kerja](#2-masuk-dan-memilih-ruang-kerja)
3. [Menyiapkan perusahaan](#3-menyiapkan-perusahaan)
4. [Data induk](#4-data-induk)
5. [Mencatat transaksi](#5-mencatat-transaksi)
6. [Piutang, utang, dan kas](#6-piutang-utang-dan-kas)
7. [Melihat laporan](#7-melihat-laporan)
8. [Akhir periode dan akhir tahun](#8-akhir-periode-dan-akhir-tahun)
9. [Perancang Jenis Transaksi](#9-perancang-jenis-transaksi)
10. [Memindahkan pembukuan dari sistem lama](#10-memindahkan-pembukuan-dari-sistem-lama)
11. [Anggaran dan proyeksi](#11-anggaran-dan-proyeksi)
12. [Akses dan keamanan](#12-akses-dan-keamanan)
13. [Pesan yang sering muncul](#13-pesan-yang-sering-muncul)
14. [Batasan yang perlu diketahui](#14-batasan-yang-perlu-diketahui)
15. [Istilah](#15-istilah)

---

## 1. Gambaran singkat

### Cara kerja

Anda tidak menulis jurnal debit-kredit dari nol untuk setiap transaksi. Alurnya:

1. Anda memilih **Jenis Transaksi** (misalnya Penjualan Kredit) dan mengisi isiannya: pelanggan, jumlah, rekening.
2. Aturan posting pada jenis itu, yang disiapkan akuntan, menyusun **jurnal** dari isian Anda.
3. Entri melewati **persetujuan** bila kebijakan perusahaan mewajibkannya.
4. Setelah **diposting**, jurnal masuk ke **buku besar** dan muncul di laporan.

Akuntan juga bisa memakai Jenis Transaksi **Baris bebas** (Jurnal Umum) untuk menulis debit dan kredit sendiri.

### Hal yang perlu diingat sejak awal

- **Jurnal yang sudah diposting tidak bisa diubah atau dihapus.** Koreksi dilakukan dengan **membalik** jurnal, lalu membuat entri pengganti bila perlu. Sistem menyimpan semuanya sebagai jejak audit.
- **Perubahan konfigurasi menjadi versi baru.** Kebijakan, pemetaan akun, dan Jenis Transaksi berversi. Transaksi lama tetap memakai versi yang berlaku saat itu.
- **Satu ruang kerja berisi satu perusahaan.** Anda bisa menjadi anggota beberapa ruang kerja.
- **Menu yang tampil mengikuti hak akses Anda.** Menu yang tidak muncul berarti akun Anda belum diberi izin untuk layar itu. Hubungi administrator.
- **Bahasa tampilan** bisa diganti dari pemilih bahasa di pojok kanan atas.

### Menu samping

| Kelompok | Isi |
| --- | --- |
| Beranda | Profil perusahaan dan langkah penyiapan |
| Pembukuan | Akun, Periode, Pengaturan buku besar, Saldo konversi, Jurnal |
| Data induk | Dimensi, Mata uang, Kurs |
| Transaksi | Entri, Kotak persetujuan, Jurnal berulang, Jenis transaksi, Kebijakan persetujuan |
| Subledger | Pihak, Profil posting, Kas dan bank, Transaksi kas, Dokumen, Open item, Alokasi, Rekonsiliasi |
| Pelaporan | Laporan, Neraca saldo, Buku besar, Umur piutang/utang |
| Perencanaan | Anggaran |
| Akses | Anggota, Role, Kebijakan keamanan, Klien integrasi |

Kelompok yang seluruh isinya di luar izin Anda disembunyikan.

---

## 2. Masuk dan memilih ruang kerja

### Masuk

1. Buka halaman **Masuk**, isi email dan kata sandi, lalu klik **Masuk**.
2. Alternatifnya, klik **Masuk dengan passkey** dan ikuti petunjuk perangkat Anda.
3. Bila akun memakai verifikasi dua langkah, masukkan 6 digit kode dari aplikasi autentikator. Kehilangan ponsel: pilih **Gunakan kode pemulihan** dan masukkan salah satu kode yang Anda simpan saat mengaktifkan 2FA.

Lupa kata sandi: klik **Lupa kata sandi?**, isi email, lalu buka tautan di email. Kata sandi baru minimal 8 karakter. Cek folder spam bila email belum datang.

Sesi berakhir otomatis bila tidak ada aktivitas selama batas yang ditetapkan perusahaan. Anda akan diminta masuk lagi.

### Memilih ruang kerja

- Bila hanya punya satu ruang kerja, sistem langsung membukanya.
- Bila punya beberapa, pilih dari daftar **Pilih ruang kerja**. Sistem mengingat ruang kerja yang terakhir Anda pakai.
- Untuk berpindah, klik pemilih ruang kerja di kiri atas sidebar (**Ganti ruang kerja**).
- Bila diundang ke ruang kerja baru, buka tautan di email undangan dan klik **Terima undangan**. Pakai akun yang email-nya sama dengan alamat yang diundang.

### Keluar

Klik nama Anda di bawah sidebar, lalu **Keluar**.

---

## 3. Menyiapkan perusahaan

Bagian ini untuk administrator dan akuntan. Perusahaan baru berstatus **Penyiapan** dan baru bisa dipakai mencatat transaksi setelah **diaktifkan**.

### 3.1 Membuat ruang kerja

Dari daftar ruang kerja, klik **Buat ruang kerja baru**. Isi nama ruang kerja dan data perusahaan. Anda menjadi anggota pertama.

### 3.2 Wizard penyiapan

Di **Beranda**, klik **Lanjutkan penyiapan**. Ada empat langkah, berurutan:

1. **Profil perusahaan.** Nama, nama badan hukum, NPWP, zona waktu, bahasa dokumen, dan **mata uang fungsional** (kode ISO 4217, misalnya IDR). Mata uang fungsional hanya bisa diubah selama penyiapan; setelah aktif, tidak bisa diubah.
2. **Bagan akun.** Pilih template sebagai titik awal, **tinjau daftar akunnya**, hapus centang akun opsional yang tidak dipakai, centang pernyataan bahwa Anda sudah meninjau, lalu klik **Pasang bagan akun**. Template hanya saran: tidak ada akun yang dibuat sebelum Anda mengonfirmasi. Sesuaikan dengan kegiatan usaha dan standar yang perusahaan pakai.
3. **Kalender fiskal.** Pilih bulan awal tahun fiskal (Januari bila sama dengan tahun kalender), simpan, lalu buat tahun fiskal pertama. Periode bulanan terbentuk otomatis tanpa celah.
4. **Aktivasi.** Klik **Aktifkan perusahaan**. Tombol ini aktif setelah bagan akun dan kalender terpasang.

### 3.3 Urutan penyiapan setelah aktif

Sebelum transaksi harian berjalan, akuntan perlu menyelesaikan ini:

1. **Peran akun** di *Pengaturan buku besar* (lihat 3.4).
2. **Mata uang dan kurs** bila memakai valas (lihat 4.2 dan 4.3).
3. **Dimensi** bila ingin analisis per cabang atau proyek (lihat 4.1).
4. **Profil posting** dan pemetaan akun kontrol piutang dan utang (lihat 6.1).
5. **Rekening kas dan bank** (lihat 6.2).
6. **Pihak**: pelanggan dan pemasok (lihat 6.1).
7. **Jenis Transaksi**: impor template atau rancang sendiri (lihat 5.2 dan 9).
8. **Kebijakan persetujuan** (lihat 5.5).
9. **Anggota dan role** (lihat 12).

### 3.4 Pengaturan buku besar

Menu **Pembukuan → Pengaturan buku besar** memuat dua bagian.

**Peran akun.** Peran menentukan akun tujuan jurnal otomatis. Pilih akun untuk tiap peran lalu simpan:

| Peran | Dipakai untuk | Wajib |
| --- | --- | --- |
| Saldo laba | Tujuan laba atau rugi saat tutup tahun | Sebelum tutup tahun |
| Laba/rugi selisih kurs terealisasi | Selisih kurs saat valas dilunasi | Bila memakai valas |
| Laba/rugi selisih kurs belum terealisasi | Hasil revaluasi | Bila memakai valas |
| Pembulatan konversi kurs | Selisih kecil karena pembulatan | Bila memakai valas |

Status tiap peran: *Terpetakan*, *Belum dipetakan*, atau *Akun tidak lagi valid* (akunnya dinonaktifkan; pilih yang lain). Setiap perubahan menjadi versi baru, dan jurnal lama tetap mencatat versi yang dipakainya.

**Kebijakan buku besar.** Berlaku untuk seluruh posting:

- *Jenis transaksi yang boleh memposting ke periode tutup sementara.* Kosong berarti periode tutup sementara menolak semua posting.
- *Hak tambahan untuk posting ke periode tutup sementara.*
- *Hak untuk memposting ke akun kontrol dari sumber lain*, dengan alasan dan tercatat di audit. Kosong berarti tidak ada yang boleh.
- *Toleransi pembulatan konversi kurs*, *jenis kurs penutup revaluasi*, dan *balik revaluasi otomatis di awal periode berikutnya*.

### 3.5 Bagan akun

Menu **Pembukuan → Akun** menampilkan bagan akun. Anda bisa mencari, memfilter menurut kelas atau status, menambah (**Tambah akun**), mengubah, menonaktifkan, dan mengaktifkan kembali akun.

- Hanya akun **daun** (tanpa anak) yang menerima posting. Akun induk hanya untuk pengelompokan.
- **Kelas** (Aset, Liabilitas, Ekuitas, Pendapatan, Beban) tidak bisa diubah setelah akun dibuat.
- **Saldo normal** mengikuti kelas; akun kontra boleh berlawanan.
- Pada **Pengaturan posting** tiap akun, atur:
  - *Sumber posting yang diterima*: Entri manual, Subledger, atau Sistem. **Akun kontrol piutang/utang dan akun kas/bank harus hanya menerima Subledger.**
  - *Mata uang*: bebas, hanya fungsional, atau satu mata uang asing tertentu.
  - *Direvaluasi di akhir periode*, untuk pos moneter valas.
  - *Dimensi*: opsional, wajib, atau dilarang per tipe dimensi.

Akun yang sudah punya jurnal sebaiknya dinonaktifkan, bukan dihapus. Data keuangan tidak pernah dihapus.

---

## 4. Data induk

### 4.1 Dimensi

Menu **Data induk → Dimensi**. Dimensi adalah cara menganalisis transaksi, misalnya cabang atau proyek.

1. Klik **Tambah tipe**, isi nama dan kunci (huruf kecil, angka, garis bawah; tidak bisa diubah kemudian). Centang **Bertingkat** bila nilainya punya induk.
2. Tambahkan **nilai** pada tipe itu (**Tambah nilai**).
3. Pada entri, pilih nilai dimensi per baris. Bila akun mewajibkan dimensi, entri tanpa dimensi ditolak.

Laporan yang difilter pada nilai induk ikut menghitung semua turunannya. Nilai yang sudah dipakai dinonaktifkan, bukan dihapus.

### 4.2 Mata uang

Menu **Data induk → Mata uang**. Mata uang fungsional selalu aktif. Untuk valas, klik **Aktifkan mata uang**, pilih dari daftar ISO 4217, lalu atur **skala** (angka di belakang koma) dan **pembulatan**.

### 4.3 Kurs

Menu **Data induk → Kurs**. Kurs adalah nilai mata uang fungsional untuk 1 unit mata uang asing.

1. Buat **Jenis kurs** lebih dulu (misalnya kurs tengah bank sentral atau kurs pajak) lewat **Jenis kurs**.
2. Klik **Catat kurs**: pilih jenis, mata uang, tanggal berlaku, dan nilainya. Atau **Impor CSV**.
3. Untuk memperbaiki, klik **Koreksi kurs**. Koreksi menambah versi baru; kurs lama tetap ada di riwayat (**Tampilkan semua versi**).

Sistem tidak memakai kurs dari tanggal lain secara diam-diam. Tanpa kurs pada tanggalnya, entri valas ditolak dengan pesan kurs tidak ditemukan.

---

## 5. Mencatat transaksi

### 5.1 Siklus entri

Setiap transaksi dicatat sebagai **entri** di menu **Transaksi → Entri**.

| Status | Arti | Aksi yang tersedia |
| --- | --- | --- |
| Draf | Masih bisa diubah | Ubah, Ajukan, Batalkan |
| Menunggu persetujuan | Menunggu penyetuju | Setujui, Tolak |
| Disetujui | Siap diposting | Posting |
| Diposting | Sudah menjadi jurnal | Balik jurnal, Buat entri pengganti |
| Ditolak | Dikembalikan dengan alasan | Revisi (kembali ke draf) |
| Dibatalkan | Tidak dipakai, tetap bisa dibaca | — |

Kebijakan perusahaan menentukan siapa yang menyetujui, bisa lebih dari satu tahap, dan apakah entri **diposting otomatis** setelah disetujui. Pembuat entri umumnya tidak boleh menyetujui entrinya sendiri (pemisahan tugas).

Daftar entri bisa difilter menurut status, rentang tanggal, dan pembalikan terjadwal. Klik nomor entri untuk melihat detail: baris jurnal, isian, riwayat keputusan, dan lampiran.

### 5.2 Membuat entri

1. Klik **Entri baru** dan pilih **Jenis transaksi**.
2. Isi **Tanggal akuntansi** (menentukan periode jurnal), **Tanggal dokumen** (opsional), dan **Keterangan**.
3. Isi sisanya sesuai jenis (lihat di bawah).
4. Klik **Simpan draf**, lalu di halaman detail klik **Ajukan**.

Tanggal akuntansi harus berada di periode **Terbuka**. Periode yang ditutup menolak posting.

**Jenis terstruktur** menampilkan isian bernama, misalnya Pelanggan, Rekening, Jumlah. Anda tidak memilih debit atau kredit; jurnalnya tersusun dari aturan posting. Di bawah ini jenis dari template **Transaksi harian** dan **Jenis Transaksi umum** (diimpor lewat *Jenis transaksi → Impor template*):

| Jenis | Yang Anda isi | Jurnal yang terbentuk |
| --- | --- | --- |
| Penjualan Kredit | Pelanggan, nomor faktur, jatuh tempo, total, akun pendapatan | Piutang (D) / Pendapatan (K) |
| Terima Pembayaran Pelanggan | Pelanggan, rekening, nomor bukti, jumlah, faktur yang dilunasi | Kas atau bank (D) / Piutang (K) |
| Tagihan Pemasok | Pemasok, nomor tagihan, jatuh tempo, total, akun biaya atau persediaan | Biaya (D) / Utang (K) |
| Bayar Pemasok | Pemasok, rekening, nomor bukti, jumlah, tagihan yang dilunasi | Utang (D) / Kas atau bank (K) |
| Transfer Antar Kas atau Bank | Dari rekening, ke rekening, jumlah keluar, jumlah masuk | Rekening tujuan (D) / Rekening asal (K) |
| Ringkasan Payroll | Tanggal, gaji bruto, akun beban gaji, akun utang gaji | Beban gaji (D) / Utang gaji (K) |

Jenis yang memakai pihak butuh **profil posting** dengan akun kontrol piutang atau utang yang sudah dipetakan (lihat 6.1). Bila belum, entri ditolak dengan kode *MAPPING_REQUIRED*.

**Jenis baris bebas** (Jurnal Umum, Jurnal Penyesuaian) menampilkan tabel baris. Untuk tiap baris pilih akun lalu isi **Debit** atau **Kredit**, tidak keduanya. Total debit harus sama dengan total kredit (penanda **Seimbang**); selisih ditampilkan bila belum sama. Format angka di layar: `1.250.000,50`. Jenis ini untuk akuntan.

### 5.3 Valas pada entri

Bila jenis mengizinkan, isian **Mata uang** dan **Kurs** tampil. Kosongkan kurs untuk memakai tabel kurs; isi bila jenis mengizinkan input manual. Kurs yang dipakai disimpan pada entri.

### 5.4 Mengajukan, menyetujui, memposting

- **Ajukan** memeriksa entri: periode, akun, dimensi wajib, pihak, kurs. Pesan kesalahan menyebut isian yang bermasalah.
- **Kotak persetujuan** (*Transaksi → Kotak persetujuan*) berisi entri yang menunggu. Pilih **Menunggu saya** untuk yang bisa Anda setujui, atau **Semua yang menunggu**. Klik **Setujui**, atau **Tolak** dengan alasan (wajib).
- **Posting** mengubah entri menjadi jurnal. Nomor jurnal muncul di detail entri; klik untuk membukanya.

### 5.5 Kebijakan persetujuan

*Transaksi → Kebijakan persetujuan* (untuk pemegang izin kelola kebijakan):

- Centang larangan menyetujui entri sendiri bila diperlukan. Klien integrasi tidak pernah dapat menyetujui.
- Tambah **aturan** berdasarkan jenis transaksi, sumber entri (Manual, Integrasi, Berulang), dan nominal minimum. **Aturan pertama dari atas yang cocok yang dipakai**, jadi urutan menentukan. Gunakan tombol naikkan dan turunkan.
- Tiap aturan punya **tahap**, masing-masing meminta satu izin. Aturan tanpa tahap berarti disetujui otomatis.
- **Posting otomatis** memposting entri setelah tahap terakhir menyetujui.
- Entri yang tidak cocok aturan mana pun butuh satu persetujuan dari pemegang izin menyetujui entri.

Setiap penyimpanan membuat versi baru yang tercatat di audit.

### 5.6 Koreksi

Jurnal yang sudah diposting tidak bisa diubah. Pilih salah satu:

- **Balik jurnal.** Klik **Balik jurnal** di detail entri atau jurnal, isi tanggal pembalikan (periodenya harus terbuka) dan alasan. Hasilnya **draf** entri pembalikan yang masih harus diajukan, disetujui, dan diposting. Pembalikan selalu penuh dan hanya sekali.
- **Buat entri pengganti.** Membuat draf baru yang disalin dari entri lama. Entri lama tidak berubah, jadi balik jurnalnya terpisah bila perlu.
- **Balik otomatis.** Isi *Balik otomatis pada* di entri; setelah diposting, sistem membalik pada tanggal itu. Bila gagal (misalnya periodenya sudah ditutup), sistem tidak mencoba lagi; entri menampilkan peringatan dan Anda membalik manual. Filter *Pembalikan terjadwal* di daftar entri menunjukkan yang gagal.
- Untuk jurnal dari dokumen subledger (faktur, pembayaran), gunakan **Balik dokumen** di detail dokumen. Pembalikan ditolak bila masih ada alokasi aktif; batalkan alokasinya lebih dulu.

### 5.7 Lampiran

Di detail entri, lampirkan berkas pendukung. Lampiran ikut tersimpan sebagai jejak audit.

### 5.8 Jurnal berulang

*Transaksi → Jurnal berulang* untuk transaksi yang sama setiap periode, misalnya sewa atau penyusutan. Klik **Jadwal baru**, pilih jenis dan irama, dan isi nilai tetapnya. Entri yang dibuat jadwal melewati validasi dan persetujuan seperti entri lain. Jadwal bisa diubah atau dilewati; kegagalan ditampilkan di daftar.

---

## 6. Piutang, utang, dan kas

Bagian ini mencakup menu kelompok **Subledger**.

### 6.1 Pihak dan profil posting

**Pihak** (*Subledger → Pihak*) adalah pelanggan dan pemasok. Klik **Tambah pihak**, isi nama, pilih peran (Pelanggan, Pemasok, atau keduanya), dan NPWP bila ada. Pihak dengan **referensi eksternal** bisa dikenali oleh sistem lain per sistem sumber; referensi yang sudah ditambahkan tidak bisa dihapus.

**Profil posting** (*Subledger → Profil posting*) menentukan akun kontrol piutang dan utang bagi pihak yang memakainya. Profil pertama menjadi default dan melayani pihak tanpa profil.

1. Klik **Tambah profil**.
2. Pada profil, pilih akun untuk *Akun kontrol piutang* dan *Akun kontrol utang* lalu simpan. Akun harus berupa daun aktif berkelas aset (piutang) atau liabilitas (utang), dan **hanya menerima sumber Subledger** (atur di bagan akun).

Pemetaan berversi, jadi posting lama tetap pada akunnya.

### 6.2 Kas dan bank

*Subledger → Kas dan bank*. Klik **Tambah rekening**, pilih jenis (Kas tunai atau Rekening bank), isi nama, mata uang, dan **akun buku besar**. Akun itu harus aset daun aktif, hanya menerima sumber Subledger, dan belum dipakai rekening lain. **Jenis, mata uang, dan akun buku besar tidak bisa diubah setelah rekening dibuat.** Rekening yang tidak dipakai dinonaktifkan.

### 6.3 Dokumen dan open item

- **Dokumen** (*Subledger → Dokumen*) adalah faktur, nota kredit, pembayaran, uang muka, dan penghapusan, yang tercipta saat entri berrute subledger diposting. Daftar ini hanya untuk dibaca; buka dokumen untuk melihat jurnal dan entri asalnya.
- **Open item** (*Subledger → Open item*) adalah sisa yang masih harus dibayar atau diterima dari tiap dokumen, dalam mata uang dokumen dan fungsional. Statusnya *Terbuka*, *Lunas*, atau *Dibalik*. Daftar ini hanya untuk dibaca; dokumen dan alokasi yang mengubahnya.

### 6.4 Pelunasan dan uang muka

**Saat mencatat pembayaran**, isi bagian **Faktur yang dilunasi** (alokasi): pilih pihak lebih dulu, lalu pilih open item dan nominalnya. Alokasi boleh sebagian dan ke beberapa dokumen. Total alokasi tidak boleh melebihi sisa open item.

**Uang muka.** Pembayaran yang tidak dialokasikan penuh meninggalkan sisa sebagai **uang muka** (open item pembayaran yang masih terbuka). Contoh: pembayaran 1.200 atas faktur 1.000 melunasi faktur dan menyisakan uang muka 200.

> **Catatan akuntansi.** Pembayaran lewat jenis transaksi penerimaan mencatat kas di debit dan **akun kontrol piutang di kredit**. Uang muka pelanggan dengan demikian berupa saldo kredit pada akun piutang, bukan pada akun kewajiban terpisah. Bila laporan perlu menyajikannya sebagai kewajiban, tanyakan akuntan perusahaan.

**Menerapkan uang muka ke faktur kemudian:** *Subledger → Alokasi → Alokasi baru*. Pilih pihak, **item debit** (faktur) dan **item kredit** (uang muka), isi jumlah dan tanggal, lalu **Alokasikan**. Alokasi hanya membuat jurnal bila ada selisih kurs terealisasi.

**Membatalkan alokasi:** klik **Batalkan alokasi** dan isi alasan. Item yang dilunasi terbuka kembali sebesar jumlahnya. Alasan dicatat di audit.

### 6.5 Selisih kurs terealisasi

Pelunasan faktur valas pada kurs berbeda dari kurs faktur membukukan selisih ke akun laba atau rugi selisih kurs terealisasi, di jurnal pembayaran itu sendiri. Pastikan perannya sudah dipetakan (3.4). Bila belum, entri ditolak dengan *MAPPING_REQUIRED*.

### 6.6 Transaksi kas

*Subledger → Transaksi kas* menampilkan penerimaan dan pengeluaran per rekening. Setelah bank atau perhitungan kas mengonfirmasinya, klik **Tandai cleared** dan pilih tanggalnya (tidak boleh di masa depan atau sebelum tanggal transaksi). **Batalkan tanda cleared** untuk mengembalikan.

### 6.7 Umur piutang dan utang

*Pelaporan → Umur piutang/utang*. Pilih akun kontrol (Piutang atau Utang), tanggal **Per tanggal**, pihak, dan dasar umur (jatuh tempo atau tanggal dokumen), lalu **Tampilkan**. Hasilnya per pihak dan dokumen dalam bucket umur, dengan ekspor CSV. Klik **Bucket** untuk mengatur batas hari (mis. `30, 60, 90`); pengaturan berlaku untuk laporan berikutnya.

---

## 7. Melihat laporan

Semua laporan bisa difilter menurut rentang tanggal atau **Per tanggal**. Bagian **Filter lainnya** memuat dimensi, Jenis Transaksi, sumber posting, dan versi rencana. Hasil yang diekspor memakai tombol **Ekspor CSV**.

> Bila akses Anda dibatasi pada nilai dimensi tertentu, laporan hanya menjumlahkan data dalam cakupan itu dan ditandai *Dibatasi cakupan data Anda*. Totalnya bisa lebih kecil dari total perusahaan.

### 7.1 Neraca saldo

*Pelaporan → Neraca saldo*. Saldo awal, mutasi, dan saldo akhir per akun pada rentang. Saldo awal tidak diinput; ia dihitung dari semua jurnal sebelum tanggal awal. Total debit harus sama dengan kredit (penanda **Seimbang**). Bila tidak seimbang, hubungi administrator.

- Klik kode akun untuk membuka **buku besar** akun itu.
- **Kertas kerja:** pilih *key penyesuaian* di filter lainnya untuk melihat saldo sebelum, penyesuaian, dan sesudah.
- Penanda *Memuat saldo konversi* muncul bila saldo awal berasal dari impor sistem lama.

### 7.2 Buku besar

*Pelaporan → Buku besar*. Menampilkan **satu akun** dalam satu waktu: baris jurnal dengan saldo berjalan, tautan ke jurnal dan entri asalnya, dan ekspor CSV.

### 7.3 Laporan keuangan

*Pelaporan → Laporan* berisi **definisi laporan** perusahaan, seperti Laba rugi, Neraca, dan Arus kas tidak langsung. Pilih satu, atur tanggal, dan jalankan.

- Hasil per kolom: periode, YTD, per tanggal, pembanding, dan selisih.
- Klik baris untuk menelusuri ke akun penyusunnya, lalu ke buku besar dan jurnal.
- **Neraca** menampilkan pemeriksaan *Aset sama dengan liabilitas dan ekuitas*. **Arus kas tidak langsung** menampilkan pemeriksaan kas awal ditambah kenaikan bersih sama dengan kas akhir. Bila tidak cocok, pesannya menyebut angkanya.
- *Akun yang belum dipetakan* adalah akun yang belum diklaim baris mana pun. Nilainya tetap dihitung di baris pengecualian supaya total cocok. Minta akuntan memetakannya.
- Kolom di luar cakupan histori (sebelum cutover) ditandai *Tidak tersedia*, bukan nol.
- **Arus kas langsung** dan **perubahan ekuitas** adalah klasifikasi mutasi; akuntan menyiapkannya (lihat 9.3).

**Proyeksi vs realisasi.** Pilih **Versi rencana** pada filter untuk membandingkan realisasi dengan anggaran. Selisih ditandai *Menguntungkan* atau *Tidak menguntungkan*.

### 7.4 Snapshot

Snapshot adalah laporan yang **diterbitkan** dan disimpan apa adanya, lengkap dengan parameter, versi definisi, dan batas datanya. Gunanya agar laporan yang sudah dilaporkan ke pihak lain tidak berubah.

- Jalankan laporan lalu klik **Terbitkan snapshot**. Snapshot tidak berubah setelah terbit.
- Bila definisi laporan kemudian berubah, snapshot lama ditandai *Perlu dihitung ulang*. Klik **Hitung ulang** untuk membuat snapshot baru dengan versi berlaku; yang lama tetap.
- Perusahaan bisa mengatur penerbitan otomatis saat periode atau tahun ditutup.
- Pengguna dengan cakupan data terbatas tidak bisa menerbitkan atau membuka isi snapshot, karena snapshot adalah laporan seluruh perusahaan.

---

## 8. Akhir periode dan akhir tahun

Bagian ini untuk akuntan.

### 8.1 Urutan yang disarankan tiap periode

1. Pastikan semua entri periode itu sudah selesai: tidak ada yang berstatus Draf, Menunggu persetujuan, Disetujui, atau Ditolak.
2. Catat kurs penutup bila ada valas.
3. Jalankan **revaluasi** (8.2).
4. Jalankan **rekonsiliasi** (8.3) dan selesaikan selisihnya.
5. Tinjau **Neraca saldo** dan laporan.
6. **Tutup sementara** atau **Tutup** periode (8.4).

### 8.2 Revaluasi

*Pembukuan → Periode*, baris periode, aksi **Revaluasi**: menilai ulang saldo valas pada akun bertanda revaluasi dengan kurs penutup di akhir periode, lalu memposting selisihnya. Menjalankan ulang hanya memposting selisih baru. Hasilnya bisa dibalik otomatis di awal periode berikutnya sesuai kebijakan buku besar. Aksi **Open item** pada baris yang sama merevaluasi open item subledger.

Syarat: jenis kurs penutup dan peran selisih kurs belum terealisasi sudah diatur (3.4).

### 8.3 Rekonsiliasi

*Subledger → Rekonsiliasi*. Pilih **Per tanggal** lalu **Jalankan rekonsiliasi**. Sistem membandingkan saldo buku besar setiap akun kontrol dan akun kas/bank dengan total subledgernya.

| Status | Arti |
| --- | --- |
| Sesuai | Buku besar dan subledger sama |
| Selisih | Berbeda; memblokir tutup tahun |
| Di-waiver | Selisih diterima dengan alasan tercatat |

Untuk selisih: **Lihat penyebab** menampilkan jurnal yang menggerakkan akun tanpa melalui subledger. Bila selisih memang harus diterima, klik **Waiver selisih** dan isi alasan; alasan dicatat di audit.

### 8.4 Menutup periode

Di *Pembukuan → Periode*, pilih tahun fiskal lalu atur status tiap periode:

| Status | Perilaku posting |
| --- | --- |
| Terbuka | Menerima semua posting |
| Ditutup sementara | Hanya jenis transaksi yang diizinkan kebijakan buku besar, dan pemosting yang punya hak tambahan bila diwajibkan |
| Ditutup | Menolak semua posting |

Aksi: **Tutup sementara**, **Tutup**, dan **Buka kembali**. Membuka kembali meminta **alasan** yang dicatat bersama nama Anda di audit. Bila periode yang dibuka kembali sudah punya snapshot terbit, snapshot itu ditandai perlu dihitung ulang.

**Tambah tahun fiskal** membuat tahun baru beserta periodenya, tanpa celah.

### 8.5 Menutup tahun buku

Menu **Periode**, bagian tahun fiskal yang bersangkutan. Checklist harus terpenuhi:

1. **Akun saldo laba sudah dipetakan** (3.4).
2. **Periode terakhir menerima jurnal penutup**: tidak Ditutup, dan bila Ditutup sementara, kebijakan harus mengizinkan kunci `system.closing`.
3. **Tidak ada entri yang belum selesai** sampai akhir tahun.
4. **Rekonsiliasi**: setiap akun kontrol dan kas/bank sesuai, atau selisihnya sudah di-waiver.

Klik **Tutup tahun buku**. Sistem memposting jurnal penutup yang memindahkan laba atau rugi tahun berjalan ke saldo laba, per akun dan kombinasi dimensi, lalu membawa saldo ke tahun berikutnya.

**Buka kembali tahun buku** membalik jurnal penutup supaya tahun bisa ditutup ulang. Periode terakhir harus menerima posting, dan alasan wajib dicatat.

---

## 9. Perancang Jenis Transaksi

Bagian ini untuk akuntan atau administrator pemegang izin kelola kebijakan.

### 9.1 Daftar dan template

*Transaksi → Jenis transaksi* menampilkan semua jenis beserta mode, versi, dan pola nomor.

- **Impor template** menyarankan jenis umum. Tinjau tiap jenis (field dan aturan posting), pilih yang Anda mau, centang pernyataan konfirmasi, lalu klik **Impor**. Template yang tersedia: *Jenis Transaksi umum* (Jurnal Umum, Jurnal Penyesuaian, Ringkasan Payroll, Penjualan Kredit) dan *Transaksi harian* (Terima Pembayaran Pelanggan, Tagihan Pemasok, Bayar Pemasok, Transfer Antar Kas atau Bank, serta Terima Pembayaran dan Bayar Pemasok untuk rekening beda mata uang). Jenis yang kuncinya sudah ada ditandai *Sudah ada*.
- **Jenis baru** membuat jenis baris bebas dengan versi pertama yang sudah terbit. Kunci (huruf kecil, angka, garis bawah) tidak bisa diubah setelah dibuat.
- **Atur penomoran** menentukan pola nomor entri, misalnya `JU/{yyyy}/{mm}/{seq}`, lebar urutan, kapan urutan mulai dari 1 lagi, dan kapan nomor diberikan (saat entri dibuat atau saat diajukan). Memberi nomor saat diajukan menjaga urutan bebas dari draf yang tidak jadi dipakai.

### 9.2 Versi

Klik **Buka perancang**. Jenis memiliki versi dengan status *Draf*, *Terbit*, atau *Dihentikan*.

- **Versi terbit tidak bisa diubah.** Untuk mengubahnya, klik **Versi baru**; draf disalin dari versi terakhir. Edit draf lalu klik **Terbitkan**.
- **Hentikan** menghentikan versi untuk entri baru. Entri yang sudah dibuat tidak berubah.
- **Label dan kunci jenis tidak dapat diubah.** Bila salah, buat jenis baru.

### 9.3 Mode terstruktur

Rancang tiga hal:

1. **Field.** Nama (huruf kecil, angka, garis bawah), tipe, label, dan wajib atau tidak. Tipe: Nominal, Tanggal, Teks, Referensi, Akun, Pihak, Rekening kas/bank, Dimensi, Alokasi. Pada field Akun, isi *Akun yang boleh dipilih* untuk membatasi pilihan; kosong berarti semua.
2. **Aturan posting.** Tiap aturan menyebut sisi (debit atau kredit), field nominalnya, dan **sumber akun**:
   - *Akun tetap*,
   - *Akun dari field* (diisi pengguna),
   - *Akun kontrol pihak* (dari profil posting pihak),
   - *Akun rekening kas* (dari rekening yang dipilih).

   Aturan *Selisih kurs* menutup sisa nilai agar jurnal seimbang dalam mata uang fungsional. Nominal nol dilewati. Memakai ulang satu field nominal pada beberapa aturan harus dinyatakan eksplisit.
3. **Rute subledger.** Menentukan apakah entri menghasilkan dokumen subledger (Faktur, Nota kredit, Pembayaran, Penghapusan, atau Transfer), akun kontrolnya, field pihak, nomor dokumen, jatuh tempo, rekening kas, dan field alokasi.

Kebijakan mata uang jenis menentukan mata uang yang diizinkan dan apakah kurs boleh diinput manual.

Menerbitkan versi memeriksa kelengkapannya. Pesan kesalahan menyebut yang kurang: tanpa field, tanpa aturan, atau field yang dipakai ulang tanpa pernyataan.

### 9.4 Klasifikasi dan definisi laporan

Akuntan menyiapkan di menu laporan: **definisi laporan** (baris, kolom, dasar nilai, kumpulan akun), **klasifikasi mutasi** (untuk arus kas langsung dan perubahan ekuitas), dan **pemetaan kas dan setara kas**. Definisi dapat diimpor dari template lalu ditinjau dan dikonfirmasi. Perubahan struktur menjadi versi baru; snapshot lama tidak berubah.

---

## 10. Memindahkan pembukuan dari sistem lama

*Pembukuan → Saldo konversi*, wizard lima langkah. Impor diposting sebagai jurnal sistem dan **hanya dilakukan sekali**. Mengirim kunci sumber dan isi yang sama lagi tidak menggandakan saldo.

1. **Data impor.** *Kunci sumber*, *Sistem sumber*, *Tanggal cutover* (hari pertama pembukuan di sistem ini), dan *Awal histori* (opsional; harus hari pertama sebuah periode, kosong berarti sama dengan cutover).
2. **Berkas saldo.** CSV dengan kolom `source_code`, `debit`, `credit` (dan `description` bila ada). Pakai titik untuk desimal. Bila mulai di tengah tahun buku, sertakan saldo pendapatan dan beban tahun berjalan.
3. **Pemetaan akun.** Kode yang sama dengan kode akun perusahaan terisi otomatis; pilih akun untuk sisanya. Kode yang belum dipetakan menolak impor.
4. **Pemetaan pihak.** Hanya bila ada **open item awal**: CSV dengan kolom `control_role` (receivables atau payables), `party`, `kind` (invoice atau credit_note), `document_number`, `document_date`, `amount`, dan opsional `due_date`, `currency`, `exchange_rate`. Open item awal wajib bila neraca saldo memuat akun kontrol piutang atau utang, dan total per akun kontrol harus sama dengan saldonya di berkas saldo.
5. **Tinjau.** Periksa jumlah baris dan total. Saldo yang tidak seimbang ditolak.

Format tanggal di CSV: `TTTT-BB-HH` (mis. `2026-01-01`). Pesan kesalahan menyebut baris dan kolom yang salah.

Wizard ini belum memetakan dimensi; hubungi pengembang bila saldo awal perlu dimensi.

---

## 11. Anggaran dan proyeksi

*Perencanaan → Anggaran*. Rencana tidak pernah menjadi jurnal.

1. **Rencana baru**: nama dan tahun buku (tidak bisa diubah kemudian).
2. **Versi baru** pada rencana (mis. "anggaran awal", "forecast Q2"). Isi baris per periode, akun (pendapatan atau beban), dan dimensi lewat grid, atau impor CSV, atau salin dari versi lain atau dari realisasi.
3. **Terbitkan** versi. Versi terbit tidak bisa diubah; **Ubah versi terbit** membuat draf baru yang menyalin isinya. Versi tanpa baris tidak bisa diterbitkan. Perusahaan bisa mengatur siapa yang boleh menerbitkan (misalnya bukan orang yang terakhir mengubah baris).
4. Tetapkan **Pembanding default** agar laporan proyeksi vs realisasi memakainya.

---

## 12. Akses dan keamanan

### 12.1 Anggota

*Akses → Anggota*:

- **Undang anggota**: isi email, pilih satu role atau lebih, dan tanggal keanggotaan berakhir bila perlu (mis. auditor eksternal; akses berhenti di akhir hari itu).
- **Ubah** role atau masa berlaku. **Cabut akses** menghentikan seketika.
- **Cakupan data**: batasi anggota ke nilai dimensi tertentu beserta turunannya. Tanpa centang, anggota melihat semua data.
- **Undangan** yang masih menunggu bisa ditarik.

### 12.2 Role

*Akses → Role*. Role adalah kumpulan izin; mengubahnya langsung berlaku bagi semua pemegangnya. Buat role sesuai tugas (misalnya penyusun, penyetuju, auditor baca-saja). Sistem memeriksa **izin**, bukan nama role.

### 12.3 Kebijakan keamanan

*Akses → Kebijakan keamanan*:

- **Aksi yang mewajibkan 2FA.** Pengguna tanpa 2FA terpasang di akunnya ditolak melakukan aksi yang dicentang (misalnya menyetujui entri), dengan pesan yang jelas. Aktifkan 2FA di akun Anda lebih dulu.
- **Batas sesi idle** dalam menit.
- **Alamat IP klien integrasi**: satu alamat atau rentang CIDR per baris. Kosong berarti dari mana saja.

Setiap penyimpanan menjadi versi baru dan tercatat di audit.

### 12.4 Klien integrasi

*Akses → Klien integrasi* untuk sistem lain (mis. aplikasi penjualan) yang mengirim **peristiwa keuangan** lewat API.

1. **Daftarkan klien**: nama, *sistem sumber* (tidak bisa diubah, unik per perusahaan), **scope**, dan *jenis transaksi yang diizinkan*.
2. **Salin Client ID dan Client secret saat itu juga.** Secret hanya tampil sekali dan tidak bisa ditampilkan lagi.
3. **Rotasi secret** membatalkan token lama seketika. **Cabut klien** menghentikan akses.

Klien tidak bisa menyetujui entri. Peristiwa yang dikirim klien masuk ke alur entri dan persetujuan yang sama.

---

## 13. Pesan yang sering muncul

| Pesan atau kode | Penyebab | Yang dilakukan |
| --- | --- | --- |
| Periode ditutup / tidak menerima posting | Tanggal akuntansi di periode Ditutup atau Ditutup sementara | Pakai tanggal di periode terbuka, atau minta akuntan membuka kembali |
| `MAPPING_REQUIRED` | Peran akun atau akun kontrol belum dipetakan | Petakan di Pengaturan buku besar atau Profil posting |
| Kurs tidak ditemukan | Tidak ada kurs pada tanggal itu | Catat kurs di menu Kurs, atau isi manual bila jenis mengizinkan |
| `TWO_FACTOR_REQUIRED` | Aksi mewajibkan 2FA dan akun belum memasangnya | Aktifkan 2FA di akun Anda |
| Lines to the cash account … must be in its currency | Mata uang entri berbeda dari mata uang rekening kas/bank pada jenis yang memakai satu jumlah | Pakai jenis *… Rekening Beda Mata Uang*, atau rekening yang mata uangnya sama dengan entri (lihat 14) |
| Draf sudah diubah di tempat lain | Orang lain menyimpan lebih dulu | Data terbaru sudah dimuat; periksa lalu simpan lagi |
| Akun / pihak / rekening tidak valid | Dinonaktifkan atau tidak memenuhi syarat | Pilih yang lain atau aktifkan kembali |
| Tidak ada menu yang diharapkan | Izin belum diberikan | Hubungi administrator |

Pesan "diubah di tempat lain" muncul karena setiap data punya nomor versi; sistem tidak menimpa diam-diam perubahan orang lain.

---

## 14. Batasan yang perlu diketahui

- **Pembayaran ke rekening beda mata uang.** Jenis yang memakai satu jumlah untuk faktur dan rekening (*Terima Pembayaran Pelanggan*, *Bayar Pemasok*) menolak saat **Ajukan** bila mata uang entri berbeda dari mata uang rekening. Untuk kasus itu pakai *Terima Pembayaran, Rekening Beda Mata Uang* atau *Bayar Pemasok, Rekening Beda Mata Uang*: Anda mengisi dua jumlah, yang melunasi faktur dan yang masuk atau keluar di rekening. Kurs rekening diambil dari tabel kurs. Selisih antara keduanya masuk ke akun selisih kurs. Transfer antar rekening beda mata uang juga didukung.
- **Biaya tunai tanpa pihak** belum tersedia sebagai jenis siap pakai. Rute subledger mewajibkan pihak dan akun kas hanya menerima sumber Subledger. Catat lewat Tagihan Pemasok lalu Bayar Pemasok, atau minta akuntan merancang jenis yang sesuai.
- **Uang muka pelanggan** tercatat sebagai saldo kredit akun piutang (lihat 6.4).
- **Kalender fiskal** di wizard hanya bulanan. Pola lain dibuat lewat API.
- **2FA** diperiksa sebagai "terpasang di akun", bukan sebagai tantangan ulang per sesi.
- **Label dan kunci Jenis Transaksi** tidak bisa diubah setelah dibuat.
- **Sales order dan proses operasional lain** (persediaan, payroll, pajak, aset tetap) ada di sistem sumber, bukan di sini. Sistem ini mencatat dampak akuntansinya: faktur, pembayaran, dan jurnalnya.
- **Basis akrual** saja; satu mata uang fungsional per perusahaan.
- **Rekonsiliasi bank dari mutasi rekening**, webhook, dan konsolidasi grup belum tersedia.

---

## 15. Istilah

| Istilah | Arti |
| --- | --- |
| Ruang kerja | Ruang akses pengguna; satu ruang kerja berisi satu perusahaan |
| Entri | Catatan transaksi yang melewati draf, persetujuan, dan posting |
| Jurnal | Hasil posting entri; debit sama dengan kredit; tidak bisa diubah |
| Jenis Transaksi | Bentuk entri beserta aturan postingnya |
| Aturan posting | Cara isian entri disusun menjadi baris jurnal |
| Akun daun | Akun tanpa anak; satu-satunya yang menerima posting |
| Akun kontrol | Akun piutang atau utang yang saldonya dirinci oleh subledger |
| Subledger | Rincian piutang, utang, dan kas per pihak dan dokumen |
| Open item | Sisa tagihan atau utang suatu dokumen yang belum dilunasi |
| Alokasi | Pencocokan pembayaran atau uang muka dengan faktur |
| Uang muka | Pembayaran yang belum dialokasikan ke dokumen |
| Dimensi | Sudut analisis transaksi, mis. cabang atau proyek |
| Mata uang fungsional | Mata uang buku besar perusahaan |
| Revaluasi | Penilaian ulang saldo valas dengan kurs penutup |
| Cutover | Hari pertama pembukuan di sistem ini |
| Snapshot | Laporan terbit yang disimpan apa adanya |
| Waiver | Penerimaan selisih rekonsiliasi dengan alasan tercatat |
| Cleared | Transaksi kas yang sudah dikonfirmasi bank atau perhitungan kas |
