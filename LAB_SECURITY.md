# Lab Security — IAE Central Mock Server

Dokumen ini berlaku untuk **dosen/pengelola lab** dan **mahasiswa** yang menggunakan mock server ini pada mata kuliah EAI.

> **Penting:** Sistem ini adalah **simulator pembelajaran**, bukan produk keamanan siap produksi. Desainnya sengaja disederhanakan (static API key, password lab bersama, JWT HS256) agar fokus ke integrasi REST · SOAP · AMQP — **bukan** untuk di-deploy ke internet publik tanpa kontrol.

---

## Ruang lingkup yang aman

| Lingkungan | Rekomendasi |
|------------|-------------|
| Docker lokal di laptop mahasiswa | ✅ Disarankan |
| Jaringan lab kampus / VLAN kuliah | ✅ Disarankan (dengan checklist di bawah) |
| VPN kampus | ✅ Disarankan |
| VPS / cloud tanpa firewall | ⚠️ Hanya dengan hardening penuh |
| Internet publik terbuka | ❌ Tidak disarankan |

---

## Checklist dosen — sebelum lab dibuka

Centang semua item sebelum mahasiswa mengakses server bersama.

### 1. Secret & kredensial

- [ ] Salin `.env.example` → `.env` — **jangan** commit file `.env`
- [ ] Ganti `JWT_SECRET` (min. 32 karakter acak)
- [ ] Ganti `ADMIN_KEY` (acak, hanya dosen yang tahu)
- [ ] Ganti `RABBITMQ_USER` dan `RABBITMQ_PASS` (bukan `guest/guest` di server shared)

Contoh generate secret (macOS/Linux):

```bash
openssl rand -hex 32   # untuk JWT_SECRET
openssl rand -hex 24   # untuk ADMIN_KEY
```

### 2. Jaringan & port

- [ ] Batasi akses firewall ke subnet lab (mis. `10.10.0.0/24`) jika di server kampus
- [ ] **Jangan** expose port RabbitMQ (`5672`, `15672`) ke internet — cukup untuk subnet lab atau localhost
- [ ] Mock API (`8080`) hanya boleh diakses dari jaringan yang dipercaya
- [ ] Pertimbangkan reverse proxy (nginx/Caddy) + **HTTPS** jika akses lintas subnet

### 3. API key & akun warga

- [ ] Assign **satu API key per kelompok** di `config/api_keys.php` — jangan satu key untuk seluruh kelas
- [ ] Bagikan key hanya lewat LMS / channel resmi, bukan chat publik
- [ ] Ingatkan mahasiswa: password warga (`KtpDigital2026!`) hanya untuk simulasi KTP Digital, bukan password nyata

### 4. Operasional

- [ ] Jalankan `docker compose up --build` di mesin lab yang terpantau
- [ ] Pantau dashboard admin: `GET /api/admin/dashboard` + header `X-Admin-Key`
- [ ] Set resource limit di `.env` (sudah ada default di `docker-compose.yml`)
- [ ] **Rotate** secret & API key setiap semester / setelah kebocoran

### 5. Setelah lab selesai

- [ ] `docker compose down -v` jika tidak perlu lagi (hapus volume log)
- [ ] Cabut aturan firewall sementara
- [ ] Dokumentasikan insiden (jika ada abuse) untuk evaluasi

---

## Aturan mahasiswa (wajib dibaca)

### Boleh

- Menggunakan API key yang diberikan dosen untuk tim Anda saja
- Menguji integrasi M2M, End-User SSO, SOAP, dan RabbitMQ sesuai `TESTING.md`
- Menjalankan stack **lokal** di mesin sendiri untuk development

### Tidak boleh

- Mem-publish stack ini ke VPS publik tanpa izin dosen
- Membagikan API key, `ADMIN_KEY`, atau `JWT_SECRET` ke luar tim
- Brute-force endpoint `/api/v1/auth/token` atau dashboard admin
- Mengisi RabbitMQ dengan spam / pesan berukuran sangat besar
- Menganggap mock ini sebagai standar keamanan industri (OAuth2, MFA, RBAC pusat, dll.)

### Yang harus dipahami untuk laporan EAI

| Di mock lab | Di produksi nyata |
|-------------|-------------------|
| API key statis | OAuth2 client credentials + rotasi secret |
| Password warga sama | Hash bcrypt/argon2, kebijakan password |
| JWT HS256 secret tunggal | RS256, JWKS, expiry + refresh token |
| HTTP | HTTPS wajib (TLS 1.2+) |
| Guest RabbitMQ | User/role terpisah, TLS, vhost isolation |

---

## Hardening opsional (server lab bersama)

### Bind port hanya ke localhost (mahasiswa akses via SSH tunnel)

Di `docker-compose.yml`, ubah mapping port menjadi:

```yaml
ports:
  - "127.0.0.1:8080:8080"
```

Mahasiswa connect via:

```bash
ssh -L 8080:localhost:8080 user@lab-server
```

### Firewall contoh (Ubuntu ufw)

```bash
# Hanya dari subnet lab
sudo ufw allow from 10.10.0.0/24 to any port 8080 proto tcp
sudo ufw deny 5672
sudo ufw deny 15672
```

Sesuaikan subnet dengan jaringan kampus Anda.

### Reverse proxy + TLS (ringkas)

Letakkan nginx/Caddy di depan mock server; terminate TLS di proxy. Mahasiswa memanggil `https://iae-lab.university.ac.id` — bukan IP mentah port 8080.

---

## Model ancaman (ringkas)

| Ancaman | Dampak di lab | Mitigasi |
|---------|---------------|----------|
| API key bocor | Orang lain pakai identitas tim | Key per tim, rotate, jangan commit ke Git |
| JWT secret bocor | Pemalsuan token | Ganti `JWT_SECRET`, restart container |
| Abuse RabbitMQ | Queue penuh, resource habis | Firewall port AMQP, password kuat, limit resource |
| Sniffing HTTP | Token terbaca di jaringan | HTTPS atau jaringan lab terisolasi |
| Scanning internet | Brute force auth | Jangan expose publik; rate limit (opsional) |

---

## Respons insiden sederhana

Jika ada aktivitas mencurigakan di dashboard admin (banyak SSO gagal, publish massal):

1. Catat waktu, subject/API key, dan event type dari log
2. Rotate API key tim yang terdampak di `config/api_keys.php`
3. Ganti `JWT_SECRET` + `ADMIN_KEY` di `.env`, lalu `docker compose up --build -d`
4. Restart RabbitMQ jika perlu: `docker compose restart rabbitmq`
5. Laporkan ke dosen / asisten lab

---

## Rotasi semester

| Item | Frekuensi |
|------|-----------|
| `JWT_SECRET`, `ADMIN_KEY`, RabbitMQ password | Setiap semester |
| API key per kelompok | Setiap semester / setiap proyek |
| Password warga di `citizens.php` | Opsional (beri tahu mahasiswa jika diubah) |
| Volume SQLite log (`mock_data`) | Reset setelah semester (`docker compose down -v`) |

---

## FAQ

**Apakah harus ganti Slim ke Laravel agar aman?**  
Tidak. Keamanan lab lebih ditentukan oleh **jaringan, secret, dan kebijakan penggunaan** daripada framework.

**Bolehkah mahasiswa fork repo ini ke GitHub public?**  
Boleh untuk portofolio **setelah** menghapus `.env`, API key asli, dan log sensitif.

**Apakah data di SQLite log mengandung PII?**  
Bisa — `LogContent` SOAP dan profil SSO. Jangan publish dump database ke publik.

---

## Referensi cepat file sensitif

| File / variabel | Isi sensitif |
|-----------------|--------------|
| `.env` | JWT secret, admin key, RabbitMQ password |
| `config/api_keys.php` | M2M keys per tim |
| `config/citizens.php` | Email & password lab warga |
| Volume `mock_data` | SQLite activity log |

**Jangan** masukkan file di atas ke repository Git publik tanpa redaksi.

---

*Terakhir diperbarui untuk kurikulum EAI — Central Corporate Mock Server.*
