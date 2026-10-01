# Identity & Access — User, Workspace, Permission, Klien Integrasi

> Modul `IdentityAccess` (prefix `IA`). Bergantung pada `MasterData`. Mekanisme autentikasi teknis ada di [API-CONTRACT.md](../API-CONTRACT.md).

## Tujuan & batas

Menentukan siapa (user atau mesin) boleh melakukan apa, di perusahaan mana, dan atas data dimensi mana. Modul bisnis tidak memanggil modul ini; lapisan aplikasi meneruskan konteks akses (permission, cakupan data, principal) ke modul bisnis sebagai parameter. Aturan approval/SoD milik `TransactionEntry`; penyimpanan audit adalah kemampuan lintas modul.

## Data milik modul

| Data | Isi utama |
| --- | --- |
| `User` | Identitas login, status, 2FA |
| `Workspace` | Ruang akses; `company_id` (1:1 pada MVP) |
| `Membership` | User × workspace, status, kedaluwarsa opsional |
| `Permission` | Katalog key aksi terproteksi milik sistem, format `<resource>.<action>` (mis. `entries.approve`, `periods.close`, `reports.export`) |
| `Role` | Data per perusahaan: nama dan daftar permission |
| `DataScope` | Pembatasan membership ke nilai dimensi tertentu |
| `IntegrationClient` | Perusahaan, `source_system`, allowlist Jenis Transaksi, scope OAuth, status, hash secret |
| `SecurityPolicy` | Kebijakan perusahaan: permission yang mewajibkan 2FA, batas sesi, allowlist IP klien integrasi |

## Interface publik

- `AccessContext` — permission efektif dan cakupan data user/klien pada perusahaan tertentu; dipakai lapisan aplikasi.
- `IntegrationPrincipal` — perusahaan, `source_system`, allowlist Jenis Transaksi, dan scope klien yang terautentikasi.

## Kebutuhan

- **IA-FR-01 Workspace dan membership.** User dapat menjadi anggota banyak workspace dan melihat daftar workspace yang dapat diaksesnya. Admin mengundang, mengubah, dan mencabut membership; membership dapat diberi tanggal kedaluwarsa (mis. auditor eksternal). Pencabutan atau kedaluwarsa langsung menghentikan akses tanpa menghapus audit. Pembuatan workspace membuat perusahaan lewat use case aplikasi.
- **IA-FR-02 Permission dan role.** Katalog permission didefinisikan sistem; role adalah data perusahaan yang dapat dibuat, diubah, dan dinonaktifkan. Seed template: `accounting_admin` (konfigurasi, membership, role, klien integrasi), `preparer` (membuat dan mengajukan entri/rencana), `approver` (menyetujui/menolak entri dan pembalikan), `viewer` (membaca laporan), `auditor` (membaca laporan, drill-down, dan audit). User dapat memegang beberapa role; permission efektif adalah gabungannya. Kode hanya memeriksa permission, tidak pernah nama role (P-01).
- **IA-FR-03 Cakupan data.** Membership dapat dibatasi ke nilai dimensi tertentu beserta turunannya; default tanpa batas. Cakupan berlaku untuk baca, tulis, approval, posting, dan ekspor; modul bisnis menerimanya sebagai filter. Total laporan bagi user bercakupan terbatas hanya menjumlahkan baris dalam cakupannya dan ditandai terbatas.
- **IA-FR-04 Autentikasi user.** Login dengan kredensial pribadi, verifikasi email, reset kata sandi, 2FA (TOTP dengan recovery code), dan passkey. Mekanisme sesi SPA ditetapkan di API-CONTRACT dan ADR.
- **IA-FR-05 Kebijakan keamanan.** Perusahaan dapat mewajibkan 2FA untuk permission tertentu dan mengatur batas sesi idle; SHOULD dapat membatasi IP klien integrasi. Kebijakan berversi dan diaudit.
- **IA-FR-06 Klien integrasi.** User berizin mendaftarkan, membatasi, merotasi, dan mencabut klien integrasi. Setiap klien terikat ke satu perusahaan, satu `source_system` (unik per perusahaan), allowlist Jenis Transaksi, dan scope. Secret hanya ditampilkan sekali dan disimpan sebagai hash. Rotasi dan pencabutan langsung membatalkan token aktif. Klien tidak mewakili user dan tidak dapat menyetujui entri.
- **IA-FR-07 Akses grup (`Post`).** Akses laporan konsolidasi memerlukan grant eksplisit pada `group_id`; membership di beberapa workspace tidak membentuk atau memberi akses grup.

## Kriteria penerimaan

- **IA-AC-01** User anggota dua workspace hanya dapat membaca/mengubah data perusahaan yang dipilih dan diizinkan; membership di satu workspace tidak memberi akses ke workspace lain atau membentuk grup konsolidasi.
- **IA-AC-02** Pencabutan atau kedaluwarsa membership menghentikan akses sejak request berikutnya; catatan audit tetap ada.
- **IA-AC-03** Mengubah permission sebuah role langsung berlaku bagi semua pemegangnya tanpa perubahan kode; mengganti nama role tidak mengubah perilaku.
- **IA-AC-04** User yang dibatasi ke departemen A (beserta turunannya) tidak dapat membaca, memposting, atau mengekspor baris departemen B; total yang dilihatnya hanya mencakup baris departemen A.
- **IA-AC-05** Bila kebijakan mewajibkan 2FA untuk `entries.approve`, user tanpa 2FA ditolak saat menyetujui dengan alasan yang jelas.
- **IA-AC-06** Klien integrasi tanpa izin, dengan perusahaan berbeda, atau di luar allowlist tidak dapat mengirim atau membaca event; klien dengan scope tulis tidak dapat menyetujui atau memposting.
- **IA-AC-07** Klien integrasi hanya dapat dikelola user berizin; setelah rotasi atau pencabutan, token lama langsung ditolak; secret tidak dapat ditampilkan ulang.
