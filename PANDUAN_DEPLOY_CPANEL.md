# Panduan Deploy MapLeads Pro ke Shared Hosting cPanel (Hybrid Setup)

Panduan ini menjelaskan cara melakukan deploy **MapLeads Pro** ke cPanel dengan arsitektur **Hybrid**:
- **cPanel**: Web CRM, Database MySQL, OneSender Gateway, Webhook, dan Cron Follow-up 24/7.
- **Laptop Anda**: Menjalankan engine Puppeteer saat Anda ingin mencari/scrape prospek baru tanpa membebani hosting.

---

## 📦 1. Persiapan File untuk Di-upload ke cPanel

Folder yang **TIDAK PERLU** di-upload ke cPanel (hemat kuota & upload cepat):
- ❌ `node_modules/`
- ❌ `.cache/`

Folder & File yang **WAJIB** di-upload ke `public_html/` (atau subdomain Anda di cPanel):
- ✅ `actions/`
- ✅ `config/`
- ✅ `cron/`
- ✅ `logs/`
- ✅ `services/`
- ✅ `views/`
- ✅ `index.php`
- ✅ `search.php`
- ✅ `settings.php`
- ✅ `schema.sql`
- ✅ `.htaccess` (jika ada)

---

## 🗄️ 2. Setup Database di cPanel

1. Buka **cPanel** > **MySQL Databases**.
2. Buat database baru (misal: `username_mapleads`).
3. Buat user database baru dan password yang kuat.
4. Hubungkan user ke database dengan mencentang **ALL PRIVILEGES**.
5. Buka **phpMyAdmin** di cPanel:
   - Pilih database yang baru dibuat.
   - Klik tab **Import**, pilih file `schema.sql`, lalu klik **Go / Kirim**.
6. Sesuaikan file `config/database.php` di cPanel:
   ```php
   define('DB_HOST', 'localhost');
   define('DB_NAME', 'username_mapleads');
   define('DB_USER', 'username_userdb');
   define('DB_PASS', 'password_anda');
   define('DB_PORT', 3306);
   ```

---

## ⏰ 3. Setup Cron Job Follow-up di cPanel (24/7 Otomatis)

1. Buka **cPanel** > menu **Cron Jobs**.
2. Pada bagian *Common Settings*, pilih: **Once Per 5 Minutes** (`*/5 * * * *`) atau **Once Per 10 Minutes**.
3. Di kolom Command, masukkan perintah:
   ```bash
   /usr/bin/curl -s "https://domain-anda.com/cron/followup_scheduler.php" > /dev/null 2>&1
   ```
   *(Ganti `https://domain-anda.com` dengan URL domain/subdomain web Anda).*

---

## 💻 4. Cara Menghubungkan Scraper di Laptop ke cPanel

Saat Anda ingin melakukan pencarian / scrape prospek Google Maps baru:

### Langkah A: Buka Tunnel di Laptop
Di folder project laptop Anda, cukup jalankan:
```bash
./scraper/start-tunnel.sh
```
*(Script ini otomatis menyalakan scraper worker Node.js dan membuka Cloudflare Tunnel / Ngrok).*

Anda akan mendapatkan URL publik HTTPS, contoh:
```
https://random-name-subdomain.trycloudflare.com
```

### Langkah B: Masukkan URL ke Pengaturan cPanel
1. Buka web MapLeads Pro Anda di cPanel: `https://domain-anda.com/settings.php`.
2. Scroll ke bagian **🤖 Scraper Worker Engine**.
3. Masukkan:
   - **Worker URL**: `https://random-name-subdomain.trycloudflare.com`
   - **API Secret Token**: `mapleads_rahasia123` *(sesuai token di script)*
4. Klik tombol **⚡ Test Koneksi Scraper**. Jika muncul notifikasi hijau `✅ Terhubung!`, klik **Simpan Pengaturan**.

### Selesai! 🎉
Sekarang saat Anda mencari prospek di menu **Pencarian Target Google Maps** di cPanel:
- Permintaan scraping dikerjakan dengan cepat oleh laptop Anda.
- Hasil data otomatis masuk dan tersimpan langsung ke database cPanel.
- Follow-up drip dan pesan WhatsApp tetap berjalan otomatis dari cPanel tanpa harus menyalakan laptop setiap hari.
