# ADR-0013 — Struktur SPA: Modul Mengikuti Backend

Status: Diterima (2026-10-02)

## Konteks

SPA ([ADR-0010](0010-first-party-ui.md)) akan memuat puluhan layar lintas modul (M1–M8). Struktur datar (`pages/`, `lib/`, `components/`) cukup untuk layar autentikasi M0, tetapi mencampur layar dan logika data dari modul berbeda. Backend sudah modular ([ADR-0002](0002-modular-monolith.md)).

## Keputusan

SPA di `resources/js` mengikuti pembagian modul backend (peta di [README](../README.md)):

| Folder | Isi |
| --- | --- |
| `modules/<modul>/` | Semua yang dimiliki satu modul: `pages/`, `components/`, `hooks/`, serta file query dan klien datanya |
| `app/` | Penyusun aplikasi: router, shell, layar status (pending, error, not found) |
| `components/` | Komponen bersama lintas modul; `components/ui/` hanya milik shadcn |
| `hooks/`, `lib/` | Util dan hook lintas modul tanpa pengetahuan domain (HTTP, error, utils) |
| `api/` | Klien OpenAPI dan skema hasil generate |
| `i18n/` | Kamus `id` dan `en` |
| `test/` | `unit/` (logika murni), `flows/` (alur lewat router asli dan jaringan tiruan), `harness.tsx`, `setup.ts` |

Nama folder modul memakai nama modul backend dalam kebab-case: `identity-access`, `master-data`, `general-ledger`, `transaction-entry`, `subledger`, `planning`, `reporting`.

Aturan impor:

- `app/` boleh mengimpor dari modul mana pun; ia yang merangkai.
- Modul tidak mengimpor modul lain. Kebutuhan bersama naik ke `components/`, `hooks/`, atau `lib/`; data modul lain diambil lewat API, bukan lewat impor kode.
- `components/`, `hooks/`, dan `lib/` tidak mengimpor `modules/` maupun `app/`.
- Test ada di `test/`, bukan di samping kode sumber; test per komponen yang kelak perlu di-colocate dibahas kasus per kasus.

Kepemilikan awal: `identity-access` memegang autentikasi, sesi (`/me`), dan workspace (`/me/workspaces`) beserta pemilihnya; `master-data` memegang data perusahaan.

## Konsekuensi

- Satu modul dikerjakan end-to-end di satu folder, selaras dengan item `TODO.md` per modul.
- Impor lintas modul dapat dijaga otomatis (aturan lint) di CI, sejalan dengan deptrac di backend; itu item tersendiri.
- Route tree masih satu file di `app/router.tsx`. Dipecah per modul bila ukurannya mengganggu.
- Kamus i18n masih terpusat di `i18n/`; pemecahan per modul diputuskan bila kamus membengkak.

## Alternatif ditolak

Struktur datar per jenis file (`pages/`, `hooks/`, `lib/`): tidak mencerminkan batas modul dan membengkak sejalan jumlah layar. Test di samping kode sumber: test yang ada menguji alur lintas layar lewat router, bukan satu file, sehingga tidak punya "rumah" yang jelas di samping kode.
