# ADR-0021 — Akun hanya lewat undangan

Status: Diterima (2026-11-11). Mengubah bagian registrasi pada [ADR-0005](0005-authentication.md).

## Konteks

Sistem ini dipakai oleh perusahaan yang dikenal pemiliknya, bukan layanan terbuka. Registrasi publik (`POST /register` bawaan Fortify) membuat siapa pun bisa punya akun dan, lewat pembuatan workspace, tenant sendiri. Di produksi tidak boleh ada akun bawaan, kata sandi literal, atau command yang bisa dijalankan di server untuk membuat akun (kekhawatiran eksekusi kode jarak jauh).

## Keputusan

1. **Registrasi publik dihapus.** `Features::registration()` dan `CreateNewUser` dihapus; `/register` menjadi 404. Akun baru hanya terjadi saat undangan diterima.
2. **Menerima undangan tidak butuh login.** `POST /api/v1/invitations/accept` menerima `token`. Bila email undangan belum punya akun, permintaan membawa `name`, `password`, dan `password_confirmation`; akun dibuat dengan email terverifikasi (pemilik email terbukti dengan memegang token), lalu user masuk. Bila akun sudah ada, penerima harus sudah login sebagai akun itu (aturan lama).
3. **Satu-satunya jalur pembuatan `User` adalah `ManageInvitations::accept`.** Test arsitektur menjaganya, dan melarang kelas `Command` memakai `User` atau `Hash`.
4. **Pemilik pertama.** `access:invite-owner` membuat undangan tanpa workspace untuk satu alamat yang tertulis di konfigurasi (`FIRST_OWNER_EMAIL`), bukan di argumen command. Command itu:
   - hanya membuat undangan, tidak membuat akun atau kata sandi;
   - menolak jalan bila alamat belum dikonfigurasi atau sudah ada user mana pun;
   - mencetak tautan sekali di terminal, tidak menulis token ke log atau email;
   - menggantikan undangan pemilik yang masih terbuka.
   Saat diterima, undangan ini diperiksa lagi: alamatnya harus sama dengan konfigurasi dan belum boleh ada user. Setelah itu tidak ada jalur untuk akun lain di luar undangan workspace dari anggota yang berwenang.
5. **Pembatasan laju.** Endpoint terima-undangan tanpa login dibatasi per IP.

## Akibat

- Undangan boleh tanpa workspace (`ia_invitations.workspace_id` dan `invited_by` nullable). Undangan seperti itu hanya membuat akun; menerima mengembalikan 204 dan user lanjut membuat workspace (aturan siapa yang boleh: D2c).
- Reset kata sandi tetap lewat Fortify.
- Tidak ada cara membuat akun lewat terminal selain undangan pemilik pertama. Pemulihan bila alamat pemilik berganti: ubah `FIRST_OWNER_EMAIL` hanya berlaku saat belum ada user; sesudahnya akun baru lewat undangan anggota.

## Alternatif ditolak

Command `access:create-user`: bertentangan dengan tujuan (siapa pun yang mampu menjalankan perintah di server bisa membuat akun dengan kata sandi). Registrasi dengan persetujuan admin: menambah alur tanpa kebutuhan, karena undangan sudah mencakupnya.
