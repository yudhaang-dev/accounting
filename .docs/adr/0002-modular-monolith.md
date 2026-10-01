# ADR-0002 — Modular Monolith dan Lapisan Kode

Status: Diterima (2026-10-01)

## Konteks

Batas modul dan graf dependensi ditetapkan di [DOMAIN-DEPENDENCIES.md](../DOMAIN-DEPENDENCIES.md). Produk harus mudah dirawat tanpa over-engineering, dan batas modul harus ditegakkan otomatis, bukan hanya lewat disiplin.

## Keputusan

```text
app/
  Modules/<Module>/
    Contracts/     interface + DTO publik; satu-satunya yang boleh diimport modul lain
    Domain/        model Eloquent, enum, value object, aturan invarian
    Application/   Actions (satu use case per class), Queries, Policies
    Http/          controller API tipis, FormRequest, JsonResource, routes
    Database/      migrations, factories, seeders milik modul
  Orchestration/   use case lintas modul (kesiapan tutup buku, impor saldo konversi, aktivasi perusahaan)
  Platform/        Audit, Attachments, Export, Numbering, Outbox, Localization
  Support/         tipe teknis kecil (mis. Money) tanpa aturan bisnis
```

- Alur: Controller → Action → Domain. `DB::transaction` dibuka di Action. Pemanggilan lintas modul hanya lewat `Contracts`.
- Eloquent menjadi model domain; tidak ada repository generik, CQRS bus, atau event sourcing. Fakta domain dikirim setelah commit.
- Nama tabel memakai prefix modul: `md_`, `ia_`, `te_`, `sl_`, `gl_`, `pl_`, `rp_`, `co_`, dan `pf_` untuk Platform.
- Ditegakkan di CI: deptrac memetakan graf DOMAIN-DEPENDENCIES; Pest arch test memastikan import lintas modul hanya ke `Contracts`, tidak ada pemeriksaan nama role, dan prefix tabel sesuai modul.
- Kode bawaan starter kit (mis. `User`, action Fortify) dipindah bertahap ke `IdentityAccess`; komponen Inertia dihapus ([ADR-0010](0010-first-party-ui.md)).

## Konsekuensi

Kepemilikan jelas dan pelanggaran batas gagal di CI. Ada sedikit boilerplate untuk `Contracts`. Konfigurasi deptrac wajib diperbarui bersama perubahan DOMAIN-DEPENDENCIES.
