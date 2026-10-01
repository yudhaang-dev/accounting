# Dokumentasi — Indeks

Kebutuhan produk sistem pembukuan *headless* (API-first) untuk SPA first-party dan sistem pihak ketiga. Muat hanya dokumen yang relevan dengan tugas.

## Urutan baca

1. [PRODUCT.md](PRODUCT.md) — selalu: scope, prinsip `P-xx`, glosarium, kebutuhan lintas modul.
2. Dokumen modul yang dikerjakan (lihat peta).
3. [DOMAIN-DEPENDENCIES.md](DOMAIN-DEPENDENCIES.md) — bila tugas menyentuh lebih dari satu modul.
4. [API-CONTRACT.md](API-CONTRACT.md) — bila tugas menyentuh endpoint, autentikasi, atau format respons.
5. [adr/](adr/) — bila tugas menyentuh arsitektur, tech stack, atau konvensi.

## Peta dokumen

| ID | Dokumen | Isi |
| --- | --- | --- |
| `P`, `X` | [PRODUCT.md](PRODUCT.md) | Scope, prinsip, glosarium, kebutuhan lintas modul |
| `MD` | [modules/master-data.md](modules/master-data.md) | Perusahaan, dimensi & dimension set, mata uang & kurs |
| `IA` | [modules/identity-access.md](modules/identity-access.md) | User, workspace, permission, cakupan akses, klien integrasi |
| `TE` | [modules/transaction-entry.md](modules/transaction-entry.md) | Jenis Transaksi, aturan posting, entri & approval, event, jurnal berulang |
| `SL` | [modules/subledger.md](modules/subledger.md) | Pihak, open item, alokasi, kas/bank, aging, rekonsiliasi |
| `GL` | [modules/general-ledger.md](modules/general-ledger.md) | COA, kalender & periode, jurnal posted, valas & revaluasi, cutover, tutup buku |
| `PL` | [modules/planning.md](modules/planning.md) | Versi anggaran & proyeksi |
| `RP` | [modules/reporting.md](modules/reporting.md) | Definisi laporan, laporan keuangan, proyeksi vs realisasi |
| `CO` | [CONSOLIDATION.md](CONSOLIDATION.md) | Konsolidasi grup (Post-MVP) |
| `API` | [API-CONTRACT.md](API-CONTRACT.md) | Kontrak REST, autentikasi, error, idempotensi |
| — | [DOMAIN-DEPENDENCIES.md](DOMAIN-DEPENDENCIES.md) | Kepemilikan modul & graf dependensi |
| — | [CHART-OF-ACCOUNTS.md](CHART-OF-ACCOUNTS.md) | Template COA & saran peran akun (data, bukan kode) |
| `ADR` | [adr/](adr/) | Keputusan arsitektur dan alasannya |
| `M`, `B` | [TODO.md](TODO.md) | Rencana implementasi per milestone |
| `R` | [REFERENCES.md](REFERENCES.md) | Rujukan standar dan regulasi |

## Konvensi

- **ID:** `<MODUL>-FR-nn` kebutuhan, `<MODUL>-AC-nn` kriteria penerimaan, `API-TR-nn` kebutuhan teknis API. Pakai ID di issue, commit, dan nama test.
- **Fase:** tanpa tanda = MVP; `F2` = Fase 2; `Post` = Post-MVP.
- **Kata kunci:** MUST = wajib; SHOULD = dianjurkan.
- **Bahasa:** kode dan kontrak berbahasa Inggris; dokumen berbahasa Indonesia dengan identifier dari glosarium (P-09).
- **Struktur dokumen modul:** Tujuan & batas → Data milik modul → Interface publik → Kebutuhan → Kriteria penerimaan.
- **Satu aturan, satu tempat:** tulis aturan di dokumen pemiliknya; dokumen lain cukup merujuk ID-nya. Rujukan eksternal hanya di REFERENCES.md.
- **Keputusan:** jangan mengubah keputusan yang tercatat di `adr/` tanpa ADR baru.
