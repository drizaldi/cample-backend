# Backend API Cample (Laravel)

REST API untuk sistem aplikasi penyewaan peralatan camping (**Cample**). Backend ini menangani autentikasi pengguna, manajemen inventaris barang & varian, transaksi sewa, pembayaran otomatis Midtrans Snap & Webhook, pencatatan denda otomatis (kerusakan & keterlambatan), serta laporan keuangan.

🔗 **Frontend Mobile App (Flutter):** [https://github.com/drizaldi/cample-mobile](https://github.com/drizaldi/cample-mobile)

---

## 🛠️ Prasyarat Sistem
Sebelum memulai, pastikan perangkat Anda telah terpasang:
- **PHP** >= 8.2
- **Composer**
- **Web Server & Database**: Laragon / XAMPP (MySQL / MariaDB)
- **Ngrok** *(Opsional, hanya dibutuhkan jika ingin menguji Webhook Midtrans secara lokal)*

---

## 🚀 Panduan Setup & Instalasi (Bagi Developer)

Ikuti langkah-langkah berikut setelah melakukan `git clone` repository ini:

### 1. Masuk ke Direktori & Install Dependencies
Buka terminal di dalam folder project ini, lalu jalankan:
```bash
composer install
```

### 2. Konfigurasi Environment (`.env`)
Salin template `.env.example` menjadi file `.env`:
```bash
cp .env.example .env
```
*(Atau salin manual file `.env.example` lalu ubah namanya menjadi `.env`).*

Kemudian buka file `.env` tersebut dan sesuaikan bagian-bagian penting berikut:
1. **Generate App Key**:
   Jalankan perintah ini di terminal untuk membuat kunci enkripsi aplikasi:
   ```bash
   php artisan key:generate
   ```
2. **Pengaturan Database (`MySQL`)**:
   Buat database baru di phpMyAdmin/MySQL Anda (misal bernama `db_cample`), lalu sesuaikan:
   ```env
   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=db_cample
   DB_USERNAME=root
   DB_PASSWORD=
   ```
3. **Pengaturan Midtrans Sandbox (Payment Gateway)**:
   Daftar akun di [Midtrans Sandbox](https://dashboard.sandbox.midtrans.com/) dan masukkan kunci Anda:
   ```env
   MIDTRANS_SERVER_KEY=SB-Mid-server-xxxx
   MIDTRANS_CLIENT_KEY=SB-Mid-client-xxxx
   MIDTRANS_MERCHANT_ID=Gxxxx
   MIDTRANS_IS_PRODUCTION=false
   ```
4. **Pengaturan Webhook Ngrok (Opsional)**:
   ```env
   APP_PUBLIC_URL=https://your-subdomain.ngrok-free.dev
   ```

### 3. Migrasi & Data Database
Jalankan migrasi database:
```bash
php artisan migrate
```
*(Atau import file database `.sql` jika Anda memiliki backup data awal).*

### 4. Buat Storage Link (Untuk Upload Gambar)
Pastikan folder storage terhubung dengan publik:
```bash
php artisan storage:link
```

### 5. Menjalankan Server
- **Jika menggunakan Laragon:** Cukup klik tombol **"Start All"** di Laragon.
- **Jika menggunakan PHP CLI:**
  ```bash
  php artisan serve
  ```

---

## 💳 Testing Webhook Midtrans di Komputer Lokal
Jika Anda ingin menguji pembayaran online Midtrans secara live:
1. Jalankan Ngrok: `ngrok http 80` (atau port yang Anda gunakan).
2. Salin URL HTTPS Ngrok Anda.
3. Buka **Dashboard Midtrans Sandbox** ➔ **Settings** ➔ **Configuration**.
4. Masukkan URL Webhook ke kolom **Payment Notification URL**:
   ```
   https://your-ngrok-url.ngrok-free.dev/backend_cample/public/api/midtrans/callback
   ```
5. Simpan pengaturan. Lakukan simulasi pembayaran di [Midtrans Simulator](https://simulator.sandbox.midtrans.com/).

---

## 📌 Fitur Utama Backend :
- 🔐 **Autentikasi**: Login, Register, Logout, Reset Password via OTP Email.
- 📦 **Inventaris**: Manajemen katalog barang, kategori, varian stok & harga sewa.
- 🏷️ **Diskon Dinamis**: Manajemen diskon per-barang dan promo toko.
- 🛒 **Transaksi & Sewa**: Checkout DP (50%), pelunasan offline, dan sistem pembatalan otomatis.
- ⏰ **Perhitungan Denda Otomatis**: Deteksi keterlambatan pengembalian berbasis tanggal kalender & denda kerusakan fisik.
- 📊 **Laporan & Rekapitulasi**: Ekspor rekap pendapatan sewa, denda, dan cetak PDF laporan berkala.
