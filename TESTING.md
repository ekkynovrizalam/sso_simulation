# Testing the Central Mock Server (Postman)

## Start the stack

```bash
cp .env.example .env   # optional — defaults work
docker compose up --build
```

| Service        | URL                                      |
|----------------|------------------------------------------|
| Mock API       | http://localhost:8080                    |
| **Papan pengumuman RabbitMQ** | http://localhost:8080/board |
| RabbitMQ UI    | http://localhost:15672 (guest / guest) |
| Admin dashboard| http://localhost:8080/api/admin/dashboard |
| JWKS (public key)| http://localhost:8080/api/v1/auth/jwks |

---

## 0. JWT — RS256 + JWKS (untuk mahasiswa Laravel)

Token ditandatangani **RS256**. **Private key hanya di server pusat** — mahasiswa **tidak** menerima `JWT_SECRET`.

| Yang dibagikan ke mahasiswa | Fungsi |
|-----------------------------|--------|
| URL JWKS | Ambil **public key** untuk verify signature |
| Isi JWT setelah login | Baca `profile` / `app` **lokal** (tanpa panggil pusat lagi) |

**Cek JWKS:**

```bash
curl -s http://localhost:8080/api/v1/auth/jwks | jq
```

Alias OIDC: `GET /.well-known/jwks.json`

**Verify di Laravel (ringkas):**

```bash
composer require firebase/php-jwt
```

```php
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;

$jwks = json_decode(
    file_get_contents('http://localhost:8080/api/v1/auth/jwks'),
    true,
    512,
    JSON_THROW_ON_ERROR
);
$keys = JWK::parseKeySet($jwks);
$decoded = JWT::decode($bearerToken, $keys);

// End-User SSO
$profile = (array) ($decoded->profile ?? []);

// M2M
$app = (array) ($decoded->app ?? []);
```

Setelah verify sukses → assign **role lokal** di Laravel (tidak ada role di JWT pusat).

---

## 1. Central SSO — dual mode (`POST /api/v1/auth/token`)

Endpoint ini mendukung **dua skenario** pada URL yang sama.

### 1A. Machine-to-Machine (Laravel microservice)

Digunakan saat aplikasi Laravel mahasiswa memanggil Central System sebagai **client aplikasi**.

- Method: `POST`
- URL: `http://localhost:8080/api/v1/auth/token`
- Headers: `Content-Type: application/json`
- Body:

```json
{
  "api_key": "KEY-MHS-01"
}
```

**Expected (200)**

```json
{
  "status": "success",
  "token_type": "m2m",
  "grant_type": "client_credentials",
  "algorithm": "RS256",
  "jwks_uri": "/api/v1/auth/jwks",
  "token": "eyJ...",
  "expires_in": 3600,
  "app": {
    "client_id": "KEY-MHS-01",
    "name": "Laravel Service — Smart Logistics",
    "team": "TEAM-01"
  }
}
```

JWT payload berisi info aplikasi (`app.client_id`, `app.name`, `app.team`). **Tidak ada role** — role ditentukan lokal di Laravel mahasiswa.

**Negative:** `"api_key": "KEY-INVALID"` → `401`

---

### 1B. End-User SSO (Simulasi KTP Digital Global)

Digunakan saat **warga kota / pengguna akhir** login dengan email & password.

- Body:

```json
{
  "email": "warga01@ktp.iae.id",
  "password": "KtpDigital2026!"
}
```

**Expected (200)**

```json
{
  "status": "success",
  "token_type": "user",
  "grant_type": "password",
  "algorithm": "RS256",
  "jwks_uri": "/api/v1/auth/jwks",
  "token": "eyJ...",
  "expires_in": 3600,
  "profile": {
    "name": "Ahmad Rizki Pratama",
    "nim": "2026000001",
    "email": "warga01@ktp.iae.id"
  }
}
```

JWT meng-embed `profile` (name, nim, email) — **tanpa field role**.

**Akun mock:** 41 warga di `config/citizens.php` (`warga01@ktp.iae.id` … `warga41@ktp.iae.id`), password lab: `KtpDigital2026!`

**Negative:** email/password salah → `401`

**Prioritas:** Jika body memuat `api_key` yang tidak kosong, server memproses sebagai **M2M** (bukan End-User).

---

## 2. SOAP Audit — generic industry schema

Skema **one-size-fits-all** untuk 13 tema industri: wajib ada `<TeamID>`, `<ActivityName>`, `<LogContent>`.

- Method: `POST`
- URL: `http://localhost:8080/soap/v1/audit`
- Headers:
  - `Content-Type: text/xml` (atau `application/soap+xml`)
  - `Authorization: Bearer <token dari langkah 1 — M2M atau User>`
- Body (raw XML):

```xml
<?xml version="1.0" encoding="UTF-8"?>
<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/" xmlns:iae="http://iae.central/audit">
  <soap:Body>
    <iae:AuditRequest>
      <iae:TeamID>TEAM-01</iae:TeamID>
      <iae:ActivityName>ShipmentCreated</iae:ActivityName>
      <iae:LogContent><![CDATA[{"order_id":"ORD-8842","origin":"JKT","destination":"SBY","weight_kg":12.5}]]></iae:LogContent>
    </iae:AuditRequest>
  </soap:Body>
</soap:Envelope>
```

`<LogContent>` boleh berisi **CDATA JSON**, XML mentah, atau teks bebas — data transaksi spesifik tema mahasiswa.

**Expected (200)** — SOAP Envelope:

```xml
<iae:Status>SUCCESS</iae:Status>
<iae:ReceiptNumber>IAE-LOG-2026-8891A7BC</iae:ReceiptNumber>
```

Format nomor resi: `IAE-LOG-2026-` + 8 karakter hex acak (contoh: `IAE-LOG-2026-8891A7BC`).

**Negative tests**

| Test | Expected |
|------|----------|
| Body JSON + `Content-Type: application/json` | `415` + SOAP Fault |
| Tanpa header `Authorization` | `401` + SOAP Fault |
| Token invalid/expired | `401` + SOAP Fault |
| Tag wajib hilang (mis. tanpa `ActivityName`) | `400` + SOAP Fault |

---

## 3. RabbitMQ — publish a message

- Method: `POST`
- URL: `http://localhost:8080/api/v1/messages/publish`
- Headers:
  - `Content-Type: application/json`
  - `Authorization: Bearer <token M2M atau User>`
- Body:

```json
{
  "routing_key": "ShipmentCreated",
  "message": {
    "activity_name": "ShipmentCreated",
    "receipt_ref": "IAE-LOG-2026-8891A7BC"
  }
}
```

**Expected (200)**

```json
{
  "status": "success",
  "exchange": "iae.central.exchange",
  "routing_key": "ShipmentCreated"
}
```

`routing_key` opsional — boleh apa saja atau dikosongkan. Yang wajib: pesan dipublish ke exchange `iae.central.exchange`.

### Cara cek sukses / gagal (paling praktis)

Buka **papan pengumuman** di browser:

- URL: http://localhost:8080/board
- Auto-refresh setiap 5 detik

| Yang Anda lihat | Artinya |
|-----------------|---------|
| Status **Tidak terkoneksi** (merah) | RabbitMQ belum jalan atau kredensial salah — cek `docker compose ps` |
| Status **Terhubung — belum ada pesan** (kuning) | Broker OK, tapi pesan Anda belum sampai — cek nama exchange |
| Kartu pesan muncul dengan nama tim/API key Anda (hijau) | **Berhasil** — pesan sudah masuk queue `iae.lab.board` |

Pastikan publish ke exchange `iae.central.exchange`. Routing key bebas (tidak ada pola khusus).

Alternatif JSON API:

```bash
curl -s http://localhost:8080/api/v1/messages/board
```

---

## 4. Instructor admin dashboard

- Method: `GET`
- URL: `http://localhost:8080/api/admin/dashboard`
- Header: `X-Admin-Key: admin-iae-dashboard`

Menampilkan ringkasan per subject (API key atau email warga) dan log SSO M2M / SSO User / SOAP / RabbitMQ.

---

## Quick health check

```bash
curl -s http://localhost:8080/health
```

Response mencakup status koneksi RabbitMQ dan jumlah pesan di papan (`message_count`).

---

## Student Laravel integration hints

| Integration | Auth | Notes |
|-------------|------|--------|
| JWKS | — | `GET /api/v1/auth/jwks` — verify RS256, **tanpa** private key |
| M2M token | `api_key` di body | Untuk service-to-service |
| User token | `email` + `password` | Simulasi login warga KTP Digital |
| SOAP audit | Bearer token | XML generic: TeamID, ActivityName, LogContent |
| RabbitMQ | Bearer token | Publish via mock API atau AMQP langsung; verifikasi di `/board` |

Di Docker Compose network, gunakan hostname `mock-server` dan `rabbitmq` (bukan `localhost`).
