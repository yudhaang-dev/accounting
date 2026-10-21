# Panduan Admin Keuangan — Kegiatan Harian

> Untuk admin keuangan yang mencatat transaksi sehari-hari. Anda tidak perlu paham debit dan kredit: pilih jenis transaksi, isi data, dan sistem menyusun jurnalnya. Penyiapan sistem dilakukan akuntan ([PANDUAN-AKUNTAN-SETUP.md](PANDUAN-AKUNTAN-SETUP.md)); rincian lengkap semua layar ada di [PANDUAN-PENGGUNA.md](PANDUAN-PENGGUNA.md).

## Yang perlu diingat

- **Yang sudah diposting tidak bisa diubah atau dihapus.** Bila salah, jurnal dibalik dan dibuat ulang (lihat bagian 7). Draf boleh diubah.
- **Menu yang tampil mengikuti izin Anda.** Menu yang tidak ada berarti Anda belum diberi izinnya. Hubungi administrator.
- **Tanggal akuntansi menentukan periode.** Bila periodenya sudah ditutup, entri ditolak.
- Format angka di layar: `1.250.000,50`.

---

## 1. Masuk

1. Isi email dan kata sandi, lalu klik **Masuk**. Atau klik **Masuk dengan passkey**.
2. Bila akun memakai 2FA, masukkan 6 digit kode dari aplikasi autentikator. Kehilangan ponsel: pilih **Gunakan kode pemulihan**.
3. Pilih **ruang kerja** perusahaan Anda. Bila hanya satu, langsung terbuka. Untuk berpindah, klik pemilih ruang kerja di kiri atas.

Lupa kata sandi: klik **Lupa kata sandi?** dan buka tautan di email (cek folder spam). Sesi berakhir otomatis bila lama tidak aktif; masuk lagi.

---

## 2. Alur kerja sehari-hari

Setiap transaksi dicatat sebagai **entri** di **Transaksi → Entri**. Statusnya berjalan seperti ini:

```
Draf → Menunggu persetujuan → Disetujui → Diposting
            ↓ Ditolak → Revisi → (kembali ke Draf)
```

| Status | Arti | Yang bisa Anda lakukan |
| --- | --- | --- |
| Draf | Masih bisa diubah | Ubah, **Ajukan**, Batalkan |
| Menunggu persetujuan | Menunggu penyetuju | Menunggu |
| Disetujui | Siap diposting | **Posting** (bila perusahaan tidak memposting otomatis) |
| Diposting | Sudah masuk pembukuan | Balik jurnal, buat entri pengganti |
| Ditolak | Dikembalikan dengan alasan | **Revisi**, perbaiki, ajukan lagi |
| Dibatalkan | Tidak dipakai | Hanya bisa dibaca |

Langkah umum untuk semua transaksi:

1. Klik **Entri baru** dan pilih **Jenis transaksi**.
2. Isi **Tanggal akuntansi**, **Keterangan**, dan isian jenis itu.
3. Klik **Simpan draf**.
4. Di halaman detail, klik **Ajukan**.
5. Penyetuju menyetujui; lalu **Posting** (atau otomatis).
6. Setelah diposting, nomor jurnal muncul di detail. Klik untuk membukanya.

Pemisahan tugas biasanya berlaku: Anda tidak bisa menyetujui entri buatan sendiri.

---

## 3. Mencatat transaksi

Nama jenis bisa berbeda sedikit di perusahaan Anda, karena akuntan yang mengaturnya. Tanyakan akuntan bila jenis yang Anda butuhkan tidak ada.

### Penjualan kredit

Barang atau jasa sudah diserahkan dan faktur terbit, belum dibayar.

- **Jenis:** Penjualan Kredit.
- **Isi:** Pelanggan, nomor faktur, jatuh tempo, total faktur, akun pendapatan.
- **Hasil:** piutang pelanggan bertambah dan pendapatan tercatat. Muncul sebagai dokumen *Faktur* dan open item di **Subledger**.

> Sales order atau pesanan tidak dicatat di sini. Piutang diakui saat faktur terbit, bukan saat pesanan masuk.

### Terima pembayaran pelanggan

- **Jenis:** Terima Pembayaran Pelanggan.
- **Isi:** Pelanggan, rekening tempat uang masuk, nomor bukti, jumlah diterima, dan **Faktur yang dilunasi**.
- Pada *Faktur yang dilunasi*, pilih pelanggan lebih dulu, lalu pilih faktur dan nominalnya. Boleh sebagian dan boleh beberapa faktur.

**Hasil pelunasan:**

| Situasi | Hasil |
| --- | --- |
| Jumlah sama dengan faktur | Faktur berstatus *Lunas* |
| Lebih kecil | Faktur menyisakan sisa tagihan |
| Lebih besar, atau tanpa alokasi | Selisihnya menjadi **uang muka** yang bisa dialokasikan kemudian |

### Uang muka dari pelanggan (DP)

Bila pelanggan membayar sebelum ada faktur, catat sebagai **Terima Pembayaran Pelanggan tanpa mengisi *Faktur yang dilunasi***. Jumlahnya tersimpan sebagai uang muka. Saat faktur terbit, terapkan uang muka itu (lihat bagian 4). Uang muka bukan penjualan; pendapatan baru muncul dari faktur.

### Tagihan dari pemasok

- **Jenis:** Tagihan Pemasok.
- **Isi:** Pemasok, nomor tagihan, jatuh tempo, total tagihan, akun biaya atau persediaan.
- **Hasil:** utang ke pemasok bertambah.

### Bayar pemasok

- **Jenis:** Bayar Pemasok.
- **Isi:** Pemasok, rekening sumber dana, nomor bukti, jumlah dibayar, dan **Tagihan yang dilunasi**.
- Cara memilih tagihan sama seperti pelunasan faktur.

### Transfer antar kas atau bank

- **Jenis:** Transfer Antar Kas atau Bank.
- **Isi:** Dari rekening, ke rekening, jumlah keluar, jumlah masuk. Untuk rekening dengan mata uang yang sama, kedua jumlahnya sama.

### Jurnal umum atau penyesuaian

Jenis *baris bebas* meminta Anda memilih akun dan mengisi debit atau kredit sendiri. **Biasanya ini tugas akuntan.** Bila Anda diminta, pastikan penanda **Seimbang** muncul sebelum menyimpan.

### Transaksi valas

Bila jenis mengizinkan, isian **Mata uang** dan **Kurs** tampil. Kosongkan kurs untuk memakai tabel kurs yang dicatat akuntan. Bila kurs tanggal itu belum ada, entri ditolak: minta akuntan mencatat kursnya.

**Rekening beda mata uang.** Bila faktur dan rekening berbeda mata uang (misalnya faktur USD dibayar ke rekening rupiah), pakai **Terima Pembayaran, Rekening Beda Mata Uang** atau **Bayar Pemasok, Rekening Beda Mata Uang**. Pilih **Mata uang** entri sama dengan mata uang faktur, lalu isi dua jumlah: *Jumlah faktur yang dilunasi* (dalam mata uang faktur) dan *Jumlah diterima* atau *dibayar* (dalam mata uang rekening, sesuai mutasi bank). Kurs diambil dari tabel kurs; bila kursnya belum ada, entri ditolak. Selisih antara keduanya dicatat sebagai selisih kurs. Jenis **Terima Pembayaran Pelanggan** dan **Bayar Pemasok** biasa menolak saat **Ajukan** bila mata uangnya tidak sama dengan rekening.

### Lampiran dan keterangan

Isi **Keterangan** yang jelas (nomor kontrak, nama proyek), dan lampirkan berkas pendukung di halaman detail entri. Keduanya memudahkan penelusuran dan audit.

---

## 4. Memantau piutang, utang, dan kas

Semua di menu kelompok **Subledger**.

| Menu | Gunanya |
| --- | --- |
| **Open item** | Sisa tagihan atau utang tiap dokumen: Terbuka, Lunas, atau Dibalik. Hanya dibaca |
| **Dokumen** | Faktur, nota kredit, pembayaran, uang muka, penghapusan, beserta jurnalnya. Hanya dibaca |
| **Alokasi** | Pencocokan pembayaran atau uang muka dengan faktur |
| **Transaksi kas** | Penerimaan dan pengeluaran per rekening |
| **Umur piutang/utang** | Tagihan menurut umur: belum jatuh tempo, 1–30 hari, dan seterusnya |

### Menerapkan uang muka ke faktur

1. Buka **Subledger → Alokasi → Alokasi baru**.
2. Pilih **Pihak**.
3. Pilih **Item debit** (faktur yang akan dilunasi) dan **Item kredit** (uang muka). Hanya open item pihak itu yang muncul.
4. Isi **Jumlah** (maksimum sisa terkecil dari kedua item) dan **Tanggal alokasi**.
5. Klik **Alokasikan**.

### Membatalkan alokasi

Klik **Batalkan alokasi** pada baris alokasi, isi alasan. Item yang dilunasi terbuka lagi. Alasan tercatat.

### Menandai transaksi kas sebagai cleared

Setelah bank mengonfirmasi (atau kas sudah dihitung): **Subledger → Transaksi kas**, klik **Tandai cleared** dan pilih tanggalnya. Tidak boleh di masa depan atau sebelum tanggal transaksi. **Batalkan tanda cleared** bila keliru.

### Laporan umur piutang dan utang

**Pelaporan → Umur piutang/utang**: pilih Piutang atau Utang, tanggal, pihak, dan dasar umur (jatuh tempo atau tanggal dokumen), lalu **Tampilkan**. Klik **Ekspor CSV** untuk mengunduh. Dipakai untuk menagih pelanggan yang menunggak atau menjadwalkan pembayaran ke pemasok.

---

## 5. Menyetujui entri (bila Anda penyetuju)

1. Buka **Transaksi → Kotak persetujuan**.
2. Pilih **Menunggu saya** untuk entri yang bisa Anda setujui, atau **Semua yang menunggu**.
3. Klik nomor entri, periksa jenis, tanggal, jumlah, pihak, dan lampiran.
4. Klik **Setujui**, atau **Tolak** dengan **alasan** (wajib). Alasan terlihat oleh pembuat entri.

Entri bertahap menampilkan *Tahap 1 dari 2* dan seterusnya. Anda hanya melihat entri yang bisa Anda setujui pada tahap berikutnya.

---

## 6. Mencari dan membaca data

- **Daftar entri.** Filter menurut status, rentang tanggal, dan pembalikan terjadwal. Klik nomor entri untuk melihat baris jurnal, riwayat keputusan (siapa menyetujui atau menolak, dengan alasan), isian, dan lampiran.
- **Jurnal.** Pembukuan → Jurnal untuk melihat jurnal yang sudah diposting, terbaru dulu. Detail jurnal menautkan ke entri asalnya.
- **Neraca saldo.** Pelaporan → Neraca saldo: saldo awal, mutasi, dan saldo akhir tiap akun pada rentang tanggal. Klik kode akun untuk melihat rinciannya.
- **Buku besar.** Pelaporan → Buku besar: satu akun, dengan saldo berjalan dan tautan ke jurnal. Pilih akunnya dulu.
- **Laporan keuangan.** Pelaporan → Laporan: pilih laporan (laba rugi, neraca, arus kas), atur tanggal, lalu jalankan. Klik baris untuk menelusuri sampai jurnal.
- **Ekspor.** Tombol **Ekspor CSV** tersedia di neraca saldo, buku besar, umur piutang, dan laporan.

Bila akses Anda dibatasi pada cabang atau proyek tertentu, laporan hanya memuat data itu dan ditandai *Dibatasi cakupan data Anda*. Totalnya bisa lebih kecil dari total perusahaan; itu wajar.

---

## 7. Memperbaiki kesalahan

| Situasi | Cara |
| --- | --- |
| Entri masih **Draf** | Klik **Ubah**, perbaiki, simpan |
| Entri **Ditolak** | Klik **Revisi**, perbaiki sesuai alasan, ajukan lagi |
| Entri **tidak jadi dipakai** (belum diposting) | Klik **Batalkan** (alasan boleh kosong) |
| Entri sudah **Diposting**, salah total | **Balik jurnal**, lalu buat entri baru yang benar |
| Entri sudah **Diposting**, perlu diganti | **Buat entri pengganti**: draf baru yang disalin dari entri lama |
| Faktur atau pembayaran dari subledger salah | **Balik dokumen** di detail dokumen |
| Alokasi salah | **Batalkan alokasi** (Subledger → Alokasi) |

**Balik jurnal:**

1. Buka entri atau jurnal, klik **Balik jurnal**.
2. Isi **tanggal pembalikan** (periodenya harus masih terbuka) dan **alasan**.
3. Sistem membuat **draf** entri pembalikan. Ajukan, setujui, dan posting seperti entri biasa.
4. Pembalikan selalu penuh dan hanya sekali. Entri lama tetap ada sebagai jejak.

**Balik dokumen** ditolak bila dokumen masih punya alokasi aktif. Batalkan alokasinya dulu, lalu balik dokumennya. Entri pengganti tidak membalik entri lama otomatis; balik jurnalnya terpisah bila perlu.

**Balik otomatis.** Beberapa entri (misalnya penyesuaian akhir bulan) punya isian *Balik otomatis pada*. Setelah diposting, sistem membaliknya pada tanggal itu. Bila gagal (misalnya periode sudah ditutup), sistem tidak mencoba lagi dan entri menampilkan peringatan; filter *Pembalikan terjadwal* di daftar entri menunjukkan yang gagal. Laporkan ke akuntan.

---

## 8. Jurnal berulang

**Transaksi → Jurnal berulang** menampung jadwal untuk transaksi yang sama tiap periode (sewa, penyusutan, dan sejenisnya), dengan nilai tetap. Entri yang dibuat jadwal melewati validasi dan persetujuan seperti entri lain. Anda bisa membuat jadwal baru, mengubah, atau melewati satu kejadian. Kegagalan ditampilkan di daftar; cek rutin di awal bulan.

---

## 9. Rutinitas bulanan dari sisi admin

Sebelum akuntan menutup periode, bantu pastikan:

- [ ] Semua transaksi bulan itu sudah dicatat dan **tidak ada entri Draf, Menunggu persetujuan, Disetujui, atau Ditolak** yang tertinggal. Filter daftar entri menurut status dan tanggal.
- [ ] Penerimaan dan pembayaran sudah dialokasikan ke faktur atau tagihannya, bukan menggantung sebagai uang muka tanpa sebab.
- [ ] Transaksi kas yang sudah dikonfirmasi bank sudah **ditandai cleared**.
- [ ] Neraca saldo dan **Umur piutang/utang** dilihat kewajarannya.
- [ ] Pembalikan terjadwal yang gagal sudah dilaporkan.
- [ ] Kurs penutup bulan itu sudah diminta dicatat (bila ada transaksi valas).

Penutupan periode, revaluasi, rekonsiliasi, dan tutup tahun dilakukan akuntan.

---

## 10. Bila ada kendala

| Pesan atau gejala | Penyebab | Yang dilakukan |
| --- | --- | --- |
| Periode ditutup / tidak menerima posting | Tanggal akuntansi di periode yang sudah ditutup | Pakai tanggal di periode terbuka, atau minta akuntan membuka kembali |
| `MAPPING_REQUIRED` | Pemetaan akun belum diatur | Hubungi akuntan |
| Kurs tidak ditemukan | Tidak ada kurs pada tanggal itu | Minta akuntan mencatat kurs |
| Lines to the cash account … must be in its currency | Mata uang entri berbeda dari mata uang rekening pada jenis yang memakai satu jumlah | Pakai jenis *… Rekening Beda Mata Uang*, atau rekening yang mata uangnya sama dengan fakturnya |
| `TWO_FACTOR_REQUIRED` | Aksi ini mewajibkan 2FA di akun Anda | Aktifkan 2FA di akun Anda |
| Draf sudah diubah di tempat lain | Orang lain menyimpan lebih dulu | Data terbaru sudah dimuat; periksa lalu simpan lagi |
| Akun, pihak, atau rekening tidak valid | Sudah dinonaktifkan | Pilih yang lain atau minta akuntan mengaktifkannya |
| Pihak tidak ada di daftar | Belum dibuat | Subledger → Pihak → Tambah pihak (bila Anda berizin) atau minta akuntan |
| Menu atau tombol tidak ada | Izin belum diberikan | Hubungi administrator |
| Jenis transaksi tidak ada | Belum diterbitkan | Hubungi akuntan |
| Entri valas ditolak saat Ajukan | Kurs tidak ada, atau mata uang beda dari rekening | Lihat bagian 3, *Transaksi valas* |

Bila meminta bantuan, sebutkan **nomor entri** dan **pesan yang tampil**. Keduanya mempercepat penelusuran.

---

## Istilah

| Istilah | Arti |
| --- | --- |
| Entri | Catatan satu transaksi sebelum dan sesudah diposting |
| Posting | Mengubah entri menjadi jurnal di buku besar |
| Jurnal | Hasil posting; tidak bisa diubah |
| Jenis Transaksi | Bentuk isian dan aturan pencatatan suatu transaksi |
| Pihak | Pelanggan atau pemasok |
| Faktur | Tagihan ke pelanggan (atau dari pemasok) yang menimbulkan piutang atau utang |
| Open item | Sisa tagihan atau utang suatu dokumen yang belum lunas |
| Alokasi | Pencocokan pembayaran atau uang muka dengan faktur |
| Uang muka | Pembayaran yang belum dialokasikan ke faktur |
| Cleared | Transaksi kas yang sudah dikonfirmasi bank atau perhitungan kas |
| Periode | Bulan pembukuan; bisa Terbuka, Ditutup sementara, atau Ditutup |
| Balik jurnal | Koreksi dengan jurnal lawan, bukan mengubah jurnal lama |
