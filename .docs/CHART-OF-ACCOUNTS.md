# Template Bagan Akun — Perusahaan Barang & Jasa

> **Jenis dokumen:** referensi domain akuntansi (template COA dan saran pemetaan). Kebutuhan bisnis normatif ada di [PRODUCT.md](PRODUCT.md); kepemilikan pemetaan per modul ada di [DOMAIN-DEPENDENCIES.md](DOMAIN-DEPENDENCIES.md). Terapkan per perusahaan (`company_id`); kode akun yang sama di perusahaan lain tetap akun berbeda dan dapat dipetakan ke akun grup kelak. Ini contoh bagan akun yang dapat dikonfigurasi, bukan daftar akun wajib atau pernyataan kepatuhan SAK. Akuntan perusahaan harus menyesuaikan akun dan pemetaan dengan kegiatan usaha, kebijakan, materialitas, serta standar yang berlaku.

## Konvensi

- Kode 4 digit; rentang awal: `1xxx` aset, `2xxx` liabilitas, `3xxx` ekuitas, `4xxx` pendapatan, `5xxx` biaya langsung/HPP, `6xxx` beban operasi, `7xxx` penghasilan/beban lain dan pajak. Sisakan kode kosong untuk ekspansi; jangan mengubah arti kode yang sudah dipakai.
- **Induk** tidak dapat menerima posting. Hanya akun daun yang dapat dipilih di jurnal. Saldo normal: **D** = debit, **K** = kredit.
- **Kontrol** berarti saldo akun harus didukung rincian transaksi dan direkonsiliasi. Untuk piutang, utang, kas, dan bank yang memiliki Subledger pada MVP, posting dilakukan melalui Subledger; rincian persediaan/pajak berada di sistem sumber dan dapat dikirim sebagai nilai melalui Aturan Posting. Jurnal manual ke akun kontrol internal dibatasi dan perlu hak khusus. **Opsional** diaktifkan sesuai model bisnis. **Sistem** dihitung/dibentuk aplikasi dan tidak dapat diposting langsung.
- Dimensi sesuai `PRODUCT.md`: template mensyaratkan `department` pada akun laba rugi; perusahaan dapat mengatur kewajiban per akun. `project` wajib untuk transaksi proyek; transaksi bisnis umum boleh tanpa proyek. Baris neraca hanya diberi dimensi jika relevan.
- Template menyediakan akun persediaan dan HPP untuk mencatat nilai yang dihitung sistem inventory atau sumber lain. Sumber tersebut menentukan kuantitas, metode penilaian, dan nilai HPP; sistem akuntansi ini tidak menyediakan modul stok atau menghitung HPP. Akuntan menetapkan jenis nilai dan jurnal yang diterima sesuai kebijakan perusahaan.
- Kode dan nama pada template hanya saran COA. Logika aplikasi mengacu pada peran akun dan ID akun yang dipetakan per perusahaan; kode akun bukan konstanta perhitungan.

## Peran akun dan saran pemetaan awal

Peran berikut adalah key stabil aplikasi. Pemetaan aktual menyimpan ID akun perusahaan, versi, tanggal efektif, dan aktor. Saran di bawah dapat dipakai untuk inisialisasi lalu dikonfirmasi; fitur yang memerlukannya tetap memvalidasi jenis/keaktifan akun.

Pemetaan peran akun sistem pada tabel ini berbeda dari Aturan Posting per Jenis Transaksi. Aturan Posting memetakan field nominal yang sudah diterima ke satu atau lebih baris dengan ID akun perusahaan dan sisi debit/kredit; penggunaan ulang nilai field pada beberapa baris harus dinyatakan eksplisit. Nilai operasional seperti HPP harus sudah disediakan oleh sumber; sistem hanya memvalidasi dan membukukannya.

| Key/peran | Saran template | Pemilik pemetaan | Aturan |
|---|---|---|---|
| `retained_earnings` | 3130 Saldo Laba | General Ledger | Satu akun daun ekuitas aktif; wajib sebelum tutup tahun. |
| `receivables_control` | 1121 Piutang Usaha — Pelanggan | Subledger | Akun daun aset; wajib bila subledger piutang aktif; saldo harus cocok dengan open item. |
| `payables_control` | 2110 Utang Usaha — Pemasok | Subledger | Akun daun liabilitas; wajib bila subledger utang aktif; saldo harus cocok dengan open item. |
| `cash_equivalents` | Pilih dari akun 1111–1114 | Reporting & Analysis | Satu atau lebih akun kas/bank; perusahaan mengonfirmasi kelayakan tiap akun sesuai kebijakan. |
| `current_year_earnings` | 3140 Laba (Rugi) Periode Berjalan | Reporting & Analysis | Nilai turunan dari akun pendapatan/beban; key ini tidak dipetakan ke target posting dan tidak menghasilkan jurnal manual. |
| `statement_line_mapping` | Baris laporan sesuai kelompok akun | Reporting & Analysis | Setiap akun posting yang dilaporkan dipetakan ke baris; akun yang belum dipetakan ditampilkan sebagai pengecualian. |
| `cash_flow_classification` | Tidak ada default otomatis | Reporting & Analysis | Petakan aktivitas operasi/investasi/pendanaan menurut akun dan transaksi; akun kas saja tidak menentukan klasifikasi arus lawan. |

Peran dan saran awal ditetapkan di tabel ini; validasi serta siklus versi normatif dijelaskan di [PRODUCT.md](PRODUCT.md). Setiap domain menyimpan pemetaan yang dimilikinya. General Ledger memvalidasi referensi ID akun melalui interface miliknya. Rumus aplikasi meminta target melalui peran, bukan mencocokkan kode COA.

## Akun

| Kode | Nama | Kelompok | Normal | Posting / pemakaian |
|---|---|---|---|---|
| 1000 | ASET | Aset | — | Induk |
| 1100 | Aset Lancar | Aset | — | Induk |
| 1110 | Kas dan Setara Kas | Aset | — | Induk |
| 1111 | Kas Kecil | Aset | D | Daun; kontrol kas |
| 1112 | Bank Operasional | Aset | D | Daun; kontrol bank |
| 1113 | Bank Khusus | Aset | D | Opsional; mis. rekening payroll/penerimaan |
| 1114 | Dana dalam Perjalanan | Aset | D | Opsional; rekonsiliasi transfer antar rekening |
| 1120 | Piutang Usaha | Aset | — | Induk |
| 1121 | Piutang Usaha — Pelanggan | Aset | D | Kontrol subledger piutang |
| 1122 | Cadangan Kerugian Piutang | Kontra-aset | K | Daun; penilaian/cadangan sesuai kebijakan |
| 1130 | Persediaan | Aset | — | Induk |
| 1131 | Persediaan Barang Dagang | Aset | D | Kontrol persediaan; opsional untuk jasa murni |
| 1132 | Bahan/Perlengkapan untuk Jasa | Aset | D | Opsional; bila memenuhi kebijakan persediaan |
| 1140 | Pajak Dibayar di Muka | Aset | — | Induk; pakai sesuai status pajak |
| 1141 | PPN Masukan | Aset | D | Opsional; akun kontrol pajak |
| 1142 | Pajak Penghasilan Dibayar di Muka | Aset | D | Opsional |
| 1150 | Biaya Dibayar di Muka | Aset | D | Daun; akrual dibalik ke beban sesuai periode |
| 1160 | Uang Muka kepada Pemasok | Aset | D | Daun; rinci menurut pemasok bila diperlukan |
| 1170 | Piutang Lain-lain | Aset | D | Daun; jangan gunakan untuk piutang pelanggan |
| 1200 | Aset Tidak Lancar | Aset | — | Induk |
| 1210 | Aset Tetap — Harga Perolehan | Aset | — | Induk; subledger aset tetap di luar cakupan awal |
| 1211 | Tanah | Aset | D | Daun; opsional |
| 1212 | Bangunan dan Renovasi | Aset | D | Daun; opsional |
| 1213 | Peralatan dan Komputer | Aset | D | Daun; opsional |
| 1214 | Kendaraan | Aset | D | Daun; opsional |
| 1219 | Akumulasi Penyusutan Aset Tetap | Kontra-aset | K | Daun; dipasangkan dengan kelas aset terkait |
| 1220 | Aset Takberwujud | Aset | — | Induk |
| 1221 | Perangkat Lunak/Lisensi Dikapitalisasi | Aset | D | Opsional; hanya jika memenuhi kebijakan kapitalisasi |
| 1229 | Akumulasi Amortisasi | Kontra-aset | K | Opsional |
| 1230 | Uang Jaminan Jangka Panjang | Aset | D | Daun; opsional |
| 2000 | LIABILITAS | Liabilitas | — | Induk |
| 2100 | Liabilitas Jangka Pendek | Liabilitas | — | Induk |
| 2110 | Utang Usaha — Pemasok | Liabilitas | K | Kontrol subledger utang |
| 2120 | Beban Masih Harus Dibayar | Liabilitas | K | Daun; akrual, termasuk jasa yang telah diterima |
| 2130 | Utang Lain-lain | Liabilitas | K | Daun; rinci menurut pihak bila diperlukan |
| 2140 | Uang Muka Pelanggan/Pendapatan Ditangguhkan | Liabilitas | K | Daun; akui sebagai pendapatan saat kewajiban dipenuhi |
| 2150 | Utang Pajak | Liabilitas | — | Induk; akun dan kewajiban bergantung status pajak |
| 2151 | PPN Keluaran | Liabilitas | K | Opsional; akun kontrol pajak |
| 2152 | Utang Pemotongan/Pemungutan Pajak | Liabilitas | K | Opsional; rincikan jenis pajak bila dibutuhkan |
| 2153 | Utang Pajak Penghasilan | Liabilitas | K | Opsional |
| 2160 | Pinjaman Jangka Pendek | Liabilitas | K | Daun; opsional |
| 2170 | Bagian Lancar Pinjaman/Sewa | Liabilitas | K | Daun; reklasifikasi jatuh tempo bila berlaku |
| 2200 | Liabilitas Jangka Panjang | Liabilitas | — | Induk |
| 2210 | Pinjaman Jangka Panjang | Liabilitas | K | Daun; opsional |
| 2220 | Liabilitas Sewa Jangka Panjang | Liabilitas | K | Daun; opsional, sesuai kebijakan yang berlaku |
| 3000 | EKUITAS | Ekuitas | — | Induk |
| 3110 | Modal Disetor/Modal Pemilik | Ekuitas | K | Daun |
| 3120 | Tambahan Modal Disetor | Ekuitas | K | Daun; opsional |
| 3130 | Saldo Laba | Ekuitas | K | Daun; saldo laba terdahulu dan tujuan hasil tutup buku tahunan |
| 3140 | Laba (Rugi) Periode Berjalan | Ekuitas | D/K | Sistem; hasil tahun buku berjalan sebelum tutup buku, tidak dapat diposting langsung |
| 3150 | Dividen/Prive | Kontra-ekuitas | D | Daun; sesuai bentuk badan usaha |
| 4000 | PENDAPATAN | Pendapatan | — | Induk; beri dimensi departemen |
| 4100 | Pendapatan Operasional | Pendapatan | — | Induk |
| 4110 | Penjualan Barang | Pendapatan | K | Daun; beri proyek bila terkait proyek |
| 4120 | Pendapatan Jasa | Pendapatan | K | Daun; beri proyek bila terkait proyek |
| 4130 | Pendapatan Operasional Lain | Pendapatan | K | Daun; gunakan akun khusus jika material |
| 4190 | Retur dan Pengurang Pendapatan | Kontra-pendapatan | — | Induk |
| 4191 | Retur/Pengurangan Penjualan | Kontra-pendapatan | D | Daun |
| 4192 | Diskon Penjualan | Kontra-pendapatan | D | Daun |
| 5000 | BIAYA LANGSUNG / HPP | Beban | — | Induk; beri dimensi departemen |
| 5100 | HPP Barang | Beban | — | Induk |
| 5110 | Harga Pokok Barang Terjual | Beban | D | Daun; opsional untuk jasa murni |
| 5120 | Selisih/Penyusutan Persediaan | Beban | D | Daun; opsional, investigasi sebelum posting |
| 5200 | Biaya Langsung Jasa/Proyek | Beban | — | Induk |
| 5210 | Tenaga Kerja Langsung Jasa/Proyek | Beban | D | Daun; jika dapat diatribusikan langsung |
| 5220 | Subkontraktor/Penyedia Jasa Langsung | Beban | D | Daun |
| 5230 | Bahan Langsung Jasa/Proyek | Beban | D | Daun |
| 5240 | Perjalanan/Biaya Langsung Proyek Lain | Beban | D | Daun; hanya biaya yang dapat diatribusikan |
| 6000 | BEBAN OPERASIONAL | Beban | — | Induk; beri dimensi departemen |
| 6100 | Penjualan dan Pemasaran | Beban | — | Induk |
| 6110 | Iklan dan Promosi | Beban | D | Daun |
| 6120 | Komisi Penjualan | Beban | D | Daun |
| 6130 | Pengiriman kepada Pelanggan | Beban | D | Daun |
| 6200 | Umum dan Administrasi | Beban | — | Induk |
| 6210 | Gaji dan Tunjangan Administrasi | Beban | D | Daun; tidak menggantikan modul payroll |
| 6220 | Sewa Kantor | Beban | D | Daun |
| 6230 | Utilitas dan Komunikasi | Beban | D | Daun |
| 6240 | Perlengkapan Kantor | Beban | D | Daun |
| 6250 | Perjalanan Dinas Administrasi | Beban | D | Daun |
| 6260 | Jasa Profesional | Beban | D | Daun |
| 6270 | Langganan Perangkat Lunak | Beban | D | Daun |
| 6280 | Biaya Administrasi Bank | Beban | D | Daun |
| 6300 | Penyusutan, Amortisasi, dan Penurunan Nilai | Beban | — | Induk |
| 6310 | Beban Penyusutan | Beban | D | Daun |
| 6320 | Beban Amortisasi | Beban | D | Daun |
| 6330 | Beban Kerugian Piutang | Beban | D | Daun |
| 7000 | PENGHASILAN DAN BEBAN LAIN | Pendapatan/Beban | — | Induk |
| 7110 | Penghasilan Bunga | Pendapatan lain | K | Daun; opsional |
| 7120 | Keuntungan Pelepasan Aset | Pendapatan lain | K | Daun; opsional |
| 7210 | Beban Bunga Pinjaman/Sewa | Beban lain | D | Daun; opsional |
| 7220 | Kerugian Pelepasan Aset | Beban lain | D | Daun; opsional |
| 7300 | Beban Pajak Penghasilan | Beban | — | Induk; aktivasi sesuai status/pedoman pajak |
| 7310 | Beban Pajak Penghasilan Kini | Beban | D | Daun; opsional |
| 7320 | Beban/Manfaat Pajak Tangguhan | Beban/Pendapatan | D/K | Daun; opsional |

## Pemetaan dan aturan posting

1. **Laba rugi:** `4xxx` pendapatan; kurangi `419x` retur/diskon; kurangi `5xxx` biaya langsung untuk laba kotor; kurangi `6xxx` beban operasi; sajikan `7xxx` sesuai pemetaan kebijakan. Pajak penghasilan dipisahkan bila relevan. Rentang kode hanya template; pemetaan akun ke baris laporan dimiliki Reporting & Analysis dan dapat berbeda per perusahaan.
2. **Neraca dan neraca saldo:** `1xxx` aset, `2xxx` liabilitas, `3xxx` ekuitas. Sebelum tutup tahun, laba/rugi berjalan dihitung dari akun pendapatan/beban sebagai `3140`; sesudah jurnal penutup, hasil tahun itu berada di `3130` dan `3140` kembali nol. Jangan menghitung hasil yang sama dua kali. Jurnal penutup serta carry-forward saldo ditetapkan di [PRODUCT.md](PRODUCT.md).
3. **Arus kas:** tandai akun kas/setara kas yang memenuhi kebijakan, lalu klasifikasikan arus dari transaksi/baris lawannya sebagai operasi, investasi, atau pendanaan. Jangan menetapkan klasifikasi hanya dari akun bank; satu pembayaran dapat memuat lebih dari satu klasifikasi. [R3]
4. **Akun kontrol:** rincian piutang, utang, kas/bank yang dikelola Subledger harus direkonsiliasi dengan akun kontrol buku besar; transaksi tersebut harus melalui Subledger agar tidak diposting ganda. Saldo persediaan/pajak dapat diterima sebagai nilai dari sistem sumber dan diposting memakai Aturan Posting; modul persediaan/pajak dan rekonsiliasi operasionalnya bukan cakupan awal. Rekonsiliasi rekening koran bank juga bukan cakupan awal.
5. **Proyeksi vs realisasi:** beri departemen untuk semua akun `4xxx`–`7xxx`; beri proyek bila transaksi terkait proyek. Gunakan `4110/4120` dan akun biaya sesuai sifat transaksi, bukan membuat akun baru untuk setiap proyek. Nama proyek disimpan sebagai dimensi.
6. **PPN/pajak:** akun pada tabel hanya wadah buku besar; tarif, perlakuan dapat dikreditkan, pemotongan, pelaporan, dan jurnal harus mengikuti status usaha serta ditinjau ahli pajak. Dokumen ini tidak menetapkan kewajiban atau perhitungan pajak.
7. **Saldo konversi:** gunakan saldo sumber per tanggal cutover dan pertahankan referensi batch. Saldo akun pendapatan/beban tahun berjalan boleh menjadi saldo YTD bila migrasi dimulai di tengah tahun; jangan mem-posting nilai yang sama lagi ke `3140`, karena akun itu dihitung sistem.

## Contoh jurnal dari nilai sumber (tanpa pajak)

Contoh berikut menunjukkan jurnal yang dibentuk dari nilai yang sudah dihitung dan dikirim sumber transaksi. Sistem akuntansi tidak menghitung nilai HPP atau memeriksa kuantitas persediaan.

| Peristiwa | Debit | Kredit | Dimensi |
|---|---|---|---|
| Jual barang secara kredit, nilai 1.000; biaya barang 600 | 1121 Piutang 1.000; 5110 HPP 600 | 4110 Penjualan 1.000; 1131 Persediaan 600 | Departemen; proyek bila penjualan proyek |
| Invoice jasa secara kredit, nilai 800 | 1121 Piutang 800 | 4120 Pendapatan Jasa 800 | Departemen; proyek bila terkait |
| Biaya subkontraktor proyek 200 secara kredit | 5220 Subkontraktor 200 | 2110 Utang Usaha 200 | Departemen dan proyek |
| Pelanggan membayar invoice 800 | 1112 Bank 800 | 1121 Piutang 800 | Referensikan invoice/subledger |

## Referensi

- [R1] IAI, [PSAK 201 — Penyajian Laporan Keuangan](https://web.iaiglobal.or.id/PSAK-Umum/7): kerangka penyajian laporan keuangan; tidak menetapkan kode COA template ini.
- [R2] IAI, [PSAK 202 — Persediaan](https://web.iaiglobal.or.id/PSAK-Umum/16): acuan perlakuan akuntansi persediaan; kebijakan dan modul persediaan perlu ditetapkan terpisah.
- [R3] IAI, [PSAK 207 — Laporan Arus Kas](https://web.iaiglobal.or.id/PSAK-Umum/8): klasifikasi arus kas dan penyajian laporan arus kas.
