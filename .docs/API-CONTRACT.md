# API Contract — Persyaratan Teknis

> **Jenis dokumen:** kontrak teknis antarklien dan backend. Kebutuhan bisnis yang dipenuhi kontrak ini ada di [PRODUCT.md](PRODUCT.md), terutama FR-26–FR-30. Kepemilikan modul dan arah dependensi ada di [DOMAIN-DEPENDENCIES.md](DOMAIN-DEPENDENCIES.md). Kontrak ini menetapkan perilaku yang terlihat klien; implementasi mengikuti versi Laravel yang dipasang. **TR** = technical requirement; **TAC** = kriteria penerimaan teknis.

## 1. Prinsip dan transport

- **TR-01** API MUST berupa REST JSON berversi pada prefix `/api/v1` dan hanya tersedia melalui HTTPS.
- **TR-02** API MUST melayani SPA terpisah dan klien mesin tanpa merender HTML atau mengasumsikan origin sama. CORS MUST dibatasi ke origin SPA yang dikonfigurasi; CORS bukan autentikasi atau otorisasi.
- **TR-03** Resource bisnis MUST berada di bawah `/api/v1/companies/{companyId}/...`, kecuali endpoint token dan identitas user. Pada MVP satu workspace terkait tepat satu perusahaan. Backend MUST memvalidasi scope perusahaan dan kepemilikan setiap ID relasi.
- **TR-04** API MUST memakai ID stabil sebagai relasi dan key mesin, bukan nama tampilan atau kode akun. Perubahan payload/semantik yang mematahkan kompatibilitas MUST memakai versi API baru; field tambahan kompatibel boleh ditambahkan tanpa mengubah arti field lama.

## 2. Resource dan operasi utama

**TR-05 — keluarga resource awal** (bukan definisi OpenAPI lengkap):

- **Organisasi & konfigurasi:** `accounts`, `periods`, `departments`, `projects`, `account-role-catalog`, `general-ledger/account-role-mappings`, `subledger/control-account-mappings`, `reports/account-mappings`.
- **Pembukuan:** `opening-balance-imports`, `journals`, `subledger-transactions`.
- **Perencanaan dan laporan:** `plans`, `reports/profit-loss`, `reports/trial-balance`, `reports/balance-sheet`, `reports/cash-flow`, `reports/plan-vs-actual`.
- **Integrasi:** `transaction-types`, `financial-events`, `integration-clients` (admin).
- **Identitas:** `GET /api/v1/me/workspaces`, pengelolaan membership perusahaan oleh admin.

Perubahan status jurnal memakai `POST /api/v1/companies/{companyId}/journals/{journalId}/{action}`, dengan `action` salah satu dari `submit`, `approve`, `post`, atau `reverse`. Periode/tahun buku memakai command eksplisit untuk `soft-close`, `close`, `reopen`, dan tutup tahun. Update biasa hanya mengubah draf. Route bisnis selalu memakai prefix perusahaan sesuai prinsip di atas.

**TR-06** Laporan MUST menerima parameter konsisten sesuai bentuk laporan: `from` dan `to` atau `as_of`, `department_id`, `project_id`, `account_id`, `plan_version_id`, dan `basis`. Respons laporan menyertakan parameter efektif, mata uang fungsional, total, serta rincian akun/periode. Ekspor CSV MUST menerapkan filter dan otorisasi yang sama dengan laporan.

Untuk perbandingan proyeksi vs realisasi, field persentase selisih diserialisasi sebagai JSON `null` jika nilai proyeksi nol; penanda menguntungkan/tidak menguntungkan mengikuti aturan di FR-13.

## 3. Bentuk respons

**TR-07** Respons sukses MUST mengikuti pola Laravel API Resources; jangan membungkus Resource sekali lagi dengan envelope khusus.

- Satu resource: `{ "data": { ... } }`.
- Koleksi: `{ "data": [ ... ] }`.
- Koleksi berpaginasi: `data`, `links`, dan `meta` pada tingkat teratas. Gunakan metadata dan tautan paginator Laravel.
- `message` tidak wajib pada setiap respons sukses. Isi `data` harus menjelaskan hasil operasi tanpa klien perlu membaca kalimat manusia.
- Respons tanpa konten memakai status HTTP yang sesuai dan tidak dipaksa memiliki body JSON.

Contoh respons paginasi:

```json
{
  "data": [],
  "links": {
    "first": "/api/v1/companies/co_1/journals?page=1",
    "last": "/api/v1/companies/co_1/journals?page=4",
    "prev": null,
    "next": "/api/v1/companies/co_1/journals?page=2"
  },
  "meta": {
    "current_page": 1,
    "per_page": 20,
    "total": 76,
    "last_page": 4
  }
}
```

**TR-08** Validation JSON MUST memakai bentuk Laravel: HTTP `422` dengan `message` dan `errors` sebagai objek field-ke-daftar-pesan. Nested field memakai dot notation. Klien menggunakan status HTTP dan nama field untuk menangani error; jangan mem-parsing teks `message`.

```json
{
  "message": "Data yang dikirim tidak valid.",
  "errors": {
    "amounts.salary_expense": ["Nilai wajib diisi."]
  }
}
```

**TR-09** Error non-validasi MUST menggunakan status HTTP yang sesuai dan `message`. Error domain yang perlu dibedakan klien MUST menyediakan `code` stabil; `errors` field-map hanya disertakan bila ada kesalahan per field. Jangan mengembalikan `data` pada error atau membocorkan data lintas perusahaan.

## 4. Penerimaan Peristiwa Keuangan

**TR-10** Endpoint penerimaan adalah `POST /api/v1/companies/{companyId}/financial-events`. `GET /api/v1/companies/{companyId}/financial-events/{eventId}` hanya boleh membaca hasil kiriman klien itu sendiri atau oleh user dengan izin perusahaan.

**TR-11** Payload kanonis MUST memakai Jenis Transaksi Keuangan dan versi paket yang telah dipublikasikan. Key pada `amounts` dan `references` ditentukan skema Jenis Transaksi; `source_transaction_id` wajib untuk integrasi mesin.

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
  "dimensions": {
    "department_id": "dept_123",
    "project_id": null
  },
  "references": {
    "source_transaction_id": "payrun-2026-09",
    "source_document_number": "PAYRUN-2026-09"
  }
}
```

**TR-12** Kontrak field MUST mengikuti ketentuan berikut:

- `transaction_type`: key stabil dan versi paket immutable.
- `accounting_date`: tanggal pembukuan ISO 8601 (`YYYY-MM-DD`); periode harus terbuka.
- `currency`: harus sama dengan mata uang fungsional perusahaan pada MVP.
- `amounts`: object key-ke-nilai desimal berbentuk string, tidak floating point; skala mengikuti mata uang dan nilai harus non-negatif. Koreksi memakai event/jurnal pembalik dan pengganti.
- `dimensions`: ID departemen/proyek; validasi status, perusahaan, dan kewajiban dimensi mengikuti konfigurasi perusahaan.
- `references`: ID transaksi sumber dan metadata referensi opak. `source_system` **tidak boleh dikirim dalam body**; server mengambilnya dari principal mesin.

Nilai dalam contoh sudah dihitung sistem payroll. API tidak menerima data karyawan, menghitung payroll/HPP, atau menjalankan workflow sistem sumber. Sistem pemanggil memetakan payload vendor ke kontrak kanonis ini.

**TR-13** Respons sukses berupa Resource dengan `data` berisi `event_id`, status penerimaan, serta ID/status jurnal bila sudah dibentuk. Peristiwa mengikuti aturan persetujuan jurnal perusahaan; klien integrasi tidak otomatis memperoleh hak untuk menyetujui atau memposting.

```json
{
  "data": {
    "event_id": "evt_123",
    "status": "accepted",
    "journal_id": "jnl_456",
    "journal_status": "submitted"
  }
}
```

**TR-14 — status HTTP penerimaan event:**

| Keadaan | HTTP | Ketentuan |
|---|---:|---|
| Peristiwa baru diterima | `201 Created` | Mengembalikan identitas dan status hasil. |
| Pengiriman ulang identik | `200 OK` | Mengembalikan hasil asli tanpa posting ganda. |
| Referensi sumber sama, payload bisnis berbeda | `409 Conflict` | Kode stabil: `SOURCE_TRANSACTION_CONFLICT`. |
| Payload atau konfigurasi tidak valid | `422 Unprocessable Entity` | Bentuk validation error mengikuti bagian 3; tidak ada posting parsial. |
| Kredensial hilang/tidak valid | `401 Unauthorized` | Tidak memproses event. |
| Klien terautentikasi tanpa izin perusahaan/jenis transaksi | `403 Forbidden` | Tidak memproses event atau membocorkan data di luar scope. |

**TR-15** Kunci idempotensi MUST menggabungkan `company_id`, `source_system` milik principal, dan `references.source_transaction_id`. Perbandingan retry dilakukan terhadap payload bisnis kanonis, bukan whitespace atau urutan key JSON. Koreksi setelah posting menggunakan jurnal pembalik dan pengganti.

## 5. Autentikasi klien mesin

- **TR-16** Token endpoint MUST berupa `POST /api/v1/oauth/token` dengan `application/x-www-form-urlencoded` dan OAuth 2.0 Client Credentials Grant; autentikasi klien memakai `client_secret_basic`.
- **TR-17** Endpoint token/resource hanya boleh melalui HTTPS. API resource menerima opaque Bearer access token pada `Authorization: Bearer ...`; secret/token dilarang pada query string dan SPA dilarang menyimpan client secret.
- **TR-18** Token berlaku 600 detik tanpa refresh token. Rotasi/revoke kredensial mencabut token terkait. Scope minimum: `transaction-types:read`, `financial-events:write`, `financial-events:read:self`.
- **TR-19** Principal dibatasi pada satu perusahaan, satu `source_system`, dan allowlist Jenis Transaksi. Hak baca mandiri hanya mencakup hasil kiriman principal itu.
- **TR-20** Client secret MUST ditampilkan satu kali saat dibuat dan disimpan dalam bentuk hash. Perubahan, rotasi, dan pencabutan dicatat di audit. `source_system` ditetapkan administrator pada principal; nilai ini adalah atribut sumber/kunci idempotensi, bukan bukti otorisasi dan bukan field request.

> Pilihan `client_secret_basic` pada MVP mengutamakan interoperabilitas. Terapkan scope sempit, token singkat, TLS, rotasi, dan revoke. Tinjau autentikasi asimetris saat kebutuhan mitra/risiko meningkat.

## 6. Kriteria teknis penerimaan

- **TAC-01** Resource sukses memiliki satu lapis `data`; pagination menggunakan `links` dan `meta` tingkat teratas tanpa `data.data`.
- **TAC-02** Validation error JSON mengikuti bentuk Laravel `message` + field-map `errors` dan status `422`; code/machine behavior tidak bergantung pada teks pesan.
- **TAC-03** Event baru, retry identik, konflik key, payload invalid, klien tak terautentikasi, dan scope terlarang menghasilkan status serta efek samping sesuai TR-14.
- **TAC-04** Retry event identik tidak membuat jurnal/event ganda; payload berubah dengan kunci yang sama menghasilkan `SOURCE_TRANSACTION_CONFLICT` dan tidak mengubah hasil awal.
- **TAC-05** Klien tidak dapat memilih `source_system` di body, membaca kiriman klien lain, mengganti scope perusahaan, atau melewati persetujuan jurnal.
- **TAC-06** API tidak memproses skema vendor khusus dan tidak menghitung nilai operasional yang dikirim sistem sumber.
- **TAC-07** Respons dan ekspor selalu menerapkan otorisasi perusahaan yang sama dengan resource/laporan asal.

## 7. Referensi protokol

- [R1] IETF, [RFC 6749 — OAuth 2.0 Authorization Framework](https://www.rfc-editor.org/rfc/rfc6749.html): Client Credentials Grant untuk klien confidential/service-to-service.
- [R2] IETF, [RFC 9700 — OAuth 2.0 Security Best Current Practice](https://www.rfc-editor.org/rfc/rfc9700.html): praktik keamanan OAuth; merekomendasikan autentikasi asimetris jika layak. Pilihan `client_secret_basic` MVP adalah tradeoff interoperabilitas yang dibatasi kontrol bagian 5.
- [R3] IETF, [RFC 6750 — Bearer Token Usage](https://www.rfc-editor.org/rfc/rfc6750.html): penggunaan token Bearer melalui TLS.
- [R4] Laravel, [Eloquent API Resources](https://laravel.com/docs/13.x/eloquent-resources): pembungkus `data`, metadata resource, serta `links`/`meta` pagination.
- [R5] Laravel, [Validation](https://laravel.com/docs/13.x/validation): respons JSON validation error `422` dengan `message` dan `errors`.
