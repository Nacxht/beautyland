# Laporan Implementasi Perbaikan Prioritas 1: Beautyland Midtrans Webhook & Stock Reservation Hardening

**Proyek:** Beautyland (PHP Native 8.1, MariaDB 12.2, Midtrans Snap SDK 2.6.2)  
**Branch:** `PPL-12`  
**Dokumen:** Laporan Implementasi Perbaikan Prioritas 1 (CRIT-01, CRIT-02, CRIT-03, HIGH-01, HIGH-03)  
**Tanggal:** 26 November 2026  
**Referensi:** `docs/ai-generated/independent-review-fase3-2.md`

---

## 1. Ringkasan Temuan Audit yang Diperbaiki

Audit independen terhadap Fase 3.2 menghasilkan keputusan **REJECTED** karena ditemukannya celah keamanan dan inkonsistensi logika inventaris. Seluruh temuan Prioritas 1 telah berhasil diperbaiki pada source code aktual:

1. **CRIT-01 (Authentication Guard Modul Pengiriman):**
   File `admin/pengiriman.php` sebelumnya tidak memiliki guard sesi admin, memungkinkan publik atau pelanggan mengakses data alamat pelanggan dan memodifikasi status pengiriman.
2. **CRIT-02 (Pengiriman Pesanan Belum Lunas):**
   Modul `admin/pengiriman.php` sebelumnya hanya memblokir status `Dibatalkan` dan `Refund`, sehingga pesanan `Menunggu Pembayaran` dan `Menunggu Verifikasi` dapat diproses kirim sebelum dibayar.
3. **CRIT-03 (Phantom Stock Conversion):**
   Fungsi `convert_stock_reservation()` sebelumnya mengembalikan `success = true, converted = 0` saat tidak ada reservasi aktif (termasuk jika reservasi telah dilepas atau kosong). Webhook menganggap ini berhasil dan mengubah status menjadi `Lunas` serta `stok_dikurangi = 1` tanpa memotong stok fisik gudang.
4. **HIGH-01 (Return `release_stock_reservation()` Diabaikan):**
   Pada `midtrans_notification.php`, nilai kembalian `release_stock_reservation()` diabaikan. Jika kueri pelepasan gagal, transaksi tetap di-commit sebagai `Dibatalkan` dan mengembalikan `HTTP 200 OK`, menyebabkan reservasi terkunci permanen (*dead inventory lock*).
5. **HIGH-03 (Pembatalan Lokal Tidak Melepas Reservasi):**
   Handler pembatalan pada `pelanggan/proses_pembayaran.php` sebelumnya tidak memverifikasi kepemilikan pesanan, tidak memeriksa status terminal, tidak menggunakan transaksi atomik/row lock, dan tidak memanggil `release_stock_reservation()`.

---

## 2. Daftar File yang Diubah

| No | File Path | Tipe Perubahan | Deskripsi Perubahan |
|---|---|---|---|
| 1 | `admin/pengiriman.php` | Modifikasi | Penambahan guard autentikasi admin (`CRIT-01`) dan pengetatan validasi pemrosesan pengiriman hanya untuk status `Lunas` (`CRIT-02`). |
| 2 | `includes/stock_reservation.php` | Modifikasi | Perombakan fungsi `convert_stock_reservation()` dan `release_stock_reservation()` untuk membedakan secara tegas kondisi aktif, sudah dikonversi (idempotent no-op), dan kondisi anomali/dilepas (`CRIT-03`, `HIGH-01`). |
| 3 | `midtrans_notification.php` | Modifikasi | Validasi ketat hasil konversi stok, penangkapan hasil pelepasan stok dengan pelemparan exception (`HTTP 500`), koreksi semantik `midtrans_status = 'capture'`, dan pelepasan stok untuk unpaid order refund (`CRIT-03`, `HIGH-01`). |
| 4 | `pelanggan/proses_pembayaran.php` | Modifikasi | Penegakan autentikasi sesi, verifikasi kepemilikan transaksi, row locking `SELECT ... FOR UPDATE`, isolasi status terminal, dan pelepasan stok atomik saat `action=cancel` (`HIGH-03`). |
| 5 | `pelanggan/create_snap_token.php` | Modifikasi | Pengecekan hasil return `release_stock_reservation()` pada penanganan error Midtrans Tahap 3C agar exception dilempar dan transaksi di-rollback secara konsisten. |

---

## 3. Penjelasan Perubahan per File

### 3.1. `admin/pengiriman.php`
- Menambahkan pemeriksaan otorisasi admin pada awal script:
  ```php
  session_start();

  if (!isset($_SESSION['user']) || $_SESSION['role'] !== 'admin') {
      header("Location: ../login.php");
      exit;
  }
  ```
- Pada handler POST `$_POST['simpan']`, kueri validasi status transaksi diperketat:
  Jika `status_pembayaran !== 'lunas'`, request langsung ditolak dengan pesan kesalahan dan di-redirect kembali ke `pengiriman.php`.
- Pada antarmuka tabel dan form pengiriman:
  Nilai `$is_blocked` didefinisikan sebagai `!($pay_status === 'lunas')`.
  Form input jasa kirim, status pengiriman, dan tombol simpan dinonaktifkan (`disabled`) secara otomatis untuk seluruh pesanan yang belum lunas.

### 3.2. `includes/stock_reservation.php`
- **`convert_stock_reservation()`:**
  - Melakukan `SELECT id_reservasi, id_produk, jumlah, status_reservasi FROM reservasi_stok WHERE id_transaksi = ? ORDER BY id_produk ASC FOR UPDATE`.
  - Jika tidak ada baris reservasi sama sekali: mengembalikan `success = false, converted = 0, already_converted = false`.
  - Jika seluruh baris berstatus `Dikonversi`: mengembalikan `success = true, converted = 0, already_converted = true` (idempotent no-op).
  - Jika ada baris berstatus `Dilepas` atau status campuran/inkonsisten: mengembalikan `success = false, converted = 0, already_converted = false`.
  - Jika seluruh baris berstatus `Aktif`: mengeksekusi pembaruan stok fisik (`produk.stok`) dan reservasi (`stok_direservasi`) dengan penanganan statement error yang ketat, lalu mengembalikan `success = true, converted = count, already_converted = false`.
- **`release_stock_reservation()`:**
  - Mengambil seluruh baris reservasi transaksi dengan `FOR UPDATE`.
  - Membedakan kondisi idempotent no-op (`already_released = true`) dari kondisi pelepasan baru.
  - Memverifikasi eksekusi statement MySQL; jika terjadi kegagalan statement, mengembalikan `success = false`.

### 3.3. `midtrans_notification.php`
- Pada cabang `settlement` dan `capture` (fraud: accept):
  - Memeriksa validitas konversi:
    `$is_valid_conversion = ($conv['success'] === true && ($conv['converted'] > 0 || !empty($conv['already_converted'])));`
  - Jika tidak valid, mencatat error kritis ke server log dan melempar `RuntimeException`. Blok `catch (\Throwable $e)` akan melakukan `$conn->rollback()` dan merespons `HTTP 500 Internal Server Error`, memastikan Midtrans melakukan retry otomatis dan tidak ada pembaruan fiktif ke `Lunas`.
- Pada cabang `expire`, `cancel`, `deny`, dan `capture` (fraud: deny):
  - Menampung `$rel = release_stock_reservation($conn, $trx_id);`.
  - Jika `!$rel['success']`, mencatat error log dan melempar `RuntimeException` agar transaksi di-rollback dan merespons `HTTP 500`.
- Memperbaiki polusi semantik `midtrans_status`:
  Menyimpan `'capture'` (bukan `'challenge'`) saat kartu kredit berstatus challenge.
- Menangani refund pada pesanan yang masih `Menunggu Pembayaran`:
  Memanggil `release_stock_reservation()` dan mengubah status transaksi menjadi `Dibatalkan`.

### 3.4. `pelanggan/proses_pembayaran.php`
- Menempatkan pemeriksaan sesi `id_pelanggan` sebelum handler `action=cancel`.
- Membungkus pembatalan dalam transaksi atomik (`$conn->begin_transaction()`).
- Mengunci baris transaksi pelanggan dengan `SELECT ... FOR UPDATE`.
- Memvalidasi kepemilikan transaksi: menolak jika `id_pelanggan` transaksi tidak cocok dengan session login.
- Memvalidasi status terminal: membatalkan operasi jika transaksi telah berstatus `Lunas`, `Refund`, atau `Dibatalkan`.
- Memanggil `release_stock_reservation($conn, $id_cancel)`. Jika gagal, melempar exception untuk memicu rollback.
- Memperbarui status menjadi `Dibatalkan` dengan klausa `WHERE id_transaksi = ? AND status_pembayaran NOT IN ('Lunas', 'Refund', 'Dibatalkan')`.
- Melakukan `$conn->commit()`.

### 3.5. `pelanggan/create_snap_token.php`
- Pada penanganan error Midtrans Tahap 3C (baris 624), memeriksa hasil return `$rel['success']` dari `release_stock_reservation()`. Jika gagal, exception dilempar ke blok catch untuk memicu rollback transaksi database.

---

## 4. Penjelasan Solusi per Temuan Audit

### CRIT-01 — Authentication Guard pada Modul Pengiriman
- **Penyebab:** Tidak adanya baris pemeriksaan `$_SESSION['user']` dan `$_SESSION['role'] === 'admin'` pada file `admin/pengiriman.php`.
- **Solusi:** Menambahkan blok proteksi standar di baris 4. Seluruh request GET dan POST tanpa sesi admin dialihkan (`header("Location: ../login.php")`) sebelum kueri data atau modifikasi data pengiriman dapat dieksekusi.

### CRIT-02 — Pengiriman Pesanan Belum Lunas
- **Penyebab:** Kueri pengecekan backend hanya memeriksa `$pay_status === 'dibatalkan' || $pay_status === 'refund'`, sehingga status `Menunggu Pembayaran` dan `Menunggu Verifikasi` lolos.
- **Solusi:** Mengubah logika validasi backend menjadi whitelist mutlak: `if ($pay_status !== 'lunas')`. Pada frontend, tombol dan select option dinonaktifkan (`disabled`) secara kondisional apabila status pembayaran belum lunas.

### CRIT-03 — Phantom Stock Conversion
- **Penyebab:** Ketiadaan verifikasi terhadap status reservasi historis pada `convert_stock_reservation()`, serta penerimaan `converted = 0` sebagai status sukses di webhook.
- **Solusi:**
  1. Helper memverifikasi seluruh baris di `reservasi_stok`. Jika reservasi sudah `Dilepas` atau tidak ditemukan, fungsi mengembalikan `success = false`.
  2. Webhook memeriksa `$is_valid_conversion = ($conv['success'] === true && ($conv['converted'] > 0 || !empty($conv['already_converted'])))`.
  3. Jika reservasi tidak valid, webhook melempar exception, melakukan rollback transaksi, dan mengembalikan `HTTP 500`. Transaksi tidak pernah diubah menjadi `Lunas` atau `stok_dikurangi = 1` tanpa pemotongan stok fisik yang nyata.

### HIGH-01 — Return `release_stock_reservation()` Diabaikan
- **Penyebab:** Pemanggilan fungsi tanpa penampungan return value dan tanpa pemeriksaan `$rel['success']`.
- **Solusi:** Seluruh titik pemanggilan di `midtrans_notification.php` kini menampung return value dan memvalidasi `$rel['success']`. Jika terjadi kegagalan query, exception dilempar, memicu rollback transaksi atomik dan menghasilkan respons `HTTP 500`, sehingga Midtrans melakukan retry otomatis.

### HIGH-03 — Pembatalan Lokal Tidak Melepas Reservasi
- **Penyebab:** Handler pembatalan hanya menjalankan query update string tanpa row lock dan tanpa memanggil helper pelepasan reservasi.
- **Solusi:** Alur pembatalan lokal dirombak dengan transaksi atomik MySQL, verifikasi kepemilikan pengguna, row locking `SELECT ... FOR UPDATE`, proteksi status terminal, pemanggilan `release_stock_reservation()`, dan commit hanya jika seluruh tahapan sukses.

---

## 5. Penjelasan Locking dan Idempotensi Setelah Perbaikan

### 5.1. Urutan Penguncian (Lock Ordering)
Untuk mencegah deadlock antar-proses konkuren, urutan penguncian tabel di seluruh aplikasi distandarisasi secara ketat:
1. **Tabel Induk (`transaksi`):** Dikunci pertama kali menggunakan `SELECT id_transaksi ... WHERE id_transaksi = ? FOR UPDATE`.
2. **Tabel Anak (`reservasi_stok`):** Dikunci kedua menggunakan `SELECT ... WHERE id_transaksi = ? ORDER BY id_produk ASC FOR UPDATE`.
3. **Tabel Agregat (`produk`):** Diperbarui secara berurutan berdasarkan `id_produk ASC`.

Urutan yang identik diterapkan pada:
- Endpoint Webhook (`midtrans_notification.php`)
- Endpoint Checkout (`pelanggan/create_snap_token.php`)
- Endpoint Pembatalan Lokal (`pelanggan/proses_pembayaran.php`)

Karena seluruh thread mengakses tabel dan baris dalam urutan hierarki yang sama, kondisi circular wait deadlock dapat dicegah secara deterministik.

### 5.2. Jaminan Idempotensi
- **Duplikat Settlement:**
  Jika notifikasi settlement diterima ulang saat transaksi sudah `Lunas`, kueri branching webhook langsung mengabaikan pemotongan stok dan hanya memperbarui timestamp/payload dengan respons `HTTP 200 OK`.
- **Duplikat Release:**
  Jika reservasi telah berstatus `Dilepas`, helper `release_stock_reservation()` mendeteksi kondisi tersebut dan mengembalikan `already_released = true` secara aman tanpa mengurangi agregat stok berulang kali.
- **Duplikat Convert:**
  Jika reservasi telah berstatus `Dikonversi`, helper `convert_stock_reservation()` mengembalikan `already_converted = true` dan no-op aman pada stok fisik.

---

## 6. Dampak terhadap Pembayaran Manual

1. **Upload Bukti Transfer:**
   Alur upload bukti transfer pada `pelanggan/proses_pembayaran.php:22-120` tidak mengalami perubahan logika. Pengurangan stok manual secara langsung (`UPDATE produk SET stok = stok - ?`) tetap berjalan sebagaimana mestinya.
2. **Verifikasi Admin:**
   Tombol verifikasi admin pada `admin/pesanan.php` tetap terbatas untuk transaksi non-Midtrans dan tidak memotong stok dua kali.
3. **Penyelarasan Pengiriman Manual:**
   Pesanan transfer bank manual yang berstatus `Menunggu Verifikasi` kini terlindungi dari pengiriman dini; admin wajib memverifikasi pembayaran menjadi `Lunas` terlebih dahulu sebelum form pengiriman terbuka.
4. **Pembatalan Manual:**
   Pembatalan lokal untuk transaksi manual tetap aman karena `release_stock_reservation()` mengembalikan no-op sukses jika transaksi tidak memiliki baris reservasi di `reservasi_stok`.

---

## 7. Daftar Test Case dan Hasil Pengujian

Pengujian dilakukan menggunakan script test harness lokal (`/tmp/opencode/test_priority1_logic.php`) dan verifikasi sintaks PHP CLI:

| No | Kategori Pengujian | Deskripsi Skenario Test Case | Hasil Aktual | Catatan Verifikasi |
|---|---|---|---|---|
| 1 | Auth Pengiriman | Akses `admin/pengiriman.php` tanpa sesi login | **PASS** | Ditolak; dialihkan ke `login.php`. |
| 2 | Auth Pengiriman | Akses `admin/pengiriman.php` dengan sesi role `pelanggan` | **PASS** | Ditolak; dialihkan ke `login.php`. |
| 3 | Auth Pengiriman | Akses `admin/pengiriman.php` dengan sesi role `admin` | **PASS** | Diizinkan; antarmuka admin terbuka. |
| 4 | Fulfillment Pengiriman | Validasi POST simpan untuk transaksi berstatus `Lunas` | **PASS** | Diizinkan dan data pengiriman tersimpan. |
| 5 | Fulfillment Pengiriman | Validasi POST simpan untuk transaksi `Menunggu Pembayaran` | **PASS** | Ditolak dengan alert penolakan. |
| 6 | Fulfillment Pengiriman | Validasi POST simpan untuk transaksi `Menunggu Verifikasi` | **PASS** | Ditolak dengan alert penolakan. |
| 7 | Fulfillment Pengiriman | Validasi POST simpan untuk transaksi `Dibatalkan` | **PASS** | Ditolak; pengiriman diblokir. |
| 8 | Fulfillment Pengiriman | Validasi POST simpan untuk transaksi `Refund` | **PASS** | Ditolak; pengiriman diblokir. |
| 9 | Konversi Reservasi | Konversi transaksi tanpa baris reservasi di database | **PASS** | Mengembalikan `success = false, converted = 0`. |
| 10 | Konversi Reservasi | Konversi berulang saat semua reservasi sudah `Dikonversi` | **PASS** | Idempotent no-op (`already_converted = true, converted = 0`). |
| 11 | Konversi Reservasi | Konversi saat reservasi berstatus `Dilepas` atau campuran | **PASS** | Mengembalikan `success = false` (anti phantom conversion). |
| 12 | Konversi Reservasi | Konversi saat seluruh reservasi berstatus `Aktif` | **PASS** | Berhasil konversi seluruh item dan stok berkurang. |
| 13 | Webhook Guard | Deteksi validitas konversi saat `stok_dikurangi = 0` | **PASS** | Menolak phantom conversion dan melempar exception `HTTP 500`. |
| 14 | Pelepasan Reservasi | Pelepasan berulang saat reservasi sudah `Dilepas` | **PASS** | Idempotent no-op (`already_released = true, released = 0`). |
| 15 | Pelepasan Reservasi | Penanganan kegagalan kueri pelepasan stok di webhook | **PASS** | Melempar exception, memicu rollback, respons `HTTP 500`. |
| 16 | Pembatalan Lokal | Pelanggan mencoba membatalkan pesanan milik orang lain | **PASS** | Ditolak; rollback dan redirect aman. |
| 17 | Pembatalan Lokal | Pembatalan transaksi yang sudah berstatus `Lunas` | **PASS** | Ditolak; status terminal terlindungi. |
| 18 | Pembatalan Lokal | Pembatalan transaksi yang sudah berstatus `Dibatalkan` | **PASS** | Ditolak; status terminal terlindungi. |
| 19 | Pembatalan Lokal | Pembatalan transaksi aktif dengan pelepasan reservasi atomik | **PASS** | Status menjadi `Dibatalkan` dan reservasi dilepas. |
| 20 | Sintaks PHP CLI | Pemeriksaan sintaks pada seluruh file yang dimodifikasi | **PASS** | `php -l` lulus tanpa error sintaks pada seluruh file. |
| 21 | Webhook Concurrency | Uji runtime konkurensi multi-curl simultan ke database aktif | **NOT RUN** | Memerlukan simulasi request jaringan eksternal/mock HTTP. |
| 22 | Penetrasi Signature | Uji penetrasi payload tampering via endpoint publik | **NOT RUN** | Dibatasi oleh ketentuan audit read-only database. |

---

## 8. Keterbatasan Pengujian

1. **Batas Modifikasi Database (Read-Only Policy):**
   Sesuai batasan pengerjaan, tidak ada mutasi data permanen (`INSERT/UPDATE/DELETE`) yang dieksekusi langsung pada tabel operasional database aktif `mariadb-server`.
2. **Pengujian Browser Manual:**
   Pengujian antarmuka browser secara menyeluruh ditangguhkan sesuai ketentuan hingga seluruh fase backend tuntas dan frontend Snap JS (Fase 4) diintegrasikan.
3. **Simulasi Concurrency Runtime:**
   Uji konkurensi pada tahap ini diverifikasi melalui unit logic test harness dan audit locking statis MariaDB InnoDB, bukan melalui load test multi-thread pada server production.

---

## 9. Temuan Baru yang Ditemukan Selama Pengerjaan

1. **Penanganan Refund pada Pesanan Unpaid (`midtrans_notification.php:228`):**
   Ditemukan bahwa jika pesanan yang masih `Menunggu Pembayaran` menerima notifikasi refund dari Midtrans (misalnya karena refund manual di portal MAP sebelum settlement lokal tiba), reservasi stok sebelumnya tidak dilepas. Masalah ini telah diselesaikan bersamaan dengan perbaikan HIGH-01 dengan menambahkan pemanggilan `release_stock_reservation()` dan pembaruan status transaksi menjadi `Dibatalkan`.
2. **Koreksi Semantik `midtrans_status` (`midtrans_notification.php:165`):**
   Status `capture` kartu kredit dengan `fraud_status = challenge` kini mencatat `midtrans_status = 'capture'`, menghilangkan nilai alien `'challenge'` dari kolom status transaksi gateway.

---

## 10. Risiko yang Masih Tersisa

1. **Ketiadaan Cron Pembersih Reservasi Kedaluwarsa:**
   Jika pelanggan checkout Midtrans dan membiarkan pembayaran kedaluwarsa tanpa adanya webhook `expire` dari Midtrans (misalnya webhook gagal terkirim dan retry habis), reservasi stok akan tetap berstatus `Aktif` sampai ada proses rekonsiliasi manual. Disarankan untuk menambahkan background scheduled worker pembersih pada fase pemeliharaan berikutnya.
2. **Pemberian Resi Otomatis pada Modul Pengiriman:**
   Input nomor resi pada `admin/pengiriman.php` masih menggunakan nilai default ter-generate (`MB-131...`). Untuk produksi riil, staf gudang perlu menginput resi asli dari kurir ekspedisi.

---

## 11. Kesimpulan Kesiapan Audit Ulang

Seluruh temuan tingkat **CRITICAL (CRIT-01, CRIT-02, CRIT-03)** dan **HIGH (HIGH-01, HIGH-03)** telah diperbaiki secara tuntas pada source code aktual dengan mempertahankan kepatuhan arsitektur e-commerce Beautyland.

**Status Akhir:** **READY FOR RE-AUDIT** (Siap Diaudit Ulang oleh Tim Review Independen).
