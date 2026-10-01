# ADR-0010 — UI First-Party: SPA Terpisah

Status: Diterima (2026-10-01)

## Konteks

UI first-party dan sistem pihak ketiga harus memakai API serta otorisasi yang sama (P-06). Inertia dan Wayfinder di basis kode hanya bawaan starter kit, bukan keputusan arsitektur.

## Keputusan

- UI first-party adalah SPA di repositori ini, dibangun dengan Vite di folder sendiri. Semua data bisnis hanya lewat `/api/v1`; backend paling banyak menyajikan shell HTML statis tanpa data.
- Hasil build disajikan oleh aplikasi Laravel yang sama (satu origin) atau dari subdomain (mis. `app.example.com` dan `api.example.com`). Keduanya berada di situs yang sama, sehingga sesi cookie berlaku ([ADR-0005](0005-authentication.md)). Bila kelak harus lintas situs, autentikasinya diputuskan lewat ADR baru.
- Klien API bertipe dihasilkan dari spesifikasi OpenAPI (Scramble), kontrak yang sama dengan yang dipakai pihak ketiga.
- Form entri dibangun dari skema JSON Jenis Transaksi (TE-FR-04).
- Satu repositori memungkinkan perubahan API dan UI dirilis bersama; SPA tetap dapat dipindah ke repositori lain kelak tanpa mengubah backend.

Rekomendasi awal stack SPA:

| Kebutuhan | Pilihan |
| --- | --- |
| Bahasa dan build | React 19 + TypeScript + Vite (sudah ada di starter kit) |
| Routing | TanStack Router |
| State server | TanStack Query |
| Klien API | openapi-typescript + openapi-fetch dari spesifikasi Scramble |
| Komponen UI | Tailwind + shadcn/ui (sudah ada) |
| Terjemahan | react-i18next (`id`, `en`) |
| Form | react-hook-form; form dinamis dari skema Jenis Transaksi |

## Konsekuensi

- Inertia, Wayfinder, dan halaman bawaan starter kit dihapus. Fortify dipertahankan dalam mode headless (tanpa view) dan dipanggil SPA lewat JSON.
- API langsung teruji oleh UI sendiri, sehingga pihak ketiga mendapat kemampuan dan otorisasi yang sama.
- Diperlukan konfigurasi CORS dengan credentials, domain stateful Sanctum, dan domain cookie sesi per lingkungan.

## Alternatif ditolak

Inertia dengan data bisnis lewat props: menciptakan dua permukaan (props dan API) yang mudah berbeda perilaku. Token OAuth di browser (PKCE): tidak diperlukan selama SPA berada di situs yang sama, dan lebih rawan terhadap XSS.
