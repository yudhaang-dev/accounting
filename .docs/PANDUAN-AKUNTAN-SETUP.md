# Panduan Akuntan — Penyiapan Sistem

> Untuk akuntan atau administrator yang menyiapkan perusahaan sampai siap dipakai admin keuangan. Urutannya berupa daftar kerja dengan keputusan yang perlu Anda ambil di tiap langkah. Rincian layar dan kegiatan harian ada di [PANDUAN-PENGGUNA.md](PANDUAN-PENGGUNA.md); kegiatan harian admin keuangan ada di [PANDUAN-ADMIN-KEUANGAN.md](PANDUAN-ADMIN-KEUANGAN.md). Bukan nasihat akuntansi atau pajak: pemetaan akun, kebijakan, dan format laporan Anda tetapkan sendiri.

## Peta kerja

| # | Langkah | Menu | Wajib |
| --- | --- | --- | --- |
| 1 | Buat ruang kerja dan profil perusahaan | Daftar ruang kerja → Buat ruang kerja baru | Ya |
| 2 | Pasang bagan akun | Beranda → Lanjutkan penyiapan | Ya |
| 3 | Atur kalender fiskal | Beranda → Lanjutkan penyiapan | Ya |
| 4 | Aktifkan perusahaan | Beranda → Lanjutkan penyiapan | Ya |
| 5 | Atur atribut posting akun | Pembukuan → Akun | Ya |
| 6 | Petakan peran akun | Pembukuan → Pengaturan buku besar | Ya sebelum tutup tahun |
| 7 | Mata uang dan kurs | Data induk | Bila ada valas |
| 8 | Dimensi | Data induk → Dimensi | Bila perlu analisis |
| 9 | Profil posting dan pihak | Subledger | Bila ada piutang atau utang |
| 10 | Rekening kas dan bank | Subledger → Kas dan bank | Bila ada kas atau bank |
| 11 | Jenis Transaksi | Transaksi → Jenis transaksi | Ya |
| 12 | Kebijakan persetujuan | Transaksi → Kebijakan persetujuan | Ya |
| 13 | Anggota, role, kebijakan keamanan | Akses | Ya |
| 14 | Definisi laporan | Pelaporan → Laporan | Ya |
| 15 | Saldo konversi | Pembukuan → Saldo konversi | Bila pindah dari sistem lama |
| 16 | Uji coba dan serah terima | — | Ya |

Kerjakan berurutan. Beberapa langkah bergantung pada yang sebelumnya: jenis transaksi berpihak butuh profil posting yang sudah dipetakan, dan profil posting butuh akun yang sudah diatur hanya menerima sumber Subledger.

---

## 1–4. Perusahaan, bagan akun, kalender, aktivasi

Perusahaan baru berstatus **Penyiapan** dan belum bisa dipakai mencatat transaksi.

**Profil.** Isi nama, nama badan hukum, NPWP, zona waktu, bahasa dokumen, dan **mata uang fungsional**. Mata uang fungsional hanya bisa diubah selama penyiapan; setelah aktif tidak bisa diubah. Pastikan benar sebelum lanjut.

**Bagan akun.** Pilih template, lalu **tinjau daftar akunnya** satu per satu. Template hanya saran dan tidak ada akun yang dibuat sebelum Anda mengonfirmasi.

- Hapus centang akun opsional yang tidak dipakai (misalnya Bank Valas bila tidak ada valas).
- Nama dan kode bisa disesuaikan setelah dipasang, tetapi **kelas akun tidak bisa diubah** setelah dibuat. Cek kelas dan saldo normal sekarang.
- Pastikan struktur induk dan daun sesuai pelaporan Anda. Hanya akun daun yang menerima posting.

**Kalender fiskal.** Pilih bulan awal tahun fiskal (Januari bila sama dengan tahun kalender), lalu buat tahun fiskal pertama. Periode bulanan terbentuk otomatis tanpa celah. Wizard hanya membuat kalender bulanan; pola lain dibuat lewat API.

**Aktivasi.** Aktif setelah bagan akun dan kalender terpasang.

---

## 5. Atribut posting akun

Di **Pembukuan → Akun**, buka tiap akun yang perlu aturan khusus lalu atur **Pengaturan posting**:

| Atribut | Keputusan |
| --- | --- |
| Sumber posting yang diterima | Entri manual, Subledger, Sistem |
| Mata uang | Bebas, hanya fungsional, atau satu mata uang asing tertentu |
| Direvaluasi di akhir periode | Untuk pos moneter valas |
| Dimensi | Opsional, wajib, atau dilarang per tipe |

**Yang wajib Anda lakukan:**

- **Akun kontrol piutang dan utang: hanya sumber Subledger.** Tanpa ini, profil posting menolak akun tersebut.
- **Akun kas dan bank: hanya sumber Subledger.** Rekening kas dan bank hanya menerima akun seperti ini.
- Template COA belum membatasi sumber ini, jadi aturlah sendiri.
- Akun biaya atau pendapatan yang harus selalu dianalisis per proyek atau cabang: set dimensi **Wajib**. Entri tanpa dimensi akan ditolak saat diajukan.

---

## 6. Pengaturan buku besar

**Pembukuan → Pengaturan buku besar** punya dua bagian.

### Peran akun

Peran menentukan akun tujuan jurnal otomatis. Simpan tiap peran setelah memilih akun.

| Peran | Dipakai untuk | Wajib |
| --- | --- | --- |
| Saldo laba | Tujuan laba atau rugi saat tutup tahun | Sebelum tutup tahun |
| Laba/rugi selisih kurs terealisasi | Selisih saat valas dilunasi | Bila ada valas |
| Laba/rugi selisih kurs belum terealisasi | Hasil revaluasi | Bila ada valas |
| Pembulatan konversi kurs | Selisih kecil karena pembulatan | Bila ada valas |

Akun laba selisih kurs harus berkelas pendapatan dan rugi selisih kurs berkelas beban. Peran bertanda *Akun tidak lagi valid* berarti akunnya dinonaktifkan; pilih akun lain. Setiap perubahan menjadi versi baru dan jurnal lama tetap memakai versi lamanya.

### Kebijakan buku besar

Berlaku untuk seluruh posting, dan setiap penyimpanan menjadi versi baru:

- **Periode tutup sementara.** Tentukan jenis transaksi yang boleh memposting ke periode ini, dan hak tambahan bila perlu. Kosong berarti periode tutup sementara menolak semua posting. Penutupan tahun dan revaluasi memakai kunci sistem; bila periode terakhir akan ditutup sementara saat tutup tahun, izinkan kunci `system.closing`.
- **Posting ke akun kontrol dari sumber lain.** Isi hak yang diizinkan, atau kosongkan agar tidak ada yang boleh. Penggunaannya wajib beralasan dan tercatat di audit.
- **Toleransi pembulatan konversi kurs.** Selisih terbesar (dalam mata uang fungsional) yang dibukukan otomatis ke akun pembulatan. Isi 0 bila tidak ada toleransi.
- **Jenis kurs penutup revaluasi** dan **balik revaluasi otomatis** di awal periode berikutnya.

---

## 7. Mata uang dan kurs

Lewati bagian ini bila perusahaan tidak punya transaksi valas.

1. **Mata uang.** Aktifkan mata uang asing yang dipakai. Atur **skala** (angka desimal) dan **pembulatan** (ke atas bila setengah, ke genap bila setengah, selalu ke atas, selalu ke bawah). ISO menyarankan skala per mata uang.
2. **Jenis kurs.** Buat lebih dulu, misalnya kurs tengah bank sentral atau kurs pajak. Tanpa jenis kurs, kurs tidak bisa dicatat.
3. **Kurs.** Catat satu per satu atau impor CSV. Koreksi menambah versi baru tanpa menimpa. Sistem **tidak memakai kurs tanggal lain** secara diam-diam: tanpa kurs pada tanggal transaksi, entri valas ditolak.
4. Pastikan peran selisih kurs sudah dipetakan (bagian 6) dan akun valas bertanda direvaluasi (bagian 5).

---

## 8. Dimensi

Dimensi adalah sudut analisis, misalnya cabang atau proyek.

- Buat **tipe** (kunci huruf kecil, angka, garis bawah; tidak bisa diubah), lalu **nilainya**. Centang **Bertingkat** bila nilai punya induk, agar laporan bisa dirangkum ke atas.
- Dimensi dipakai di baris entri, filter laporan, dan **cakupan data anggota** (bagian 13).
- Nilai yang sudah dipakai dinonaktifkan, bukan dihapus.
- Kaitkan dengan kewajiban dimensi per akun (bagian 5).

Keputusan yang perlu diambil lebih dulu: dimensi apa saja yang benar-benar dipakai laporan. Dimensi yang terlalu banyak membebani admin keuangan saat input.

---

## 9. Profil posting dan pihak

**Profil posting** (Subledger → Profil posting) menentukan akun kontrol piutang dan utang bagi pihak yang memakainya.

1. **Tambah profil.** Profil pertama menjadi default dan melayani pihak tanpa profil.
2. Pilih akun untuk **Akun kontrol piutang** dan **Akun kontrol utang**, lalu simpan. Akun harus daun aktif: aset untuk piutang, liabilitas untuk utang, dan hanya menerima sumber Subledger.
3. Buat profil tambahan bila ada kelompok pihak dengan akun berbeda (misalnya pihak berelasi atau valas).

Pemetaan berversi. **Tanpa pemetaan, entri berpihak ditolak dengan `MAPPING_REQUIRED`.**

**Pihak** (Subledger → Pihak): tambahkan pelanggan dan pemasok awal. Pihak dengan **referensi eksternal** per sistem sumber dikenali oleh sistem lain; referensi yang sudah ditambahkan tidak bisa dihapus. Untuk jumlah besar, minta pengembang mengimpor lewat API.

---

## 10. Rekening kas dan bank

Subledger → Kas dan bank → **Tambah rekening**. Tiap rekening punya satu mata uang dan satu akun buku besar.

Syarat akun: aset daun aktif, hanya menerima sumber Subledger, menerima mata uang rekening, dan belum dipakai rekening lain. **Jenis, mata uang, dan akun buku besar tidak bisa diubah setelah dibuat.** Buat satu akun GL per rekening.

---

## 11. Jenis Transaksi

Inilah inti kemudahan bagi admin keuangan: admin mengisi istilah bisnis, sementara Anda mengunci debit, kredit, dan akunnya.

### Mulai dari template

Transaksi → Jenis transaksi → **Impor template**. Tinjau tiap jenis, pilih yang dipakai, centang konfirmasi, lalu impor.

| Template | Jenis |
| --- | --- |
| Jenis Transaksi umum | Jurnal Umum, Jurnal Penyesuaian, Ringkasan Payroll, Penjualan Kredit |
| Transaksi harian | Terima Pembayaran Pelanggan, Tagihan Pemasok, Bayar Pemasok, Transfer Antar Kas atau Bank, Terima Pembayaran dan Bayar Pemasok untuk rekening beda mata uang |

Template tidak mengenal COA Anda, jadi akun biaya, pendapatan, dan payroll berupa **field akun** yang diisi pengguna. **Persempit sebelum dipakai admin:** buka perancang, buat versi baru, dan pada field akun isi *Akun yang boleh dipilih* dengan akun yang relevan saja (misalnya hanya akun pendapatan). Atau ganti field akun dengan **akun tetap** di aturan posting bila akunnya selalu sama.

### Merancang jenis sendiri

Perancang (Buka perancang) punya tiga bagian:

1. **Field.** Nama, tipe, label, wajib. Tipe: Nominal, Tanggal, Teks, Referensi, Akun, Pihak, Rekening kas/bank, Dimensi, Alokasi.
2. **Aturan posting.** Sisi, field nominal, dan sumber akun: *akun tetap*, *akun dari field*, *akun kontrol pihak*, atau *akun rekening kas*. Aturan *Selisih kurs* menutup sisa agar seimbang di mata uang fungsional.
3. **Rute subledger.** Menentukan dokumen yang terbentuk (Faktur, Nota kredit, Pembayaran, Penghapusan, Transfer), akun kontrol, field pihak, nomor dokumen, jatuh tempo, rekening kas, dan field alokasi.

Menerbitkan versi memeriksa kelengkapan; pesannya menyebut yang kurang.

**Pedoman agar ramah admin:**

- Satu jenis per kejadian bisnis. Hindari jenis serbaguna.
- Beri label dengan istilah admin, bukan istilah akuntansi: "Jumlah diterima", bukan "Debit kas".
- Kunci sisi lawan sebanyak mungkin. Pengguna hanya memilih yang memang berbeda tiap transaksi.
- Gunakan field *Pihak* dan *Rekening kas/bank* supaya akun piutang, utang, dan kas ditentukan sistem.
- Jenis baris bebas (Jurnal Umum, Penyesuaian, revaluasi, tutup buku) tetap untuk akuntan. Batasi lewat izin dan kebijakan persetujuan.

### Versi dan penomoran

- **Versi terbit tidak bisa diubah.** Perubahan = **Versi baru** → edit draf → **Terbitkan**. Entri lama tetap memakai versinya.
- **Label dan kunci jenis tidak bisa diubah.** Periksa ejaan sebelum membuat.
- **Atur penomoran** per jenis: pola (`JU/{yyyy}/{mm}/{seq}`), lebar urutan, reset (tidak pernah, tahunan, bulanan, harian), dan kapan nomor diberikan. Nomor saat *diajukan* menjaga urutan bebas dari draf yang batal.

### Hal valas yang perlu Anda sampaikan ke admin

Jenis pembayaran yang memakai **satu jumlah** untuk faktur dan rekening ditolak saat diajukan bila mata uang entri berbeda dari mata uang rekening. Untuk kasus itu sediakan jenis **… Rekening Beda Mata Uang** dari template *Transaksi harian*. Aturannya: jumlah kas punya field sendiri (tidak dipakai aturan lain), baris kas mengikuti mata uang rekening dengan kurs dari tabel kurs, baris kontrol memakai mata uang entri, dan satu aturan *Selisih kurs* menutup sisanya ke akun peran selisih kurs. Pastikan kurs rekening ada di tabel kurs pada tanggal transaksi dan peran selisih kurs sudah dipetakan. Admin mengisi dua jumlah: yang melunasi faktur dan yang diterima atau dibayar di rekening. Transfer antar rekening beda mata uang juga didukung.

---

## 12. Kebijakan persetujuan

Transaksi → Kebijakan persetujuan. Setiap penyimpanan membuat versi baru yang tercatat di audit.

- **Pemisahan tugas:** centang larangan menyetujui entri sendiri. Klien integrasi tidak pernah dapat menyetujui.
- **Aturan:** berdasarkan jenis transaksi, sumber (Manual, Integrasi, Berulang), dan nominal minimum. **Aturan pertama dari atas yang cocok yang berlaku**, jadi taruh yang paling spesifik di atas.
- **Tahap:** tiap tahap meminta satu izin. Aturan tanpa tahap berarti disetujui otomatis.
- **Posting otomatis** setelah tahap terakhir menyetujui. Pertimbangkan hanya untuk jenis berisiko rendah.
- Entri yang tidak cocok aturan mana pun butuh satu persetujuan dari pemegang izin menyetujui entri.

Nominal dibandingkan dengan total debit dalam mata uang fungsional.

---

## 13. Anggota, role, dan keamanan

**Role** (Akses → Role): kumpulan izin; perubahan langsung berlaku bagi semua pemegangnya. Sistem memeriksa izin, bukan nama role. Rancang role per tugas, misalnya:

| Contoh role | Isi izin pokok |
| --- | --- |
| Admin keuangan | Membuat dan mengajukan entri, melihat laporan, mengelola pihak dan kas |
| Penyetuju | Menyetujui dan memposting entri |
| Akuntan | Pengaturan buku besar, tutup periode, rekonsiliasi |
| Auditor | Baca-saja, dengan masa berlaku |

Untuk perusahaan yang sudah ada, izin baru (mis. menerbitkan snapshot laporan) perlu **ditambahkan manual** ke role yang relevan.

**Anggota** (Akses → Anggota): undang lewat email, pilih role, tentukan masa berlaku (akses berhenti di akhir hari itu), dan atur **cakupan data** per nilai dimensi beserta turunannya. Pengguna dengan cakupan terbatas tidak bisa menerbitkan atau membuka isi snapshot.

**Kebijakan keamanan** (Akses → Kebijakan keamanan):

- Pilih aksi yang mewajibkan 2FA (misalnya menyetujui entri). Pengguna tanpa 2FA terpasang ditolak. Minta penyetuju mengaktifkan 2FA lebih dulu. 2FA diperiksa sebagai terpasang atau tidak, bukan sebagai tantangan ulang per sesi.
- Batas sesi idle.
- Daftar IP klien integrasi.

**Klien integrasi** (Akses → Klien integrasi): daftarkan sistem lain (mis. aplikasi penjualan), tentukan scope dan jenis transaksi yang diizinkan, lalu simpan Client secret saat itu juga karena tidak akan tampil lagi.

---

## 14. Definisi laporan

Pelaporan → Laporan.

1. **Impor template definisi** (laba rugi, neraca, arus kas tidak langsung), tinjau, dan konfirmasi. Bila ada kode akun template yang tidak ada di bagan akun Anda, sistem menyebutnya.
2. Sesuaikan baris, kolom, dan kumpulan akun di **editor definisi**. Perubahan struktur menjadi versi baru; snapshot lama tidak berubah.
3. Bagian OCI belum ada di template: laba rugi hanya menjumlahkan akun pendapatan dan beban.
4. **Pemetaan kas dan setara kas** diperlukan untuk arus kas tidak langsung. **Klasifikasi mutasi** diperlukan untuk arus kas langsung dan perubahan ekuitas.
5. Pastikan tidak ada akun yang *belum dipetakan* di hasil laporan.
6. Atur **penerbitan otomatis** snapshot saat periode atau tahun ditutup, bila diperlukan.

Cek kewajaran: neraca harus seimbang (aset = liabilitas + ekuitas) dan arus kas harus cocok dengan kas.

---

## 15. Saldo konversi (bila pindah dari sistem lama)

Pembukuan → Saldo konversi. Impor diposting sebagai jurnal sistem dan **hanya dilakukan sekali**.

Sebelum mulai, siapkan:

- Neraca saldo awal per tanggal mulai: CSV `source_code`, `debit`, `credit`. Pakai titik untuk desimal. Bila mulai di tengah tahun buku, sertakan saldo pendapatan dan beban tahun berjalan.
- Bila ada akun kontrol piutang atau utang: **open item awal** (CSV `control_role`, `party`, `kind`, `document_number`, `document_date`, `amount`; opsional `due_date`, `currency`, `exchange_rate`). Total per akun kontrol harus sama dengan saldonya.
- Pemetaan kode sistem lama ke akun, dan ke pihak.
- Tanggal **cutover** dan **awal histori** (hari pertama sebuah periode).

Saldo yang tidak seimbang ditolak. Wizard belum memetakan dimensi. Setelah impor, bandingkan neraca saldo dengan neraca saldo sistem lama, dan jalankan **Rekonsiliasi** supaya akun kontrol cocok dengan open item.

---

## 16. Uji coba dan serah terima

### Daftar periksa sebelum admin keuangan mulai

- [ ] Perusahaan berstatus **Aktif**; mata uang fungsional benar.
- [ ] Kelas dan saldo normal akun benar; akun kontrol dan kas hanya menerima sumber Subledger.
- [ ] Peran akun dipetakan (saldo laba; selisih kurs bila ada valas).
- [ ] Kurs ada untuk tanggal yang akan dipakai (bila valas).
- [ ] Profil posting dipetakan; pelanggan, pemasok, dan rekening sudah dibuat.
- [ ] Jenis Transaksi untuk kejadian harian diterbitkan, field akunnya sudah dipersempit.
- [ ] Kebijakan persetujuan sesuai prosedur internal; role penyetuju punya 2FA bila diwajibkan.
- [ ] Anggota, role, dan kebijakan keamanan sudah diatur.
- [ ] Definisi laporan tersedia, dan tidak ada akun belum dipetakan.
- [ ] Saldo konversi diimpor dan cocok dengan sistem lama (bila ada).

### Uji alur

Gunakan data uji, atau periode yang akan dibatalkan:

1. **Penjualan kredit** → posting. Periksa jurnal: piutang debit, pendapatan kredit. Periksa dokumen dan open item muncul.
2. **Terima pembayaran** dengan alokasi ke faktur → faktur berstatus *Lunas*.
3. **Terima pembayaran** tanpa alokasi → muncul sebagai uang muka (open item pembayaran terbuka).
4. **Tagihan pemasok** lalu **Bayar pemasok**.
5. **Transfer antar kas**.
6. **Tolak** dan **Revisi** satu entri; **balik** satu jurnal.
7. Entri valas pada tanggal tanpa kurs → harus ditolak; dengan kurs → diterima.
8. Jalankan **Neraca saldo** (harus seimbang), **Buku besar**, laporan keuangan, dan **Umur piutang/utang**.
9. Coba entri sebagai pengguna tanpa izin → harus ditolak.

> **Uang muka pelanggan.** Pembayaran lewat jenis penerimaan mencatat kas di debit dan akun kontrol piutang di kredit, sehingga uang muka berupa saldo kredit pada akun piutang. Putuskan apakah penyajian neraca cukup, atau Anda perlu jenis tersendiri yang mencatat uang muka ke akun kewajiban dan menerapkannya manual ke faktur.

> **Biaya tunai tanpa pihak** belum tersedia sebagai jenis siap pakai, karena rute subledger mewajibkan pihak dan akun kas hanya menerima sumber Subledger. Sediakan prosedur pengganti bagi admin.

### Setelah berjalan

Rutinitas akhir periode (revaluasi, rekonsiliasi, tutup periode, tutup tahun buku) ada di [PANDUAN-PENGGUNA.md](PANDUAN-PENGGUNA.md), bagian 8. Ubah konfigurasi lewat versi baru, bukan menimpa. Perubahan tercatat di audit.
