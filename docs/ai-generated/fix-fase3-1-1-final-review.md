# Laporan Perbaikan Temuan Final Review Fase 3.1.1 — Beautyland

**Repository:** Beautyland (PHP Native, MySQLi, Midtrans Snap)  
**Tanggal Perbaikan:** 26 November 2026  
**Dokumen Rujukan:** `docs/ai-generated/final-review-fase3-1-1.md`  
**File yang Dimodifikasi:** `pelanggan/create_snap_token.php`  
**Status Keseluruhan:** **FIXED** (B-01: FIXED, W-01: FIXED, W-02: FIXED). Siap dilanjutkan ke Fase 3.2.

---

## 1. Ringkasan Perubahan

Berdasarkan hasil audit `final-review-fase3-1-1.md`, telah dilakukan perbaikan presisi pada endpoint `pelanggan/create_snap_token.php` untuk menuntaskan tiga temuan:
1. **B-01 (Duplicate Key pada Request Paralel):** Menghilangkan respons HTTP 500 saat terjadi tabrakan kunci unik `idx_checkout_idempotency_key`. Transaksi request kedua langsung di-rollback, kemudian di-query ulang transaksi pertama untuk mengembalikan status/token asli sesuai siklus hidupnya (HTTP 429 atau HTTP 200).
2. **W-02 (Perlindungan Reservasi Pasca-Penerbitan Token):** Memisahkan blok penanganan kesalahan pemanggilan API Midtrans dari blok penyimpanan token ke database. Jika Midtrans telah berhasil menerbitkan Snap Token namun penyimpanan lokal gagal, sistem **tidak** menandai transaksi `Gagal` dan **tidak** melepaskan reservasi stok. Transaksi ditandai untuk rekonsiliasi.
3. **W-01 (Pemulihan Transaksi 'Menunggu' yang Tertahan):** Mengimplementasikan batas toleransi waktu (120 detik) untuk transaksi berstatus `Menunggu`. Jika proses sebelumnya terhenti akibat crash/timeout, transaksi secara aman ditandai sebagai `Perlu Rekonsiliasi` menggunakan conditional atomic update tanpa melepas reservasi atau memicu transaksi ganda.

---

## 2. File yang Dibuat dan Dimodifikasi

| No | Path File | Aksi | Keterangan |
|---|---|---|---|
| 1 | `pelanggan/create_snap_token.php` | Modifikasi | Implementasi perbaikan B-01, W-01, dan W-02 dengan helper fungsi `handle_existing_transaction_response()`. |
| 2 | `docs/ai-generated/fix-fase3-1-1-final-review.md` | Dibuat | Laporan verifikasi perbaikan teknis ini. |

*Catatan: Tidak ada perubahan skema database, migrasi tambahan, perubahan file `.env`, maupun modifikasi pada file pembayaran manual.*

---

## 3. Detail Perbaikan B-01, W-01, dan W-02

### 3.1. B-01 — Penanganan Duplicate Key pada Request Paralel

#### Kondisi Sebelum Perbaikan:
Ketika dua request paralel dengan `checkout_idempotency_key` yang sama tiba sebelum salah satu commit, Request 2 menabrak constraint unik `idx_checkout_idempotency_key` pada query `INSERT INTO transaksi`. Error MySQL 1062 ditangkap oleh blok generic `catch (\Throwable $e)` dan menghasilkan **HTTP 500 ("Terjadi kesalahan sistem")**.

#### Alur Setelah Perbaikan:
1. Blok `catch` pada Tahap 1 mendeteksi secara spesifik apakah error berkode `1062` dan berasal dari `idx_checkout_idempotency_key`.
2. Jika terdeteksi:
   - Request saat ini langsung memanggil `$conn->rollback()`.
   - Melakukan query ulang ke tabel `transaksi` berdasarkan `checkout_idempotency_key`.
   - Memvalidasi kepemilikan: `(int)$dup_trx['id_pelanggan'] === $id_pelanggan`.
   - Mengalihkan penanganan ke fungsi terpusat `handle_existing_transaction_response($conn, $dup_trx)`.
3. Hasil bagi pengguna:
   - Jika Request 1 masih dalam proses pemanggilan Midtrans: Request 2 menerima **HTTP 429 ("Sesi pembayaran sedang diproses oleh sistem")**.
   - Jika Request 1 telah berhasil: Request 2 menerima **HTTP 200** beserta `snap_token` dan `order_id` yang sama (`"idempotent": true`).
   - Tidak ada transaksi ganda, tidak ada reservasi ganda, dan **tidak ada HTTP 500**.

---

### 3.2. W-02 — Perlindungan Reservasi Stok Setelah Midtrans Menerbitkan Token

#### Kondisi Sebelum Perbaikan:
Pemanggilan `\Midtrans\Snap::createTransaction($params)` dan penyimpanan token (`UPDATE transaksi SET snap_token = ...`) berada dalam satu blok `try...catch` yang sama. Jika query `UPDATE` gagal setelah token terbit, script masuk ke Tahap 3C yang menandai status `Gagal` dan memanggil `release_stock_reservation()`. Hal ini menyebabkan reservasi stok fisik dilepas padahal di server Midtrans transaksinya aktif.

#### Alur Setelah Perbaikan:
Kode dipisahkan menjadi dua blok penanganan yang tegas dan independen:
1. **Blok Panggilan Midtrans (Tahap 2):**
   - Menguji apakah token berhasil diterima dari Midtrans.
   - Jika timeout/jaringan: tandai `Perlu Rekonsiliasi`, jangan lepas reservasi, respons HTTP 504.
   - Jika penolakan definitif sebelum token terbit: tandai `Gagal`, lepas reservasi secara atomik, buat idempotency key baru, respons HTTP 500.
2. **Blok Penyimpanan Token Database (Tahap 3A):**
   - Hanya dieksekusi setelah Midtrans mengonfirmasi keberhasilan penerbitan token.
   - Jika terjadi kegagalan query `UPDATE snap_token`:
     - **DILARANG** menandai transaksi sebagai `Gagal`.
     - **DILARANG** memanggil `release_stock_reservation()`.
     - **DILARANG** membuat idempotency key baru.
     - **Reservasi stok tetap dipertahankan utuh.**
     - Sistem berupaya menandai status transaksi sebagai `Perlu Rekonsiliasi` jika koneksi database masih merespons.
     - Error dicatat ke log server secara aman tanpa memuat Server Key.
     - Respons HTTP 500 memberi tahu pengguna bahwa transaksi tercatat dan ditandai untuk rekonsiliasi.

---

### 3.3. W-01 — Penanganan Aman Status 'Menunggu' yang Tertahan

#### Kondisi Sebelum Perbaikan:
Jika proses PHP mati mendadak setelah Tahap 1 (persistensi lokal) selesai di-commit namun sebelum respons Midtrans tersimpan, `snap_status` akan selamanya bernilai `'Menunggu'`. Setiap kali pelanggan mencoba checkout ulang, sistem merespons HTTP 429 tanpa batas waktu, menyebabkan pelanggan terkunci permanen.

#### Alur Setelah Perbaikan:
1. Menambahkan evaluasi waktu pada baris `handle_existing_transaction_response()`:
   ```php
   $time_diff = $updated_at !== '' ? (time() - strtotime((string)$updated_at)) : 0;
   ```
2. **Jika `$time_diff < 120` detik:** Dianggap request sedang berjalan normal; respons HTTP 429 ("Sesi pembayaran sedang diproses").
3. **Jika `$time_diff >= 120` detik:**
   - Diidentifikasi bahwa proses sebelumnya terhenti/crash.
   - Sistem **TIDAK** mengubah status menjadi `Gagal`.
   - Sistem **TIDAK** melepas reservasi stok.
   - Sistem **TIDAK** membuat order ID baru secara sembarangan.
   - Menggunakan kueri bersyarat atomik untuk mencegah race condition antar-request:
     ```sql
     UPDATE transaksi 
     SET snap_status = 'Perlu Rekonsiliasi' 
     WHERE id_transaksi = ? AND snap_status = 'Menunggu'
     ```
   - Mengembalikan respons HTTP 409 dengan pesan bahwa transaksi ditandai untuk rekonsiliasi dan reservasi stok tetap aman.
   - Pada Fase 3.2, Webhook notifikasi atau skrip rekonsiliasi dapat menyinkronkan status pesanan ini dengan memeriksa `\Midtrans\Transaction::status($midtrans_order_id)`.

---

## 4. Alur Lengkap Sebelum dan Sesudah Perbaikan

```
================================================================================
ALUR SEBELUM PERBAIKAN:
================================================================================
Request Paralel -> INSERT duplicate key -> generic catch -> HTTP 500 (B-01)
Midtrans sukses -> UPDATE snap_token fail -> catch -> lepas reservasi & status Gagal (W-02)
PHP crash -> snap_status = 'Menunggu' -> selamanya stuck di HTTP 429 (W-01)

================================================================================
ALUR SETELAH PERBAIKAN:
================================================================================
Request Paralel -> INSERT duplicate key 1062
                   |--> Rollback transaksi lokal
                   |--> Query ulang transaksi pertama
                   |--> Validasi kepemilikan pelanggan
                   +--> Return HTTP 429 (jika masih proses) atau HTTP 200 (jika sukses) [B-01 FIXED]

Midtrans Sukses -> Panggil createTransaction() sukses
                   |--> Blok terpisah: UPDATE snap_token
                   +--> Jika UPDATE DB fail:
                        |-- Jangan lepas reservasi stok!
                        |-- Jangan tandai 'Gagal'!
                        +-- Tandai 'Perlu Rekonsiliasi' & log error [W-02 FIXED]

PHP Crash -> snap_status = 'Menunggu'
             |--> Jika usia < 120 detik: HTTP 429
             +--> Jika usia >= 120 detik:
                  |-- Atomic UPDATE snap_status = 'Perlu Rekonsiliasi'
                  |-- Reservasi stok tetap AMAN
                  +-- Return HTTP 409 [W-01 FIXED]
```

---

## 5. Hasil Pemeriksaan Sintaks (`php -l`)

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

## 6. Hasil Pemeriksaan Statis

| Komponen Pengujian | Metode Verifikasi | Hasil |
|---|---|---|
| **Deteksi MySQL 1062** | Memeriksa `$conn->errno === 1062` dan nama constraint `checkout_idempotency_key`. | **Lolos:** Hanya merespons duplicate key idempotency, error lain tetap tercatat sebagai system error. |
| **Isolasi Transaksi Kedua** | Memeriksa ketiadaan pemanggilan `reserve_stock()` pada request yang duplicate key. | **Lolos:** Request kedua langsung di-rollback sebelum menyentuh reservasi stok. |
| **Pemisahan Blok Try-Catch** | Memeriksa alur kode baris 452-549 pada `create_snap_token.php`. | **Lolos:** Blok pemanggilan Midtrans dan blok penyimpanan DB terpisah secara tegas. |
| **Ketahanan Reservasi W-02** | Memeriksa exception path pada blok penyimpanan token. | **Lolos:** Tidak ada instruksi `release_stock_reservation()` di dalam blok `catch ($db_err)`. |
| **Batas Usia 'Menunggu' W-01** | Memeriksa perhitungan `$time_diff >= 120` dan query UPDATE bersyarat. | **Lolos:** Transaksi macet bertransisi ke `Perlu Rekonsiliasi` secara atomik. |
| **Kompatibilitas Manual** | Memeriksa file pembayaran manual dan `admin/pesanan.php`. | **Lolos:** Tidak ada perubahan pada alur pembayaran transfer bank konvensional. |

---

## 7. Skenario yang Belum Dapat Diuji Secara Runtime

Karena batasan lingkungan akademik (tidak menjalankan database live, migrasi belum dieksekusi pengguna, dan tidak melakukan panggilan HTTP riil ke gateway Midtrans), pengujian berikut belum dapat diuji secara langsung:
1. **Simulasi High-Concurrency Parallel cURL:** Mengirim 20 request paralel dalam interval 5 milidetik dengan idempotency key yang sama ke server web Apache/Nginx aktif.
2. **Simulasi Network Packet Drop Midtrans:** Menguji cURL timeout 28 secara riil menggunakan network traffic shaper/proxy.
3. **Simulasi Pemutusan Koneksi MySQL Pasca-Snap:** Mematikan daemon mysqld tepat setelah Midtrans mengembalikan token untuk menguji blok `catch ($db_err)`.

---

## 8. Risiko Tersisa

1. **Sinkronisasi Otomatis Transaksi 'Perlu Rekonsiliasi':** Transaksi yang masuk ke status `Perlu Rekonsiliasi` (akibat timeout atau database glitch) saat ini membutuhkan penanganan oleh Webhook atau skrip rekonsiliasi yang akan dibangun pada Fase 3.2.
2. **Ketergantungan Eksekusi Migrasi Database:** Kode ini mengandalkan kolom dan tabel yang tercantum pada dua file migrasi (`20261126_add_midtrans_columns.sql` dan `20261126_add_stock_reservations_and_checkout_idempotency.sql`). Migrasi harus dijalankan sebelum endpoint ini diuji di browser.

---

## 9. Status Masing-Masing Temuan

| ID Temuan | Kategori | Status Akhir | Justifikasi |
|---|---|---|---|
| **B-01** | Duplicate Key pada Request Paralel | **FIXED** | Error 1062 ditangani spesifik, transaksi di-rollback, transaksi pertama di-query ulang, mengembalikan HTTP 429 atau HTTP 200 tanpa HTTP 500. |
| **W-01** | Transaksi 'Menunggu' Tertahan Permanen | **FIXED** | Batas toleransi 120 detik diterapkan; transaksi yang melebihi batas bertransisi ke `Perlu Rekonsiliasi` secara atomik tanpa melepas stok. |
| **W-02** | Pelepasan Reservasi jika UPDATE Gagal Pasca-Midtrans | **FIXED** | Blok Midtrans dipisahkan dari blok UPDATE DB; kegagalan penyimpanan lokal tidak melepas reservasi dan ditandai untuk rekonsiliasi. |

---

## 10. Rekomendasi Kesiapan Proyek

**Kesimpulan:**  
Seluruh kriteria penerimaan perbaikan audit telah terpenuhi dan diverifikasi secara statis tanpa error sintaks.

**Rekomendasi:**  
Proyek Beautyland **SUDAH SIAP** untuk:
1. Melakukan verifikasi pre-flight database dan menjalankan migrasi skema.
2. Melanjutkan ke implementasi **Fase 3.2 — Midtrans Notification Webhook** (`midtrans_notification.php`).
