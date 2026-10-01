# ADR-0005 — Autentikasi User dan Klien Mesin

Status: Diterima (2026-10-01)

## Konteks

UI first-party adalah SPA di situs yang sama dengan API ([ADR-0010](0010-first-party-ui.md)); sistem pihak ketiga memanggil API secara server-to-server. Kebutuhan teknisnya ada di API-TR-21 sampai API-TR-24.

## Keputusan

- **User:** sesi cookie lewat Fortify mode headless (login, verifikasi email, reset kata sandi, 2FA TOTP, passkey). SPA meminta cookie CSRF sebelum login; route `/api/v1` menerima sesi yang sama lewat middleware stateful Sanctum. CORS mengizinkan credentials hanya untuk origin SPA; domain stateful Sanctum dan domain cookie sesi dikonfigurasi per lingkungan. Tidak ada token di browser.
- **Klien mesin:** Passport Client Credentials. Endpoint token didaftarkan di `/api/v1/oauth/token` dengan `client_secret_basic`. Masa berlaku token default 600 detik (konfigurasi), tanpa refresh token. Secret disimpan sebagai hash.
- Data scope klien (perusahaan, `source_system`, allowlist Jenis Transaksi) milik `IdentityAccess` dan ditautkan ke klien OAuth. Rotasi dan pencabutan mencabut token aktif lewat action khusus.
- Access token Passport berbentuk JWT, tetapi klien wajib memperlakukannya sebagai opaque.
- Guard API menerima user bersesi atau klien Passport, lalu menyusun `AccessContext` (IA) untuk otorisasi.

## Alternatif ditolak

Passport Authorization Code + PKCE untuk UI: tidak diperlukan untuk situs yang sama dan menaruh token di browser. Token personal Sanctum untuk mesin: tidak mengikuti standar OAuth Client Credentials.
