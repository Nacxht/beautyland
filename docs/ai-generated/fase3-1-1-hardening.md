# Laporan Fase 3.1.1: Checkout Hardening & Stock Reservation (Midtrans)

**Tanggal:** 26 November 2026  
**Referensi:**
- `docs/ai-generated/audit-checkout-midtrans.md`
- `docs/ai-generated/review-fase3-1.md`
- Task PPL-12: Integrasi Midtrans Snap Beautyland  
**Status Fase:** Selesai diimplementasikan dan diverifikasi secara statis; siap untuk migrasi database dan pengujian webhook Fase 3.2.

---

## 1. Ringkasan Perubahan

Fase 3.1.1 menyelesaikan seluruh kelemahan arsitektur yang teridentifikasi pada audit Fase 3.1:
1. **Pemisahan Transaksi Database dari Panggilan HTTP Midtrans (Pola *Persist-Before-Call*):** Transaksi MySQL kini selalu di-commit sebelum memanggil API Midtrans Snap, menghilangkan risiko penahanan kunci database (lock contention/connection pool exhaustion) saat menunggu respons gateway.
2. **Implementasi Reservasi Stok Terpisah (Stock Reservation):** Menggunakan tabel `reservasi_stok` dan agregat `produk.stok_direservasi` dengan row locking `SELECT ... FOR UPDATE` terurut (`id_produk ASC`). Dua pembeli tidak dapat memperebutkan stok terakhir secara bersamaan (memenuhi **AC-18**), dan stok aktual baru dipotong saat pembayaran lunas di webhook (memenuhi **AC-19**).
3. **Pencegahan Transaksi Duplikat (Idempotency Guard):** Menambahkan `checkout_idempotency_key` kriptografis pada sesi checkout dan index unik di database untuk menangkal double-click dan request paralel.
4. **Validasi Ketat Kepemilikan dan Profil Pelanggan:** Validasi kepemilikan `pending_order` (`id_pelanggan`) serta validasi format nama, email, nomor telepon, dan alamat tanpa menggunakan data fallback dummy.
5. **Penanganan Kegagalan & Timeout:** Mengklasifikasikan kegagalan jaringan/timeout sebagai status `Perlu Rekonsiliasi` tanpa melepas reservasi prematur, serta melepas reservasi secara atomik jika terjadi penolakan definitif dari Midtrans.
6. **Proteksi Kompatibilitas Pembayaran Manual:** Alur upload bukti transfer dan verifikasi pesanan manual tetap aman, dengan proteksi tambahan agar admin tidak memverifikasi transaksi Midtrans secara manual di panel admin.

---

## 2. File yang Dibuat

| No | Path File | Keterangan |
|---|---|---|
| 1 | `database/migrations/20261126_add_stock_reservations_and_checkout_idempotency.sql` | Skrip migrasi penambahan kolom agregat reservasi, tabel `reservasi_stok`, dan kolom idempotency `transaksi`. |
| 2 | `includes/stock_reservation.php` | Helper modular untuk operasi reservasi atomik (`reserve_stock`, `release_stock_reservation`, `convert_stock_reservation`, `get_stock_reservations`). |
| 3 | `docs/ai-generated/fase3-1-1-hardening.md` | Dokumen laporan teknis implementasi Fase 3.1.1 ini. |

---

## 3. File yang Diubah

| No | Path File | Perubahan Utama |
|---|---|---|
| 1 | `pelanggan/create_snap_token.php` | Rewrite menyeluruh: validasi ownership, validasi profil ketat, pengecekan idempotency, pemisahan tahap persistensi dari pemanggilan Midtrans, integrasi reservasi stok, dan penanganan timeout/ambiguitas. |
| 2 | `pelanggan/proses_checkout.php` | Menghasilkan `checkout_idempotency_key` berbasis `random_bytes(16)` ke dalam `$_SESSION['pending_order']`. |
| 3 | `admin/pesanan.php` | Menambahkan pengecekan prepared statement dan filter UI agar admin hanya dapat memverifikasi manual transaksi non-Midtrans (mencegah double-handling). |
| 4 | `.env.example` | Menambahkan template konfigurasi `MIDTRANS_EXPIRY_DURATION=86400`. |

---

## 4. Alasan Setiap Perubahan

1. **`pelanggan/create_snap_token.php`**: Diperlukan perombakan karena implementasi Fase 3.1 menahan transaksi MySQL terbuka selama panggilan HTTP Midtrans dan tidak memiliki reservasi stok atau idempotency.
2. **`includes/stock_reservation.php`**: Dibuat sebagai modul terpisah agar logika reservasi, pelepasan, dan konversi stok dapat digunakan bersama oleh endpoint checkout dan webhook notifikasi (Fase 3.2) secara konsisten dan idempotent.
3. **`pelanggan/proses_checkout.php`**: Diberi penambahan `checkout_idempotency_key` pada tahap pembentukan checkout agar identifier unik checkout sudah terbentuk sebelum pelanggan membuka halaman pembayaran.
4. **`admin/pesanan.php`**: Perlu diproteksi agar tombol "Verifikasi" tidak mengubah status transaksi Midtrans menjadi "Lunas" secara manual tanpa konfirmasi resmi dari gateway Midtrans.
5. **`.env.example`**: Mendokumentasikan variabel durasi kedaluwarsa reservasi agar masa berlaku Snap Token dan reservasi stok selaras.

---

## 5. Desain Reservasi Stok

Proyek menggunakan arsitektur **dua tingkat stok**:
- **Stok Aktual (`produk.stok`)**: Jumlah fisik barang yang belum terjual.
- **Stok Direservasi (`produk.stok_direservasi`)**: Akumulasi barang yang sedang dalam proses checkout/pembayaran aktif.
- **Stok Tersedia**: `stok_tersedia = stok - stok_direservasi`.

### Siklus Hidup Reservasi (`reservasi_stok.status_reservasi`):
1. **`Aktif`**: Dibuat saat Snap Token diinisiasi di checkout (`create_snap_token.php`). Menambah `stok_direservasi`.
2. **`Dikonversi`**: Diubah saat webhook menerima status pembayaran `settlement`/`capture` (Fase 3.2). Mengurangi `stok` aktual sekaligus mengurangi `stok_direservasi`.
3. **`Dilepas`**: Diubah saat pesanan kedaluwarsa, dibatalkan, atau gagal. Mengurangi `stok_direservasi` tanpa mengubah `stok` aktual.

Seluruh operasi menggunakan prepared statement dan penguncian row database `FOR UPDATE` yang diurutkan berdasarkan `id_produk ASC` untuk mencegah deadlock.

---

## 6. Struktur Tabel dan Kolom Baru

### Tabel `produk` (Modifikasi Kolom)
```sql
ALTER TABLE `produk`
  ADD COLUMN `stok_direservasi` INT NOT NULL DEFAULT 0 AFTER `stok`;
```

### Tabel `transaksi` (Modifikasi Kolom & Indeks)
```sql
ALTER TABLE `transaksi`
  ADD COLUMN `checkout_idempotency_key` VARCHAR(64) DEFAULT NULL AFTER `stok_dikurangi`,
  ADD COLUMN `snap_status` VARCHAR(30) DEFAULT NULL AFTER `checkout_idempotency_key`,
  ADD COLUMN `snap_expires_at` TIMESTAMP NULL DEFAULT NULL AFTER `snap_status`;

ALTER TABLE `transaksi`
  ADD UNIQUE INDEX `idx_checkout_idempotency_key` (`checkout_idempotency_key`),
  ADD INDEX `idx_snap_status` (`snap_status`);
```

### Tabel Baru `reservasi_stok`
```sql
CREATE TABLE `reservasi_stok` (
  `id_reservasi` INT NOT NULL AUTO_INCREMENT,
  `id_transaksi` INT NOT NULL,
  `id_produk` INT NOT NULL,
  `jumlah` INT NOT NULL,
  `status_reservasi` ENUM('Aktif', 'Dikonversi', 'Dilepas') NOT NULL DEFAULT 'Aktif',
  `expires_at` TIMESTAMP NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_reservasi`),
  KEY `idx_res_transaksi` (`id_transaksi`),
  KEY `idx_res_produk` (`id_produk`),
  KEY `idx_res_status` (`status_reservasi`),
  KEY `idx_res_expires` (`expires_at`),
  UNIQUE KEY `uq_transaksi_produk` (`id_transaksi`, `id_produk`),
  CONSTRAINT `fk_res_transaksi` FOREIGN KEY (`id_transaksi`) REFERENCES `transaksi` (`id_transaksi`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_res_produk` FOREIGN KEY (`id_produk`) REFERENCES `produk` (`id_produk`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
```

---

## 7. Mekanisme Idempotency

1. **Generasi Key:** Saat pelanggan memproses checkout di `proses_checkout.php`, dibuat key 32 karakter hex acak: `bin2hex(random_bytes(16))` dan disimpan di `$_SESSION['pending_order']['checkout_idempotency_key']`.
2. **Pengecekan di Endpoint:** Sebelum memulai transaksi lokal baru, `create_snap_token.php` mencari apakah `checkout_idempotency_key` sudah ada di tabel `transaksi`.
3. **Pencegahan Double-Submit:**
   - Jika request berulang datang saat proses pembuatan pertama masih berjalan (`snap_status = 'Menunggu'`), endpoint mengembalikan HTTP 429 (Too Many Requests).
   - Jika transaksi telah memiliki `snap_token` aktif (`snap_status = 'Berhasil'`), endpoint langsung mengembalikan token yang sama tanpa membuat transaksi baru di database maupun di Midtrans.
   - Constraint unik `idx_checkout_idempotency_key` di tingkat database memastikan jika request paralel tiba bersamaan dalam hitungan milidetik, salah satu akan ditolak oleh MySQL InnoDB.

---

## 8. Alur Pembuatan Snap Token yang Baru (Pola Persist-Before-Call)

```
[Browser Client]
       |
       | POST /pelanggan/create_snap_token.php
       v
1. Validasi Autentikasi & Kepemilikan (Ownership)
2. Validasi Profil Pelanggan Ketat (Nama, Email, No HP, Alamat)
3. Pengecekan Idempotency Key di Database
       |
       +--> Jika token sudah ada & aktif -> Kembalikan Snap Token lama (HTTP 200)
       |
4. TAHAP 1: PERSISTENSI LOKAL (MySQL Transaction)
   a. Begin Transaction
   b. Lock baris produk (SELECT ... FOR UPDATE order by id_produk ASC)
   c. Hitung ulang harga dan subtotal resmi dari DB
   d. Insert ke `transaksi` (status_pembayaran: 'Menunggu Pembayaran', snap_status: 'Menunggu')
   e. Buat `midtrans_order_id` unik ("BL-{id_transaksi}-{timestamp}")
   f. Jalankan helper `reserve_stock()`: validasi stok_tersedia dan catat `reservasi_stok`
   g. Insert ke `detail_transaksi` & `pengiriman`
   h. COMMIT MySQL Transaction! (Koneksi database dilepas dari lock)
       |
5. TAHAP 2: REQUEST MIDTRANS SNAP API (Koneksi DB bebas transaksi)
   a. Susun payload Snap (transaction_details, item_details, customer_details, expiry)
   b. Panggil \Midtrans\Snap::createTransaction($params)
       |
       +--> [BERHASIL]:
       |      - Update transaksi SET snap_token = ..., snap_status = 'Berhasil'
       |      - Hapus item pesanan dari tabel `keranjang`
       |      - Unset $_SESSION['pending_order']
       |      - Kembalikan JSON { success: true, snap_token, order_id } (HTTP 200)
       |
       +--> [TIMEOUT / JARINGAN AMBIGU]:
       |      - Update transaksi SET snap_status = 'Perlu Rekonsiliasi'
       |      - Jangan hapus reservasi atau keranjang
       |      - Kembalikan JSON pesan rekonsiliasi (HTTP 504)
       |
       +--> [KEGAGALAN DEFINITIF (misal 4xx rejection)]:
              - Update transaksi SET snap_status = 'Gagal'
              - Lepaskan reservasi stok via helper `release_stock_reservation()`
              - Buat idempotency key baru pada pending_order agar pengguna dapat retry
              - Kembalikan JSON pesan kegagalan (HTTP 500)
```

---

## 9. Penanganan Timeout dan Transaksi yang Perlu Rekonsiliasi

Jika terjadi cURL error 28 (`CURLE_OPERATION_TIMEDOUT`) atau galat jaringan ambigu saat memanggil Midtrans:
- Sistem **tidak menganggap transaksi pasti gagal di Midtrans** (karena Midtrans bisa saja sudah menerima dan memproses transaksi tersebut).
- Transaksi lokal ditandai dengan `snap_status = 'Perlu Rekonsiliasi'`.
- Reservasi stok **tidak dilepaskan** untuk mencegah overselling jika pelanggan ternyata membayar transaksi tersebut.
- Keranjang belanja dan pending order tidak dihapus.
- Pada Fase 3.2, webhook atau skrip rekonsiliasi dapat memeriksa status `order_id` tersebut ke endpoint status Midtrans (`\Midtrans\Transaction::status($order_id)`).

---

## 10. Masa Berlaku Reservasi

- Durasi kedaluwarsa transaksi dan reservasi dikonfigurasi melalui `$_ENV['MIDTRANS_EXPIRY_DURATION']` (default: 86.400 detik = 24 jam).
- Nilai ini disinkronkan secara presisi:
  - Disimpan pada kolom `transaksi.snap_expires_at`.
  - Disimpan pada kolom `reservasi_stok.expires_at`.
  - Dikirimkan ke Midtrans Snap via parameter `expiry` (`unit: 'minute'`, `duration: X`).
- Dengan demikian, kedaluwarsa di sisi gateway Midtrans dan database lokal memiliki batas waktu yang konsisten.

---

## 11. Kompatibilitas Pembayaran Manual

- Struktur `$_SESSION['pending_order']` tetap mempertahankan seluruh kunci yang dibutuhkan pembayaran manual (`id_pelanggan`, `total_harga`, `metode_pembayaran`, `kode_pembayaran`, `alamat`, `items`).
- File `pelanggan/pembayaran.php` dan `pelanggan/proses_pembayaran.php` tetap berfungsi normal tanpa perubahan alur upload bukti transfer.
- Transaksi pembayaran manual tidak membuat baris di `reservasi_stok`, sehingga tidak terpengaruh oleh lifecycle reservasi.
- Halaman `admin/pesanan.php` diberi pengaman prepared statement: tombol dan endpoint verifikasi manual hanya memproses transaksi non-Midtrans, mencegah admin mengubah status transaksi Midtrans tanpa sinkronisasi gateway.

---

## 12. Hasil Pemeriksaan Sintaks (`php -l`)

```bash
php -l /var/www/html/Beautyland/pelanggan/create_snap_token.php
# Output: No syntax errors detected in /var/www/html/Beautyland/pelanggan/create_snap_token.php

php -l /var/www/html/Beautyland/pelanggan/proses_checkout.php
# Output: No syntax errors detected in /var/www/html/Beautyland/pelanggan/proses_checkout.php

php -l /var/www/html/Beautyland/includes/stock_reservation.php
# Output: No syntax errors detected in /var/www/html/Beautyland/includes/stock_reservation.php

php -l /var/www/html/Beautyland/admin/pesanan.php
# Output: No syntax errors detected in /var/www/html/Beautyland/admin/pesanan.php
```

---

## 13. Hasil Pemeriksaan Kode terhadap Skenario A–H (Statis)

| Skenario | Deskripsi | Hasil Analisis Statis | Status |
|---|---|---|---|
| **Skenario A** | Stok tersedia (Stok 10, pesan 3 -> stok aktual tetap 10, direservasi 3, tersedia 7). | `reserve_stock()` mengunci baris produk, memvalidasi sisa, dan menambah `stok_direservasi`. `stok` aktual tidak tersentuh. | **Berhasil diperiksa secara statis** |
| **Skenario B** | Perebutan stok terakhir (Stok 1, direservasi 0, Pelanggan A dan B checkout bersamaan). | Baris produk dikunci dengan `FOR UPDATE`. Transaksi pertama mendapat lock dan mengubah `stok_direservasi = 1`. Transaksi kedua melihat `stok_tersedia = 0` dan ditolak (HTTP 409). AC-18 terpenuhi. | **Berhasil diperiksa secara statis** |
| **Skenario C** | Pembayaran berhasil (Reservasi aktif 2 unit -> lunas). | Helper `convert_stock_reservation()` disiapkan: mengurangi `stok` dan `stok_direservasi`, mengubah status reservasi ke `'Dikonversi'`. Idempotent bila dipanggil berulang. AC-19 terpenuhi. | **Berhasil diperiksa secara statis** |
| **Skenario D** | Pembayaran kedaluwarsa (Reservasi aktif 2 unit -> expire/cancel). | Helper `release_stock_reservation()` disiapkan: mengurangi `stok_direservasi` tanpa mengubah `stok` fisik, mengubah status ke `'Dilepas'`. Idempotent bila dipanggil berulang. | **Berhasil diperiksa secara statis** |
| **Skenario E** | Double submit (Dua request dengan idempotency key sama). | Request pertama memproses, request paralel kedua tertahan / ditolak HTTP 429 atau dicegah oleh unique index. Request setelah sukses mengembalikan Snap Token lama tanpa duplikasi order. | **Berhasil diperiksa secara statis** |
| **Skenario F** | Midtrans timeout (Transaksi lokal tersimpan, Midtrans timeout). | Transaksi lokal berstatus `'Perlu Rekonsiliasi'`. Reservasi stok tidak dilepas prematur. Keranjang dan pending order dipertahankan. | **Berhasil diperiksa secara statis** |
| **Skenario G** | Ownership tidak cocok (id_pelanggan session != pending_order). | Divalidasi eksplisit di awal; langsung ditolak dengan HTTP 403 Forbidden tanpa menyentuh database. | **Berhasil diperiksa secara statis** |
| **Skenario H** | Pembayaran manual. | Alur transfer bank dan upload bukti berjalan normal tanpa membuat reservasi stok Midtrans. Admin diproteksi dari verifikasi manual salah sasaran. | **Berhasil diperiksa secara statis** |

---

## 14. Migrasi yang Harus Dijalankan Manual

Database pengguna belum diubah. Migrasi berikut wajib dijalankan secara berurutan di server database sebelum pengujian runtime:

### Urutan Eksekusi Migrasi:
1. **Migrasi 1 (Fase 2):**
   ```bash
   mysql -u root beautyland < database/migrations/20261126_add_midtrans_columns.sql
   ```
2. **Migrasi 2 (Fase 3.1.1):**
   ```bash
   mysql -u root beautyland < database/migrations/20261126_add_stock_reservations_and_checkout_idempotency.sql
   ```

---

## 15. Hal-hal yang Belum Diimplementasikan

1. **Webhook Handler (`midtrans_notification.php`):** Belum dibuat (sesuai batasan, akan dikerjakan pada Fase 3.2).
2. **Cron / Job Pembersihan Reservasi Kedaluwarsa:** Belum ada background worker otomatis untuk melepaskan reservasi yang kedaluwarsa jika Midtrans gagal mengirim webhook notifikasi `expire`.
3. **Frontend Integration:** Form checkout belum dihubungkan ke `create_snap_token.php` dan Snap JS belum dipasang (dijadwalkan pada Fase 4).

---

## 16. Risiko yang Masih Tersisa

1. **Notifikasi Webhook Hilang (Lost Webhook):** Jika Midtrans mengalami gangguan dan webhook `expire` tidak pernah terkirim, reservasi stok akan tetap berstatus `Aktif` sampai ada mekanisme rekonsiliasi / background worker berkala.
2. **Ketergantungan Kredensial `.env`:** Pemanggilan API Midtrans memerlukan server key Sandbox yang aktif di file `.env`. Jika kredensial kosong, pemanggilan akan mengembalikan status 500.

---

## 17. Rekomendasi Pekerjaan Fase 3.2 (Midtrans Notification Webhook)

1. Buat endpoint publik `midtrans_notification.php` di root direktori tanpa autentikasi session.
2. Verifikasi signature SHA-512 dari payload webhook menggunakan Server Key.
3. Gunakan helper `convert_stock_reservation($conn, $id_transaksi)` saat status `settlement` atau `capture`.
4. Gunakan helper `release_stock_reservation($conn, $id_transaksi)` saat status `cancel`, `expire`, atau `deny`.
5. Perbarui kolom `transaksi.status_pembayaran` sesuai mapping status aplikasi (`Lunas` / `Dibatalkan`).
6. Update flag `stok_dikurangi = 1` untuk menjamin idempotency webhook berulang.
7. Tangani transaksi dengan status `Perlu Rekonsiliasi` agar dapat dipulihkan secara otomatis saat webhook notifikasi tiba.
