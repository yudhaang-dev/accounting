# API Contract — Kebutuhan Teknis

> Kontrak antara backend dan klien (SPA first-party dan sistem pihak ketiga). Prefix ID `API`. Kebutuhan bisnis ada di dokumen modul; keputusan stack dan autentikasi di [ADR-0004](adr/0004-tech-stack.md) dan [ADR-0005](adr/0005-authentication.md). Spesifikasi OpenAPI lengkap dihasilkan dari kode.

## 1. Prinsip dan transport

- **API-TR-01** REST JSON berversi di `/api/v1`, hanya lewat HTTPS. Perubahan yang mematahkan kompatibilitas memakai versi baru; field tambahan yang kompatibel boleh ditambahkan tanpa mengubah arti field lama.
- **API-TR-02** API melayani SPA dan klien mesin tanpa merender HTML. CORS dibatasi ke origin SPA yang dikonfigurasi; CORS bukan otorisasi.
- **API-TR-03** Resource bisnis berada di `/api/v1/companies/{companyId}/...`, kecuali endpoint token dan identitas user. Backend memvalidasi scope perusahaan dan kepemilikan setiap ID relasi (P-05).
- **API-TR-04** Relasi memakai ID stabil (ULID) atau key konfigurasi, tidak pernah nama tampilan atau kode akun.
- **API-TR-05** Nilai uang dan kurs berupa string desimal; tanggal `YYYY-MM-DD`; waktu ISO 8601 dengan zona waktu.
- **API-TR-06** Field `message` mengikuti header `Accept-Language` (`id`, `en`; default dari konfigurasi). Klien hanya berpegang pada status HTTP, `code`, dan nama field.

## 2. Resource dan operasi

**API-TR-07 — keluarga resource awal** (relatif terhadap `/api/v1/companies/{companyId}` kecuali ditandai):

| Modul | Resource |
| --- | --- |
| Identitas | `/api/v1/me`, `/api/v1/me/workspaces`, `memberships`, `roles`, `permissions` (katalog), `integration-clients` |
| Data induk | profil perusahaan (`/api/v1/companies/{companyId}`), `dimension-types`, `dimension-values`, `currencies`, `rate-types`, `exchange-rates` |
| Buku besar | `accounts`, `fiscal-calendar`, `fiscal-years`, `periods`, `journals` (baca), `opening-balance-imports`, `revaluation-runs`, `general-ledger/account-role-mappings` |
| Pencatatan | `transaction-types`, `entries`, `financial-events`, `recurring-schedules` |
| Subledger | `parties`, `posting-profiles`, `cash-accounts`, `open-items`, `allocations`, `reconciliations`, `subledger/account-role-mappings`, `reports/aging` |
| Perencanaan | `plans`, `plan-versions` |
| Pelaporan | `report-definitions`, `report-definitions/{id}/results`, `movement-classifications`, `reporting/account-role-mappings`, `reports/trial-balance`, `reports/general-ledger`, `report-snapshots` |
| Lintas modul | `policies/{key}` (kebijakan per modul, mis. `approval`, `ledger`, `security`), `account-role-catalog` |

- **API-TR-08 Command.** Transisi status memakai `POST .../{resource}/{id}/{action}`:
  - `entries`: `submit`, `approve`, `reject`, `post`, `revise`, `cancel`.
  - `journals/{id}/reverse` membuat entri `system.reversal` yang tunduk pada approval dan mengembalikan entri tersebut.
  - `periods`: `soft-close`, `close`, `reopen`; `fiscal-years`: `close`, `reopen`; `plan-versions`: `publish`, `archive`; `opening-balance-imports` dan `revaluation-runs`: `post`.
  - `PATCH` hanya untuk draf dan konfigurasi; resource yang sudah final tidak dapat diubah.
- **API-TR-09 Laporan dan ekspor.** Parameter: `from`/`to` atau `as_of`, `account_id`, `dimensions[<type_key>]`, `transaction_type_keys[]`, `source`, `plan_version_id`, `basis`, `compare`. Respons memuat parameter efektif, mata uang fungsional, total, cakupan histori (RP-FR-13), versi definisi, dan rincian. Ekspor CSV memakai endpoint yang sama dengan `Accept: text/csv` dan otorisasi yang sama (X-FR-02); ekspor besar SHOULD diproses asinkron dan menghasilkan tautan unduh.

## 3. Bentuk respons dan error

- **API-TR-10 Sukses.** Mengikuti Laravel API Resources tanpa envelope tambahan [R14]: `{ "data": {...} }`, koleksi `{ "data": [...] }`, dan paginasi dengan `data`, `links`, `meta` di tingkat teratas. Daftar memakai paginasi offset; feed memakai paginasi cursor (`meta.next_cursor`). Respons tanpa konten memakai status yang sesuai tanpa body.

```json
{
  "data": [],
  "links": { "first": "...?page=1", "last": "...?page=4", "prev": null, "next": "...?page=2" },
  "meta": { "current_page": 1, "per_page": 20, "total": 76, "last_page": 4 }
}
```

- **API-TR-11 Validasi.** HTTP 422 dengan `message` dan `errors` (field → daftar pesan, nested dengan dot notation) [R15].
- **API-TR-12 Error domain.** Menyertakan `code` stabil. Error yang terkait field memakai 422 dengan `code` dan `errors`; konflik status atau versi memakai 409; ketiadaan izin memakai 403. Error tidak pernah memuat `data` atau data perusahaan lain.

```json
{
  "message": "Periode tidak menerima posting.",
  "code": "PERIOD_NOT_OPEN",
  "errors": { "accounting_date": ["Periode 2026-09 berstatus closed."] }
}
```

| `code` | HTTP | Arti |
| --- | --- | --- |
| `SOURCE_TRANSACTION_CONFLICT` | 409 | Kunci sumber sama, isi berbeda (TE-FR-13) |
| `IDEMPOTENCY_KEY_REUSED` | 409 | `Idempotency-Key` sama, isi berbeda |
| `VERSION_CONFLICT` | 409 | `lock_version` sudah berubah |
| `INVALID_STATE_TRANSITION` | 409 | Aksi tidak berlaku untuk status saat ini |
| `PERIOD_NOT_OPEN` | 422 | Periode tidak menerima posting untuk entri ini |
| `ACCOUNT_NOT_POSTABLE` | 422 | Akun induk, nonaktif, atau milik perusahaan lain |
| `POSTING_SOURCE_NOT_ALLOWED` | 422 | Akun tidak menerima sumber ini |
| `DIMENSION_REQUIRED`, `DIMENSION_INVALID` | 422 | Dimensi wajib hilang atau nilainya tidak valid |
| `UNBALANCED_ENTRY` | 422 | Tidak seimbang dalam mata uang fungsional |
| `EXCHANGE_RATE_NOT_FOUND` | 422 | Kurs tidak tersedia (MD-FR-08) |
| `TRANSACTION_TYPE_VERSION_RETIRED` | 422 | Versi Jenis Transaksi sudah dihentikan |
| `MAPPING_REQUIRED` | 422 | Pemetaan peran atau kebijakan wajib belum lengkap |
| `TRANSACTION_TYPE_NOT_ALLOWED` | 403 | Di luar allowlist klien integrasi |
| `SEGREGATION_OF_DUTIES` | 403 | Pembuat tidak boleh menyetujui entrinya sendiri |
| `TWO_FACTOR_REQUIRED` | 403 | Aksi memerlukan 2FA |
| `UNAUTHENTICATED` | 401 | Kredensial hilang atau tidak valid |
| `FORBIDDEN` | 403 | Tanpa izin untuk aksi atau perusahaan ini |
| `NOT_FOUND` | 404 | Resource tidak ada atau tidak terlihat oleh principal |
| `CSRF_TOKEN_MISMATCH` | 419 | Token CSRF hilang atau tidak cocok (hanya permintaan bersesi) |
| `TOO_MANY_REQUESTS` | 429 | Melebihi rate limit; lihat `Retry-After` |
| `SERVER_ERROR` | 500 | Kesalahan tak terduga; detail internal tidak dibocorkan |

Kode baru ditambahkan ke tabel ini sebelum dipakai.

- **API-TR-13 Idempotensi command.** `POST` yang membuat resource atau mengubah status menerima header `Idempotency-Key` (wajib untuk klien mesin, dianjurkan untuk SPA), kecuali `financial-events` yang memakai TE-FR-13. Pengulangan dengan kunci dan isi sama mengembalikan hasil asli; isi berbeda menghasilkan `IDEMPOTENCY_KEY_REUSED`. Kunci berlaku per principal dan perusahaan dengan masa simpan terkonfigurasi.
- **API-TR-14 Konkurensi optimistis.** Resource yang dapat diubah membawa `lock_version`; `PATCH` wajib mengirimnya, dan ketidakcocokan menghasilkan `VERSION_CONFLICT` tanpa perubahan.
- **API-TR-15 Rate limit.** Dibatasi per user dan per klien integrasi sesuai konfigurasi; kelebihan menghasilkan 429 dengan header `Retry-After`.

## 4. Peristiwa Keuangan

- **API-TR-16 Endpoint.** `POST .../financial-events` membuat event (TE-FR-12). `GET .../financial-events/{eventId}` hanya untuk kiriman klien itu sendiri atau user berizin. `GET .../financial-events/changes?cursor=` adalah changes feed (TE-FR-15).
- **API-TR-17 Payload kanonis.**

```json
{
  "transaction_type": { "key": "payroll_summary", "version": 1 },
  "accounting_date": "2026-09-30",
  "currency": "IDR",
  "amounts": {
    "salary_expense": "10000000",
    "net_payable": "9500000",
    "withholding_payable": "500000"
  },
  "dimensions": { "department": "01JAX2Q5B7Y3M8N4P6R0S9T1VW", "project": null },
  "references": {
    "source_transaction_id": "payrun-2026-09",
    "source_document_number": "PAYRUN-2026-09"
  }
}
```

- **API-TR-18 Field.**
  - `transaction_type`: key dan versi terbit yang ada di allowlist klien.
  - `accounting_date`: periodenya harus menerima posting.
  - `currency`: mata uang aktif perusahaan. `exchange_rate` (string desimal) opsional bila kebijakan mengizinkan kurs input; bila kosong, kurs diambil dari tabel (MD-FR-08).
  - `amounts`: key dari skema Jenis Transaksi; string desimal non-negatif dengan skala ≤ skala mata uang.
  - `dimensions`: objek key tipe dimensi → ID nilai atau `null`.
  - `party`, `cash_account`, `allocations`: bila diminta skema; pihak dapat dirujuk lewat ID atau referensi eksternal milik `source_system` klien (SL-FR-01).
  - `references`: `source_transaction_id` wajib untuk klien mesin; metadata lain disimpan opak. `source_system` dilarang di body; server mengambilnya dari principal.
  - API tidak menerima skema vendor atau data operasional (mis. data karyawan) dan tidak menghitung nilai operasional (P-02).
- **API-TR-19 Respons.** `data` berisi `event_id`, `status` (status entri), serta `journal_id` dan `journal_number` bila sudah diposting.

```json
{ "data": { "event_id": "01JAX2R8C4D6F8G0H2J4K6M8N0", "status": "submitted", "journal_id": null, "journal_number": null } }
```

- **API-TR-20 Status HTTP penerimaan event.**

| Keadaan | HTTP | Ketentuan |
| --- | ---: | --- |
| Event baru diterima | 201 | Mengembalikan identitas dan status |
| Kiriman ulang identik | 200 | Mengembalikan hasil asli tanpa entri ganda |
| Kunci sumber sama, isi berbeda | 409 | `SOURCE_TRANSACTION_CONFLICT` |
| Payload atau konfigurasi tidak valid | 422 | Tanpa efek parsial |
| Kredensial hilang atau tidak valid | 401 | Tidak diproses |
| Tanpa izin perusahaan atau Jenis Transaksi | 403 | Tidak diproses dan tidak membocorkan data |
| Melebihi rate limit | 429 | Dengan `Retry-After` |

## 5. Autentikasi

- **API-TR-21 SPA.** Sesi cookie stateful dengan proteksi CSRF untuk SPA pada situs yang sama (mis. subdomain); SPA meminta cookie CSRF sebelum login, dan CORS mengizinkan credentials hanya untuk origin SPA ([ADR-0010](adr/0010-first-party-ui.md)). Token dan client secret tidak pernah disimpan di browser.
- **API-TR-22 Klien mesin.** OAuth 2.0 Client Credentials di `POST /api/v1/oauth/token` dengan `application/x-www-form-urlencoded` dan `client_secret_basic` [R11]. Token dikirim sebagai Bearer di header `Authorization` [R13]; secret dan token dilarang di query string. Klien memperlakukan token sebagai opaque.
- **API-TR-23 Token dan scope.** Masa berlaku default 600 detik (dapat dikonfigurasi), tanpa refresh token. Scope: `transaction-types:read`, `financial-events:write`, `financial-events:read:self`, dan opsional `exchange-rates:write`. Rotasi atau pencabutan klien langsung membatalkan token aktif (IA-FR-06).
- **API-TR-24 Principal mesin.** Terikat ke satu perusahaan, satu `source_system`, dan allowlist Jenis Transaksi. `source_system` adalah atribut principal dan bagian kunci idempotensi, bukan bukti otorisasi.

> `client_secret_basic` dipilih untuk interoperabilitas MVP; risikonya dibatasi scope sempit, token singkat, TLS, rotasi, dan pencabutan. Tinjau autentikasi asimetris bila kebutuhan mitra atau risiko meningkat [R12].

## 6. Fase 2

- **API-TR-25 Webhook (`F2`).** Dikirim lewat outbox setelah commit, ditandatangani HMAC-SHA256 beserta timestamp, dan dicoba ulang dengan backoff. Penerima memproses secara idempoten berdasarkan ID webhook.
- **API-TR-26 Batch event (`F2`).** `POST .../financial-events/batch` dengan hasil per item (TE-FR-17).

## 7. Kriteria penerimaan

- **API-AC-01** Respons sukses memiliki satu lapis `data`; paginasi memakai `links` dan `meta` di tingkat teratas tanpa `data.data`.
- **API-AC-02** Error validasi berbentuk `message` + `errors` dengan status 422; perilaku klien tidak bergantung pada teks pesan.
- **API-AC-03** Event baru, kiriman ulang identik, konflik kunci, payload tidak valid, klien tanpa kredensial, scope terlarang, dan rate limit menghasilkan status serta efek sesuai API-TR-20.
- **API-AC-04** Kiriman ulang identik tidak membuat entri atau jurnal ganda; isi berbeda dengan kunci sama menghasilkan `SOURCE_TRANSACTION_CONFLICT` tanpa mengubah hasil awal.
- **API-AC-05** Klien tidak dapat memilih `source_system` lewat body, membaca kiriman klien lain, berpindah perusahaan, atau melewati approval.
- **API-AC-06** API menolak skema vendor dan tidak menghitung nilai operasional yang seharusnya dipasok sistem sumber.
- **API-AC-07** Respons dan ekspor selalu menerapkan otorisasi dan cakupan data yang sama dengan resource asal.
- **API-AC-08** Pengulangan command dengan `Idempotency-Key` dan isi sama mengembalikan hasil asli; isi berbeda menghasilkan `IDEMPOTENCY_KEY_REUSED`.
- **API-AC-09** `PATCH` dengan `lock_version` usang menghasilkan 409 `VERSION_CONFLICT` tanpa perubahan data.
- **API-AC-10** Mengganti `Accept-Language` hanya mengubah teks `message`; `code`, status, dan nama field tetap sama.
