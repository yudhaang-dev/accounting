# ADR-0011 — Tanpa Sanctum: Sesi `web` untuk SPA, Passport untuk Mesin

Status: Diterima (2026-10-01). Mengubah bagian Sanctum pada [ADR-0004](0004-tech-stack.md), [ADR-0005](0005-authentication.md), dan [ADR-0010](0010-first-party-ui.md).

## Konteks

ADR-0005 memakai Sanctum hanya untuk membuat route `/api/v1` menerima sesi cookie Fortify. Sanctum tidak menyediakan hal lain yang dipakai: token personal tidak dipakai (mesin memakai Passport), dan guard gabungan sesi/klien tetap harus ditulis sendiri untuk menyusun `AccessContext`. Dua paket autentikasi untuk satu kebutuhan itu boros.

## Keputusan

- Sanctum tidak dipakai.
- **User (SPA):** sesi cookie dengan guard `web`, tetap lewat Fortify headless. Grup route `/api/v1` diberi middleware sesi dan CSRF (`EncryptCookies`, `AddQueuedCookiesToResponse`, `StartSession`, `ValidateCsrfToken`) secara eksplisit. Route kecil menerbitkan cookie CSRF sebelum login. CORS mengizinkan credentials hanya untuk origin SPA; domain cookie sesi dikonfigurasi per lingkungan.
- **Klien mesin:** Passport Client Credentials, tanpa perubahan (ADR-0005).
- Guard API tetap menerima user bersesi atau klien Passport, lalu menyusun `AccessContext`.
- Sesi tetap satu-satunya sumber identitas user, sehingga logout, batas idle (IA-FR-05), dan 2FA konsisten. Cookie JWT `laravel_token` milik Passport (`CreateFreshApiToken`) tidak dipakai karena masa berlakunya lepas dari sesi.

## Konsekuensi

Satu paket autentikasi lebih sedikit. Middleware stateful untuk `/api/v1` menjadi tanggung jawab aplikasi dan diuji (M0-10, M0-13).

## Alternatif ditolak

Cookie `laravel_token` Passport untuk SPA: pencabutan dan idle timeout tidak mengikuti sesi. Tetap memakai Sanctum: tidak memberi nilai tambah selain middleware stateful.
