# ADR-0004 — Tech Stack

Status: Diterima (2026-10-01)

## Konteks

Basis kode berasal dari Laravel React starter kit (Laravel 13, PHP ≥ 8.3, Fortify, Pest 5, Larastan, Pint). Inertia dan Wayfinder bawaan starter kit tidak dipakai; UI first-party adalah SPA ([ADR-0010](0010-first-party-ui.md)). Produk melayani UI first-party dan sistem pihak ketiga lewat API yang sama (P-06).

## Keputusan

| Kebutuhan | Pilihan |
| --- | --- |
| Autentikasi user | Fortify (sudah ada); sesi di `/api/v1` lewat middleware `web` ([ADR-0005](0005-authentication.md), diubah [ADR-0011](0011-drop-sanctum.md)) |
| Autentikasi mesin | Laravel Passport, Client Credentials |
| Otorisasi | spatie/laravel-permission mode *teams* (team = perusahaan); role sebagai data, pemeriksaan per permission |
| Kontrak modul dan command | spatie/laravel-data |
| Output HTTP | `JsonResource` bawaan (API-TR-10) |
| Dokumentasi OpenAPI | dedoc/scramble |
| Filter dan sort daftar | spatie/laravel-query-builder |
| Uang dan kurs | brick/math (`BigDecimal`) dengan custom cast |
| Validasi skema Jenis Transaksi | opis/json-schema |
| Audit | spatie/laravel-activitylog + trigger append-only (ADR-0003) |
| Queue | Driver database untuk pengembangan; Redis + Horizon untuk produksi |
| Webhook (`F2`) | spatie/laravel-webhook-server |
| Batas arsitektur | deptrac + Pest arch ([ADR-0002](0002-modular-monolith.md)) |
| Kualitas | Pest, Larastan, Pint (sudah ada) |
| Klien API bertipe untuk SPA | Dihasilkan dari spesifikasi OpenAPI ([ADR-0010](0010-first-party-ui.md)) |
| Observability | Sentry, Laravel Pulse |

Sengaja tidak dipakai: event sourcing, CQRS bus, repository generik, tenancy multi-database, library state machine.

## Konsekuensi

Kompatibilitas setiap paket dengan Laravel 13 diverifikasi saat instalasi. Paket yang tidak kompatibel diganti lewat ADR baru, bukan diam-diam.
