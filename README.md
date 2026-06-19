# IAE Central Corporate Mock Server

Mock **Central Corporate System** untuk laboratorium mata kuliah **Enterprise Application Integration (EAI)**. Mahasiswa menghubungkan microservice **Laravel** mereka ke tiga pola integrasi korporat: **REST SSO**, **SOAP/XML legacy audit**, dan **RabbitMQ messaging**.

Dibangun dengan **PHP 8.2 + Slim 4** — ringan, containerized, dan cukup untuk puluhan tim lab tanpa footprint framework penuh.

> **Lab only — not production.** Baca **[LAB_SECURITY.md](LAB_SECURITY.md)** sebelum deploy ke server bersama atau membagikan kredensial ke mahasiswa.

---

## Apa yang disimulasikan?

| Pintu integrasi | Protokol | Peran dalam skenario EAI |
|-----------------|----------|---------------------------|
| **Central SSO** | REST JSON | Autentikasi M2M (Laravel) & End-User (KTP Digital Global) |
| **Audit System** | SOAP XML | Submit log aktivitas generic lintas 13 tema industri |
| **Message Broker** | AMQP (RabbitMQ) | Event bus korporat `iae.central.exchange` (topic) |

Mahasiswa mengimplementasikan **konsumen** di Laravel; server ini adalah **penyedia pusat** yang selalu hidup di lab.

---

## Arsitektur

```
                    ┌─────────────────────────────────────────────────────────┐
                    │              IAE Central Mock Server (:8080)             │
                    │  ┌─────────────┐  ┌──────────────┐  ┌─────────────────┐ │
  Laravel M2M       │  │ AuthController│  │SoapAuditCtrl │  │ MessageController│ │
  (api_key) ───────►│  │ POST /auth/  │  │ POST /soap/  │  │ POST /messages/ │ │
                    │  │     token    │  │  v1/audit    │  │    publish      │ │
  End-User          │  └──────┬───────┘  └──────┬───────┘  └────────┬────────┘ │
  (email+pass) ────►│         │                  │                    │          │
                    │         ▼                  ▼                    ▼          │
                    │  ┌─────────────────────────────────────────────────────┐ │
                    │  │ AuthService · SoapAuditService · RabbitMqService    │ │
                    │  │ ActivityLogger · JwtCodec (RS256) · RsaKeyManager   │ │
                    │  └─────────────────────────────────────────────────────┘ │
                    └────────────────────────────┬────────────────────────────┘
                                                 │ AMQP publish
                                                 ▼
                    ┌─────────────────────────────────────────────────────────┐
                    │  RabbitMQ 3.13 (:5672 AMQP · :15672 Management UI)       │
                    │  Exchange: iae.central.exchange (topic, durable)         │
                    └─────────────────────────────────────────────────────────┘

  Dosen ──► GET /api/admin/dashboard  (header X-Admin-Key) ──► HTML activity log
```

### Alur autentikasi (dual SSO)

Satu endpoint `POST /api/v1/auth/token` — dua mode:

```
┌─────────────────────┐     api_key      ┌──────────────────┐
│ Laravel Microservice│ ───────────────► │  JWT token_type  │
│ (Machine-to-Machine)│                  │      = m2m       │
└─────────────────────┘                  │  payload: app{}  │
                                         └────────┬─────────┘
                                                  │
┌─────────────────────┐   email+password          │
│ Warga / Mahasiswa   │ ───────────────► JWT token_type = user (profile)
│ (KTP Digital Global)│                  └──► role lokal di Laravel mahasiswa
└─────────────────────┘
                                                  │
                                         Bearer M2M only
                                                  │
                    ┌─────────────────────────────┴─────────────────────────────┐
                    ▼                                                           ▼
            POST /soap/v1/audit                              POST /api/v1/messages/publish
            (SOAP XML + Bearer M2M)                          (JSON + Bearer M2M)
```

**Role tidak disertakan di JWT pusat** — penentuan RBAC dilakukan di aplikasi Laravel mahasiswa setelah **verify RS256** via JWKS.

Mahasiswa **tidak** menerima private key — hanya `GET /api/v1/auth/jwks` (public key). Baca `profile` dari JWT secara lokal setelah verify; tidak perlu panggil pusat tiap request.

---

## Quick start

```bash
cp .env.example .env          # sesuaikan secret untuk lab bersama
docker compose up --build -d
```

| Service | URL | Default auth |
|---------|-----|--------------|
| Mock API | http://localhost:8080 | Lihat [TESTING.md](TESTING.md) |
| Health | http://localhost:8080/health | — |
| JWKS | http://localhost:8080/api/v1/auth/jwks | Public key (verify JWT) |
| Admin dashboard | http://localhost:8080/api/admin/dashboard | Header `X-Admin-Key` |
| **Papan pengumuman RabbitMQ** | http://localhost:8080/board | — (buka di browser, auto-refresh) |
| RabbitMQ UI | http://localhost:15672 | User/pass dari `.env` |

**Dokumentasi uji Postman:** [TESTING.md](TESTING.md)  
**Checklist keamanan lab:** [LAB_SECURITY.md](LAB_SECURITY.md)

---

## API reference

| Method | Path | Auth | Deskripsi |
|--------|------|------|-----------|
| `GET` | `/health` | — | Health check JSON |
| `GET` | `/api/v1/auth/jwks` | — | Public keys (RS256) untuk verify JWT |
| `GET` | `/.well-known/jwks.json` | — | Alias JWKS (OIDC-style) |
| `POST` | `/api/v1/auth/token` | Body | **M2M:** `{ "api_key": "KEY-MHS-25", "nim": "102022430014" }` |
| | | | **User:** `{ "email": "warga01@ktp.iae.id", "password": "..." }` |
| `POST` | `/soap/v1/audit` | Bearer M2M | Audit XML generic (lihat skema di bawah) |
| `POST` | `/api/v1/messages/publish` | Bearer M2M | Publish ke `iae.central.exchange` |
| `GET` | `/board` | — | Papan pengumuman — lihat pesan di queue lab |
| `GET` | `/api/v1/messages/board` | — | JSON papan pengumuman (untuk skrip/automasi) |
| `GET` | `/api/admin/dashboard` | `X-Admin-Key` | Log aktivitas HTML (dosen) |

### SOAP audit — skema generic (13 tema industri)

Tag wajib di dalam SOAP Body:

| Tag | Isi |
|-----|-----|
| `<TeamID>` | Identitas tim lab |
| `<ActivityName>` | Nama aktivitas bisnis (bebas per tema) |
| `<LogContent>` | Data transaksi (CDATA JSON, XML, atau teks) |

Response sukses:

```xml
<iae:Status>SUCCESS</iae:Status>
<iae:ReceiptNumber>IAE-LOG-2026-8891A7BC</iae:ReceiptNumber>
```

Contoh request: [samples/soap-audit-request.xml](samples/soap-audit-request.xml)

---

## Konfigurasi identitas

| File | Isi |
|------|-----|
| [config/api_keys.php](config/api_keys.php) | API key M2M per tim Laravel (`KEY-MHS-01`, …) |
| [config/citizens.php](config/citizens.php) | 41 akun warga KTP Digital (`warga01@ktp.iae.id` … `warga41@ktp.iae.id`) |
| [config/app.php](config/app.php) | JWT TTL, path SQLite, koneksi RabbitMQ |
| `.env` | Secret production-like untuk lab (JWT, admin, RabbitMQ) |

Password lab warga default: `KtpDigital2026!` (ubah di `citizens.php` jika perlu).

---

## Project structure

```
sso_simulation/
├── config/
│   ├── api_keys.php          # M2M keys + metadata aplikasi
│   ├── citizens.php          # 41 End-User SSO (KTP Digital)
│   └── app.php               # Env-based settings
├── public/index.php            # Front controller
├── src/
│   ├── AppBootstrap.php        # Routes & dependency wiring
│   ├── Controllers/
│   │   ├── AuthController.php
│   │   ├── SoapAuditController.php
│   │   ├── MessageController.php
│   │   ├── AdminController.php
│   │   └── HealthController.php
│   └── Services/
│       ├── AuthService.php     # Dual SSO + JWT validate
│       ├── JwtCodec.php        # RS256 sign/verify
│       ├── RsaKeyManager.php   # Key pair + JWKS export
│       ├── SoapAuditService.php
│       ├── RabbitMqService.php
│       └── ActivityLogger.php  # SQLite audit trail
├── rabbitmq/definitions.json   # Pre-declared topic exchange
├── samples/soap-audit-request.xml
├── docker-compose.yml
├── Dockerfile
├── TESTING.md
├── LAB_SECURITY.md
└── composer.json
```

---

## Stack teknis

| Komponen | Pilihan | Catatan |
|----------|---------|---------|
| Runtime | PHP 8.2 (Alpine) | Built-in server di container |
| Framework | Slim 4 | Routing + middleware |
| JWT | RS256 (`JwtCodec` + `RsaKeyManager`) | Private key di volume; JWKS untuk mahasiswa |
| Log aktivitas | SQLite | Volume Docker `mock_data` |
| Message broker | RabbitMQ 3.13 + management | Exchange `iae.central.exchange` |
| Orkestrasi | Docker Compose | Resource limits per service |

---

## Environment variables

Salin [.env.example](.env.example) ke `.env`:

| Variable | Fungsi |
|----------|--------|
| `MOCK_SERVER_PORT` | Port mock API (default `8080`) |
| `JWT_KEYS_DIR` | Path RSA key pair (default `/var/www/data/keys`) |
| `JWT_KID` | Key ID di header JWT & JWKS |
| `JWT_TTL` | Masa berlaku token detik (default `3600`) |
| `ADMIN_KEY` | Akses dashboard dosen |
| `RABBITMQ_USER` / `RABBITMQ_PASS` | Kredensial broker |
| `RABBITMQ_*_PORT` | Port AMQP & management UI |
| `MOCK_*` / `RABBITMQ_*` limits | CPU & memory caps container |

---

## Resource limits (Docker)

| Service | CPU limit | Memory limit |
|---------|-----------|--------------|
| `mock-server` | 0.5 core | 128 MB |
| `rabbitmq` | 1.0 core | 512 MB |

Sesuaikan via `.env`. Pantau: `docker stats iae-central-mock iae-rabbitmq`

---

## Integrasi dari Laravel mahasiswa

| Kebutuhan | Host di Docker network | Host di laptop |
|-----------|------------------------|----------------|
| Mock API | `http://mock-server:8080` | `http://localhost:8080` |
| RabbitMQ AMQP | `rabbitmq:5672` | `localhost:5672` |

1. **Service call:** `POST /api/v1/auth/token` dengan `api_key` + `nim` → simpan Bearer token.  
2. **User login (opsional):** `email` + `password` warga → JWT berisi `profile`.  
3. **Verify JWT:** ambil public key dari `GET /api/v1/auth/jwks` → verify RS256 di Laravel → baca `profile` lokal.  
4. **SOAP:** kirim XML ke `/soap/v1/audit` — jangan pakai JSON.  
5. **Event:** `POST /api/v1/messages/publish` atau AMQP langsung ke exchange topic.

---

## Lisensi & penggunaan

Infrastruktur **pendidikan** — Enterprise Application Integration lab.  
Bukan standar keamanan industri; lihat perbandingan mock vs produksi di [LAB_SECURITY.md](LAB_SECURITY.md).
