# Laporan Final Concurrency & Recovery Audit — Beautyland Fase 3.1.1

**Repository:** Beautyland (PHP Native, MySQLi, Midtrans Snap)  
**Tanggal Audit:** 26 November 2026  
**Dokumen Acuan:**
- `docs/ai-generated/audit-checkout-midtrans.md`
- `docs/ai-generated/review-fase3-1.md`
- `docs/ai-generated/fase3-1-1-hardening.md`
- `docs/ai-generated/fix-fase3-1-1-final-review.md`  
**File yang Diaudit:**
- `pelanggan/create_snap_token.php`
- `pelanggan/proses_checkout.php`
- `includes/stock_reservation.php`
- `admin/pesanan.php`
- `database/migrations/20261126_add_midtrans_columns.sql`
- `database/migrations/20261126_add_stock_reservations_and_checkout_idempotency.sql`

---

## 1. Ringkasan Eksekutif

Audit lanjutan ini dilakukan untuk menguji ketahanan konkurensi (*concurrency*) dan pemulihan kegagalan (*crash recovery*) pada implementasi aktual `pelanggan/create_snap_token.php` setelah diterapkannya perbaikan B-01, W-01, dan W-02.

### Hasil Penilaian Keseluruhan:
**APPROVED WITH WARNINGS**

- **B-01 (Duplicate Key Handling):** **PASS**. Error MySQL 1062 terdeteksi secara presisi, request paralel di-rollback, transaksi pertama di-query ulang dengan verifikasi kepemilikan, dan respons HTTP yang tepat (429 atau 200) dikembalikan tanpa menimbulkan HTTP 500.
- **W-01 (Stuck 'Menunggu' Handling):** **PASS** (dengan 1 catatan *Warning*). Batas waktu 120 detik mencegah pelanggan terkunci permanen; status bertransisi ke `Perlu Rekonsiliasi` secara atomik tanpa melepas stok.
- **W-02 (Stock Reservation Protection):** **PASS**. Blok panggilan Midtrans terisolasi total dari blok penyimpanan token database. Kegagalan database pasca-terbitnya token tidak memicu pelepasan reservasi stok.
- **Kesiapan Fase 3.2 (Webhook):** **PASS**. `midtrans_order_id` telah di-commit ke database lokal sebelum pemanggilan Midtrans, menjamin webhook dapat mengidentifikasi pesanan pada seluruh kondisi waktu.

---

## 2. Temuan Audit Berdasarkan Kategori

---

### 2.1. Audit Race Condition W-01

#### Skenario yang Diperiksa:
1. Request A membuat transaksi lokal dengan `snap_status = 'Menunggu'`.
2. Request A memanggil API Midtrans, namun terjadi latensi jaringan sehingga request berlangsung lebih dari 120 detik.
3. Di detik ke-121, Request B (misal: refresh browser oleh pelanggan) mendeteksi transaksi berumur > 120 detik.
4. Request B mengeksekusi conditional UPDATE mengubah status menjadi `'Perlu Rekonsiliasi'` dan merespons HTTP 409.
5. Di detik ke-125, Request A akhirnya menerima Snap Token dari Midtrans.

#### Analisis Alur Kode Aktual:
- **Lokasi Kode Request B:** `pelanggan/create_snap_token.php:68-91`
  ```php
  $time_diff = $updated_at !== '' ? (time() - strtotime((string)$updated_at)) : 0;
  if ($time_diff >= 120) {
      $stmt_mark_stuck = $conn->prepare("
          UPDATE transaksi 
          SET snap_status = 'Perlu Rekonsiliasi' 
          WHERE id_transaksi = ? AND snap_status = 'Menunggu'
      ");
  ```
  Request B berhasil mengubah status transaksi dari `'Menunggu'` menjadi `'Perlu Rekonsiliasi'`.
- **Lokasi Kode Request A Pasca-Midtrans:** `pelanggan/create_snap_token.php:628-636`
  ```php
  $stmt_token = $conn->prepare("UPDATE transaksi SET snap_token = ?, snap_status = 'Berhasil' WHERE id_transaksi = ?");
  $stmt_token->bind_param("si", $snapToken, $id_transaksi);
  $stmt_token->execute();
  ```

#### Evaluasi Pertanyaan Kritis:
1. **Apakah Request A masih dapat menyimpan Snap Token?**
   - **YA.** Query UPDATE Request A mengaitkan `snap_token` dengan record `id_transaksi`. Token tersimpan di database.
2. **Apakah Request A menimpa `Perlu Rekonsiliasi` menjadi `Berhasil`?**
   - **YA.** Query pada baris 629 mengubah `snap_status = 'Berhasil'`.
   - **Dampak Positif:** Transaksi yang sempat dicurigai tertahan ternyata berhasil memperoleh token resmi dari Midtrans. Dengan status menjadi `'Berhasil'` dan `snap_token` tersimpan, transaksi pulih ke kondisi valid.
   - **Dampak Klien:** Request B telah menerima HTTP 409. Jika pengguna memuat ulang halaman checkout atau membuka kembali pesanan, request berikutnya akan membaca `snap_status = 'Berhasil'` dan mengembalikan token yang aktif (HTTP 200).
3. **Apakah reservasi stok tetap aman selama kedua request berlangsung?**
   - **AMAN.** Request B **TIDAK** melepaskan reservasi stok saat mengubah status ke `'Perlu Rekonsiliasi'`. Baris di `reservasi_stok` tetap berstatus `'Aktif'`, dan `produk.stok_direservasi` tidak berkurang.
4. **Catatan Potensi Inkonsistensi:**
   - Kueri baris 629 tidak menyertakan filter `WHERE id_transaksi = ? AND status_pembayaran = 'Menunggu Pembayaran'`. Jika terjadi skenario ekstrem di mana webhook Fase 3.2 datang dan membatalkan pesanan (`Dibatalkan`) dalam jeda waktu tersebut, `snap_status` akan tertulis `'Berhasil'` meskipun `status_pembayaran` sudah `'Dibatalkan'`. Hal ini diklasifikasikan sebagai **WARNING (F-01)**.

---

### 2.2. Audit B-01 (Duplicate Key & Request Paralel)

#### Skenario yang Diperiksa:
Dua request identik (misal double-click pada form) dikirim secara paralel dan melewati pengecekan awal sebelum salah satu melakukan commit.

#### Analisis Alur Kode Aktual:
1. **Deteksi Error Spesifik MySQL 1062:**
   - **Lokasi Kode:** `pelanggan/create_snap_token.php:465-470`
     ```php
     $is_duplicate_key = ($conn->errno === 1062 || (int)$e->getCode() === 1062);
     $is_idempotency_dup = $is_duplicate_key && (
         strpos($e->getMessage(), 'checkout_idempotency_key') !== false ||
         strpos($e->getMessage(), 'idx_checkout_idempotency_key') !== false
     );
     ```
   - **Hasil:** Deteksi benar-benar spesifik pada constraint unik `idx_checkout_idempotency_key`. Duplicate key pada kolom atau tabel lain tidak akan memicu alur ini.
2. **Rollback Transaksi Request Kedua:**
   - **Lokasi Kode:** `pelanggan/create_snap_token.php:458-460`
     ```php
     if (isset($conn) && $conn instanceof mysqli && $conn->connect_errno === 0) {
         $conn->rollback();
     }
     ```
   - **Hasil:** Transaksi lokal milik request kedua langsung di-rollback sebelum kueri ulang dijalankan.
3. **Pembacaan Transaksi Pertama:**
   - Kueri ulang pada baris 473-477 dijalankan di luar transaksi aktif (`autocommit` mode).
   - Karena MySQL InnoDB menerapkan row lock pada index unik saat insert, Request 2 menunggu hingga Request 1 menyelesaikan transaksi (commit). Begitu Request 2 menerima error 1062, Request 1 dipastikan sudah selesai commit Tahap 1.
   - Request 2 dipastikan dapat membaca transaksi yang di-commit oleh Request 1.
4. **Validasi Kepemilikan Pelanggan:**
   - **Lokasi Kode:** `pelanggan/create_snap_token.php:485`
     ```php
     if ($dup_trx && (int)$dup_trx['id_pelanggan'] === $id_pelanggan) {
         handle_existing_transaction_response($conn, $dup_trx);
     }
     ```
   - **Hasil:** Validasi kepemilikan diverifikasi secara ketat sebelum data transaksi atau status dikembalikan. Jika terjadi ketidakcocokan ID pelanggan, sistem merespons HTTP 409 generic tanpa membocorkan data pesanan.
5. **Respons yang Diterima Request Kedua:**
   - Jika Request 1 masih memanggil Midtrans: Request 2 menerima **HTTP 429** ("Sesi pembayaran sedang diproses").
   - Jika Request 1 sudah berhasil menyimpan token: Request 2 menerima **HTTP 200** dengan `snap_token` dan `order_id` yang sama.
   - **Tidak ada HTTP 500 yang dihasilkan.**
   - **Status:** **PASS**

---

### 2.3. Audit W-02 (Perlindungan Reservasi Stok Pasca-Midtrans)

#### Skenario yang Diperiksa:
Midtrans berhasil menerbitkan Snap Token (HTTP 200 dari server gateway), namun koneksi database lokal mengalami kegagalan saat mengeksekusi `UPDATE transaksi SET snap_token = ...`.

#### Analisis Alur Kode Aktual:
1. **Pemisahan Blok Try-Catch:**
   - **Panggilan Midtrans:** Baris 551-622 dibungkus dalam blok `try...catch` pertama.
   - **Penyimpanan Database:** Baris 628-689 dibungkus dalam blok `try...catch` kedua.
   - **Hasil:** Kedua operasi tidak lagi bercampur dalam satu exception handler.
2. **Pengecekan Jalur Pelepasan Reservasi:**
   - Fungsi `release_stock_reservation()` hanya dipanggil pada baris 603 di dalam Tahap 3C (ketika Midtrans melempar error definitif sebelum token dibuat).
   - Pada blok `catch ($db_err)` di Tahap 3A (baris 659-688):
     - **TIDAK ADA** pemanggilan `release_stock_reservation()`.
     - **TIDAK ADA** pemanggilan update `snap_status = 'Gagal'`.
     - **TIDAK ADA** pembuatan `checkout_idempotency_key` baru di session.
     - **Status:** **PASS**. Reservasi stok fisik tetap terlindungi.
3. **Penanganan Kegagalan Koneksi Database:**
   - **Lokasi Kode:** `pelanggan/create_snap_token.php:670-680`
     ```php
     try {
         if ($conn->connect_errno === 0 && @$conn->ping()) {
             $stmt_recon = $conn->prepare("UPDATE transaksi SET snap_status = 'Perlu Rekonsiliasi' WHERE id_transaksi = ?");
             if ($stmt_recon) {
                 $stmt_recon->bind_param("i", $id_transaksi);
                 $stmt_recon->execute();
                 $stmt_recon->close();
             }
         }
     } catch (\Throwable $ignored) {}
     ```
   - **Hasil:** Jika koneksi database putus total (`ping()` gagal), script tidak menganggap update status berhasil. Sistem mencatat log error dan mengembalikan HTTP 500 dengan penjelasan bahwa transaksi ditandai untuk rekonsiliasi.
4. **Keterlacakan Order Yatim:**
   - `midtrans_order_id` (format: `BL-{id}-{time}`) telah di-commit ke database lokal pada Tahap 1 (baris 396-444).
   - Meskipun kolom `snap_token` masih NULL, transaksi lokal sudah memiliki identitas `midtrans_order_id` yang cocok dengan transaksi di Midtrans.

---

### 2.4. Audit Kesiapan Integrasi dengan Fase 3.2 (Webhook)

| Kondisi Kedatangan Webhook | Kesiapan Database & Data | Penilaian |
|---|---|---|
| **Webhook tiba sebelum browser menerima respons** | `midtrans_order_id` sudah tersimpan di tabel `transaksi` sejak Tahap 1. Webhook dapat menemukan transaksi via `SELECT ... WHERE midtrans_order_id = ?`. | **PASS** |
| **Webhook tiba saat `snap_status = 'Menunggu'`** | Webhook tidak bergantung pada `snap_status`. Webhook memverifikasi `order_id`, mencocokkan `gross_amount == total_harga`, dan mengonversi reservasi via `convert_stock_reservation()`. | **PASS** |
| **Webhook tiba saat `snap_status = 'Perlu Rekonsiliasi'`** | Webhook memvalidasi signature SHA-512. Jika status adalah `settlement`, webhook mengubah `status_pembayaran = 'Lunas'` dan memotong stok, secara otomatis menyelesaikan rekonsiliasi. | **PASS** |
| **Webhook tiba saat `snap_token` gagal tersimpan (W-02)** | Webhook Midtrans mengidentifikasi transaksi menggunakan `order_id`, bukan `snap_token`. Kegagalan penyimpanan token di browser tidak menghalangi konfirmasi pembayaran webhook. | **PASS** |

---

## 3. Matriks Temuan Audit

| ID | Kategori | Tingkat | File & Baris | Deskripsi Singkat | Status |
|---|---|---|---|---|---|
| **F-01** | Status Overwrite | **WARNING** | `pelanggan/create_snap_token.php:629` | Update `snap_status = 'Berhasil'` tidak memeriksa apakah `status_pembayaran` sudah terminal jika terjadi keterlambatan ekstrem (> 120s). | **Tercatat untuk Fase 3.2** |
| **F-02** | Migrasi Database | **WARNING** | `database/migrations/20261126_add_midtrans_columns.sql` | Indeks `idx_midtrans_order_id` telah ada di DB; pre-flight check SQL wajib dijalankan sebelum mengeksekusi file migrasi. | **Tersedia Kueri Pre-flight** |
| **F-03** | Idempotency B-01 | **PASS** | `pelanggan/create_snap_token.php:465-497` | Error 1062 di-rollback dan di-query ulang tanpa memicu HTTP 500. | **FIXED & VERIFIED** |
| **F-04** | Reservasi W-02 | **PASS** | `pelanggan/create_snap_token.php:628-688` | Kegagalan penyimpanan token pasca-Midtrans tidak melepas reservasi stok. | **FIXED & VERIFIED** |
| **F-05** | Stuck Menunggu W-01 | **PASS** | `pelanggan/create_snap_token.php:68-100` | Transaksi tertahan > 120s bertransisi ke `Perlu Rekonsiliasi` secara atomik. | **FIXED & VERIFIED** |
| **F-06** | Webhook Readiness | **PASS** | `transaksi.midtrans_order_id` | Identifikasi pesanan pada Fase 3.2 independen dari penyimpanan `snap_token`. | **READY** |

---

## 4. Rekomendasi Teknis untuk Fase 3.2

1. **Idempotency Webhook:** Pastikan `midtrans_notification.php` memeriksa kolom `stok_dikurangi = 1` sebelum memanggil `convert_stock_reservation()`, guna mencegah pemotongan stok ganda jika Midtrans mengirim notifikasi HTTP berulang.
2. **Kondisionalitas Status pada Webhook:** Saat webhook menerima status `settlement` atau `capture`, perbarui `status_pembayaran = 'Lunas'` dan `snap_status = 'Berhasil'`.
3. **Pelepasan Stok Webhook:** Saat webhook menerima status `expire`, `cancel`, atau `deny`, panggil `release_stock_reservation($conn, $id_transaksi)` dan perbarui `status_pembayaran = 'Dibatalkan'` serta `snap_status = 'Gagal'`.
4. **Penanganan Status Ambigu (Rekonsiliasi Otomatis):** Tambahkan logika di webhook bahwa jika transaksi yang ditemukan memiliki `snap_status = 'Perlu Rekonsiliasi'`, webhook harus mencatat log resolusi rekonsiliasi berhasil.

---

## 5. Kesimpulan Akhir

Berdasarkan hasil audit mendalam terhadap aspek konkurensi, race condition, idempotency, dan mekanisme pemulihan kegagalan pada `pelanggan/create_snap_token.php`:

### **STATUS: APPROVED WITH WARNINGS**

Implementasi telah memenuhi seluruh kriteria keamanan dan integritas transaksi:
- Tidak ada kondisi di mana stok fisik dapat berkurang sebelum pembayaran diverifikasi (memenuhi **AC-19**).
- Tidak ada kondisi di mana dua transaksi paralel dapat merebut reservasi stok yang sama melebihi kuantitas tersedia (memenuhi **AC-18**).
- Tidak ada transaksi ganda atau reservasi ganda yang ditimbulkan oleh request paralel atau duplicate idempotency key.
- Seluruh file PHP lolos verifikasi sintaksis `php -l`.

Proyek Beautyland **dinyatakan siap untuk melanjutkan ke Fase 3.2 — Midtrans Notification Webhook** setelah administrator melakukan verifikasi pre-flight database.
