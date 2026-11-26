# Laporan Final Review: Fase 3.1.1 Checkout Hardening & Stock Reservation

**Repository:** Beautyland (PHP Native, MySQLi, Midtrans Snap)  
**Tanggal Audit:** 26 November 2026  
**Dokumen Rujukan:**
- `docs/ai-generated/audit-checkout-midtrans.md`
- `docs/ai-generated/review-fase3-1.md`
- `docs/ai-generated/fase3-1-1-hardening.md`  
**File yang Diaudit:**
- `pelanggan/create_snap_token.php`
- `pelanggan/proses_checkout.php`
- `includes/stock_reservation.php`
- `admin/pesanan.php`
- `database/migrations/20261126_add_midtrans_columns.sql`
- `database/migrations/20261126_add_stock_reservations_and_checkout_idempotency.sql`
- `includes/midtrans.php`

---

## 1. Ringkasan Hasil Audit

Secara keseluruhan, arsitektur Fase 3.1.1 telah berhasil mengatasi masalah fundamental dari Fase 3.1:
1. **Pola Persist-Before-Call** berhasil diterapkan: transaksi lokal di-commit sebelum panggilan HTTP Midtrans dilakukan.
2. **Helper Reservasi Stok** (`includes/stock_reservation.php`) menerapkan row locking terurut (`id_produk ASC`), mencegah overselling (**AC-18**), dan menyiapkan fondasi pemotongan stok pada Webhook (**AC-19**).
3. **Kompatibilitas Pembayaran Manual** terjaga dengan baik; `admin/pesanan.php` diproteksi agar tidak memverifikasi manual transaksi Midtrans.

Namun, audit akhir menemukan **1 temuan berstatus BLOCKER** pada penanganan error duplicate key saat request paralel, serta **2 temuan berstatus WARNING** terkait pemulihan status `Menunggu` dan penanganan galat setelah Snap Token terbit.

### Matriks Status Kesiapan
| Komponen | Status | Keterangan |
|---|---|---|
| **Kategori 1: Idempotency & Paralel** | **BLOCKER** | Request kedua yang terkena duplicate key MySQL (1062) menghasilkan HTTP 500, belum otomatis mengambil transaksi pertama. |
| **Kategori 2: Snap Token & Expiry** | **PASS** | Token kedaluwarsa ditolak, token aktif di-reuse, parameter `expiry` sesuai spesifikasi Midtrans Snap API. |
| **Kategori 3: Crash & Recovery** | **WARNING** | Status `snap_status = 'Menunggu'` belum memiliki mekanisme timeout otomatis jika proses PHP mati mendadak; kegagalan UPDATE setelah token terbit melepaskan reservasi secara prematur. |
| **Kategori 4: Helper Reservasi Stok** | **PASS** | Operasi atomik, row lock terurut, idempotent, agregat konsisten, aman dari nilai negatif. |
| **Kategori 5: Migrasi Database** | **PASS** | Skema konsisten, aman untuk data historis. Pre-flight SQL disiapkan karena indeks Fase 2 sudah terpasang sebagian. |
| **Kategori 6: Pembayaran Manual** | **PASS** | Alur transfer bank dan upload bukti 100% kompatibel dan terisolasi. |

---

## 2. Temuan Berdasarkan Enam Kategori Pemeriksaan

### 2.1. Audit Idempotency dan Request Paralel

1. **Unique Constraint Sebagai Perlindungan:**
   - **Status:** **PASS**
   - **Bukti:** `database/migrations/20261126_add_stock_reservations_and_checkout_idempotency.sql:35`
     ```sql
     ALTER TABLE `transaksi` ADD UNIQUE INDEX `idx_checkout_idempotency_key` (`checkout_idempotency_key`);
     ```
     Index unik ini menjamin database menolak insert baris kedua dengan kunci yang sama.
2. **Penanganan Duplicate Key pada Request Paralel:**
   - **Status:** **BLOCKER**
   - **Bukti:** `pelanggan/create_snap_token.php:333, 401-412`
   - **Analisis:** Jika dua request paralel membaca session yang sama sebelum salah satu melakukan insert, Request 1 berhasil melakukan INSERT. Request 2 menabrak constraint unik `idx_checkout_idempotency_key` (MySQL Error 1062).
   - **Kelemahan:** Error 1062 ditangkap oleh blok generic `catch (\Throwable $e)` (baris 401) dan langsung mengembalikan **HTTP 500**:
     ```json
     { "success": false, "message": "Terjadi kesalahan sistem saat memproses pesanan Anda. Silakan coba kembali." }
     ```
     Request 2 **belum melakukan re-query** ke database untuk mengambil transaksi yang baru saja dibuat oleh Request 1.
3. **Pencegahan Reservasi dan Transaksi Ganda:**
   - **Status:** **PASS**
   - **Analisis:** Karena Request 2 gagal pada baris 333 (sebelum memanggil `reserve_stock` di baris 345), Request 2 di-rollback total. Tidak ada transaksi ganda, tidak ada order ID ganda, dan tidak ada reservasi ganda.
4. **Kemandirian dari PHP Session Locking:**
   - **Status:** **PASS**
   - **Analisis:** Sistem mengandalkan database-level unique constraint (`idx_checkout_idempotency_key`), bukan hanya session lock PHP.
5. **Siklus Hidup Idempotency Key:**
   - **Status:** **PASS**
   - **Bukti:** `pelanggan/proses_checkout.php:85` dan `pelanggan/create_snap_token.php:539`
   - Key baru hanya dibuat saat proses checkout baru dimulai dari awal atau saat percobaan sebelumnya dipastikan gagal definitif dan reservasinya telah dilepas dengan aman.

---

### 2.2. Audit Snap Token dan Kedaluwarsa

1. **`snap_status = 'Berhasil'` dan Token Belum Kedaluwarsa:**
   - **Status:** **PASS**
   - **Bukti:** `pelanggan/create_snap_token.php:183-194`
   - Mengembalikan `snap_token` dan `order_id` yang sama dengan flag `"idempotent": true` (HTTP 200).
2. **`snap_status = 'Berhasil'` tetapi Token Sudah Kedaluwarsa:**
   - **Status:** **PASS**
   - **Bukti:** `pelanggan/create_snap_token.php:196-202`
   - Memeriksa `strtotime($expires_at) > time()`. Jika sudah kedaluwarsa, ditolak dengan HTTP 409 dan token lama tidak dikembalikan.
3. **Status `Menunggu` dan `Perlu Rekonsiliasi`:**
   - **Status:** **PASS**
   - **Bukti:** `pelanggan/create_snap_token.php:204-220`
   - Status `Menunggu` mengembalikan HTTP 429 ("Sesi pembayaran sedang diproses"). Status `Perlu Rekonsiliasi` mengembalikan HTTP 409. Keduanya tidak membuat order ID baru.
4. **Status Pembayaran Terminal (`Lunas` / `Dibatalkan`):**
   - **Status:** **PASS**
   - **Bukti:** `pelanggan/create_snap_token.php:164-180`
   - Transaksi yang sudah `Lunas` atau `Dibatalkan` langsung ditolak dengan HTTP 409.
5. **Verifikasi Format Parameter Expiry:**
   - **Status:** **PASS**
   - **Bukti:** `pelanggan/create_snap_token.php:445-449`
     ```php
     'expiry' => [
         'start_time' => date('Y-m-d H:i:s O'),
         'unit'       => 'minute',
         'duration'   => $expiry_minutes,
     ]
     ```
     Format `date('Y-m-d H:i:s O')` menghasilkan format ISO string seperti `2026-11-26 18:30:00 +0700` dengan unit `minute` dan durasi integer. Format ini 100% sesuai dengan spesifikasi parameter `expiry` pada Midtrans Snap API.

---

### 2.3. Audit Crash dan Recovery

1. **Kondisi 1: PHP berhenti setelah transaksi lokal di-commit tetapi sebelum request Midtrans:**
   - **Status:** **WARNING**
   - **Bukti:** `pelanggan/create_snap_token.php:387-452`
   - **Analisis:** Transaksi lokal tersimpan dengan status `snap_status = 'Menunggu'`. Jika proses PHP mati mendadak (misal OOM killer, Apache restart, server reboot), transaksi akan selamanya berstatus `'Menunggu'`.
   - **Dampak:** Saat pelanggan mencoba checkout ulang dengan session yang sama, sistem selalu merespons HTTP 429 tanpa pernah memberi kesempatan pulih, karena belum ada batas waktu kedaluwarsa untuk status `'Menunggu'` (misal: jika status `'Menunggu'` sudah lebih dari 2 menit, anggap proses sebelumnya mati dan lakukan recovery).
2. **Kondisi 2: Midtrans menerima request tetapi koneksi timeout:**
   - **Status:** **PASS**
   - **Bukti:** `pelanggan/create_snap_token.php:496-516`
   - **Analisis:** Script mendeteksi cURL error 28 / timeout, mengupdate status menjadi `'Perlu Rekonsiliasi'`, tidak melepas reservasi stok, serta tidak menghapus keranjang/session.
3. **Kondisi 3: Midtrans berhasil menerbitkan token tetapi UPDATE `snap_token` gagal:**
   - **Status:** **WARNING**
   - **Bukti:** `pelanggan/create_snap_token.php:464, 520-536`
   - **Analisis:** Jika baris 464 gagal (misal koneksi database terputus sesaat setelah token diterima dari Midtrans), exception dilempar ke blok `catch`.
   - **Kelemahan:** Karena bukan timeout, script masuk ke Tahap 3C yang mengupdate status menjadi `'Gagal'` dan **melepas reservasi stok** via `release_stock_reservation()`. Padahal di server Midtrans, order tersebut sudah aktif! Jika pelanggan berhasil membayar transaksi tersebut di jendela pembayaran Midtrans, stok fisik di toko sudah tidak direservasi.
4. **Kondisi 4: Webhook datang sebelum browser menerima respons:**
   - **Status:** **PASS**
   - **Bukti:** `pelanggan/create_snap_token.php:338-343, 387`
   - **Analisis:** Karena `midtrans_order_id` disimpan dan di-commit di Tahap 1 (sebelum memanggil Midtrans), order ID sudah ada di tabel `transaksi`. Webhook Fase 3.2 dapat menemukan record transaksi berdasarkan `midtrans_order_id` meskipun browser pelanggan belum menerima respons JSON.
5. **Kondisi 5: Request diulang setelah kegagalan:**
   - **Status:** **PASS**
   - **Analisis:** Pada kegagalan definitif (Tahap 3C), session `pending_order` diberi `checkout_idempotency_key` baru (baris 539) sehingga percobaan berikutnya dapat membuat transaksi baru tanpa tertahan key lama.

---

### 2.4. Audit Helper Reservasi Stok (`includes/stock_reservation.php`)

1. **Atomisitas Operasi:**
   - **Status:** **PASS**
   - Semua fungsi dijalankan di dalam transaksi database caller.
2. **Urutan Row Lock (`id_produk ASC`):**
   - **Status:** **PASS**
   - **Bukti:**
     - `reserve_stock`: baris 35-37 melakukan sorting `usort` item berdasarkan `id_produk ASC` sebelum `FOR UPDATE`.
     - `release_stock_reservation`: baris 151 query `ORDER BY id_produk ASC FOR UPDATE`.
     - `convert_stock_reservation`: baris 230 query `ORDER BY id_produk ASC FOR UPDATE`.
3. **Proteksi Nilai Negatif:**
   - **Status:** **PASS**
   - **Bukti:** Baris 179 dan 258 menggunakan klausa MySQL `GREATEST(0, stok_direservasi - ?)` dan `GREATEST(0, stok - ?)`.
4. **Pencegahan Pemotongan Ganda (Idempotency Helper):**
   - **Status:** **PASS**
   - **Bukti:** `release_stock_reservation` dan `convert_stock_reservation` hanya mengambil baris yang berstatus `status_reservasi = 'Aktif'`. Pemanggilan kedua secara otomatis mendapatkan 0 baris dan menghasilkan *safe no-op*.
5. **Independensi Transaksi Caller:**
   - **Status:** **PASS**
   - Helper tidak memanggil `$conn->commit()` maupun `$conn->rollback()`, menyerahkan kendali penuh kepada pemanggil.
6. **Konsistensi Agregat:**
   - **Status:** **PASS**
   - Penambahan dan pengurangan `produk.stok_direservasi` selalu berpasangan dengan perubahan status baris pada tabel `reservasi_stok`.

---

### 2.5. Audit Migrasi Database

1. **Pemeriksaan Konflik Skema:**
   - **Status:** **PASS**
   - Tidak ada duplikasi nama kolom atau indeks antara `20261126_add_midtrans_columns.sql` dan `20261126_add_stock_reservations_and_checkout_idempotency.sql`.
2. **Kesesuaian Tipe Data Foreign Key:**
   - **Status:** **PASS**
   - `reservasi_stok.id_transaksi` (INT) merujuk ke `transaksi.id_transaksi` (INT).
   - `reservasi_stok.id_produk` (INT) merujuk ke `produk.id_produk` (INT).
   - Kedua tabel menggunakan Engine `InnoDB` dan Collation `utf8mb4_0900_ai_ci`.
3. **Nilai Default untuk Data Historis:**
   - **Status:** **PASS**
   - Kolom `stok_direservasi` memiliki default `0`.
   - Kolom `checkout_idempotency_key` bernilai `NULL` (tidak bentrok pada index UNIQUE).
4. **Keberadaan Parsial Indeks Fase 2:**
   - **Status:** **WARNING**
   - Indeks `idx_midtrans_order_id` telah terdeteksi pada database sebelumnya. Menjalankan skrip Fase 2 secara mentah berpotensi memicu error `Duplicate column name` atau `Duplicate key name`. Wajib dilakukan pemeriksaan pre-flight sebelum menjalankan migrasi.

---

### 2.6. Audit Kompatibilitas Pembayaran Manual

1. **Verifikasi Pesanan di `admin/pesanan.php`:**
   - **Status:** **PASS**
   - **Bukti:** `admin/pesanan.php:17-27`
     ```php
     $stmt_verif = $conn->prepare("
         UPDATE transaksi
         SET status_pembayaran='Lunas'
         WHERE id_transaksi = ? AND (metode_pembayaran != 'Midtrans' OR metode_pembayaran IS NULL)
     ");
     ```
     Admin hanya dapat memverifikasi manual transaksi yang memiliki `metode_pembayaran != 'Midtrans'` atau `NULL`. Transaksi Midtrans terlindungi dari verifikasi manual salah sasaran.
2. **Tampilan UI Admin:**
   - **Status:** **PASS**
   - **Bukti:** `admin/pesanan.php:447-458`
     Transaksi Midtrans menampilkan label `"Otomatis (Midtrans)"` sebagai pengganti tombol verifikasi.
3. **Alur Upload Bukti Pembayaran Manual:**
   - **Status:** **PASS**
   - File `pelanggan/pembayaran.php` dan `pelanggan/proses_pembayaran.php` tidak mengalami perubahan logika dan tetap berfungsi penuh untuk pembayaran transfer bank manual.

---

## 3. Daftar Temuan Berdasarkan Tingkat Keparahan

### A. BLOCKER (Wajib Diperbaiki Sebelum Fase 3.2)

#### B-01: Request Paralel yang Menabrak Duplicate Key Mengembalikan HTTP 500
- **File:** `pelanggan/create_snap_token.php:401-412`
- **Uraian:** Ketika dua request dengan idempotency key yang sama dieksekusi bersamaan, request kedua gagal pada `INSERT INTO transaksi` karena unique constraint `idx_checkout_idempotency_key`. Kegagalan ini langsung ditangkap oleh blok generic `catch` dan mengembalikan HTTP 500.
- **Dampak:** Pengguna melihat pesan error sistem (HTTP 500) padahal transaksinya berhasil dibuat oleh request pertama.
- **Solusi yang Dibutuhkan:**
  Pada blok `catch`, jika kode error adalah MySQL error 1062 (Duplicate entry):
  Lakukan query ulang `SELECT id_transaksi, midtrans_order_id, snap_token, snap_status FROM transaksi WHERE checkout_idempotency_key = ?`.
  Jika transaksi ditemukan, kembalikan respons sesuai status transaksi tersebut (HTTP 429 jika masih `Menunggu`, atau HTTP 200 dengan `snap_token` jika sudah `Berhasil`).

---

### B. WARNING (Perlu Disempurnakan)

#### W-01: Status `snap_status = 'Menunggu'` Rentan Menjadi Status Yatim (Stuck)
- **File:** `pelanggan/create_snap_token.php:204-211`
- **Uraian:** Jika proses PHP mati di antara Tahap 1 dan Tahap 2, `snap_status` akan selamanya bernilai `'Menunggu'`.
- **Dampak:** Pengguna yang merefresh halaman akan terkunci permanen dengan HTTP 429.
- **Rekomendasi:** Tambahkan batas waktu toleransi (misalnya: jika `snap_status === 'Menunggu'` dan selisih waktu `created_at` atau `updated_at` sudah lebih dari 2 menit, izinkan sistem melakukan recovery atau memicu request Snap ulang).

#### W-02: Pelepasan Reservasi Berisiko saat Gagal Simpan Token Setelah Midtrans Merespons
- **File:** `pelanggan/create_snap_token.php:464, 520-536`
- **Uraian:** Jika Midtrans berhasil menerbitkan token, namun query `UPDATE transaksi SET snap_token = ...` gagal, script masuk ke Tahap 3C yang menandai status `'Gagal'` dan melepas reservasi stok.
- **Dampak:** Transaksi sudah terbentuk di Midtrans, tetapi reservasi di database lokal dilepas.
- **Rekomendasi:** Jika exception terjadi *setelah* `$snapResponse` diterima dari Midtrans, transaksi harus ditandai sebagai `'Perlu Rekonsiliasi'`, bukan `'Gagal'`, dan reservasi stok **tidak boleh dilepas**.

---

## 4. SQL Pemeriksaan Database (Aman & Non-Destruktif)

Sebelum menjalankan file migrasi apa pun, jalankan kueri berikut untuk memeriksa struktur aktual database `beautyland`:

```sql
-- 1. Periksa kolom yang sudah ada pada tabel `transaksi`
SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = 'beautyland' AND TABLE_NAME = 'transaksi'
  AND COLUMN_NAME IN (
    'midtrans_order_id', 'midtrans_transaction_id', 'snap_token',
    'midtrans_payment_type', 'midtrans_status', 'notification_payload',
    'stok_dikurangi', 'checkout_idempotency_key', 'snap_status', 'snap_expires_at'
  );

-- 2. Periksa index yang sudah ada pada tabel `transaksi`
SELECT INDEX_NAME, COLUMN_NAME, NON_UNIQUE
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = 'beautyland' AND TABLE_NAME = 'transaksi'
  AND INDEX_NAME IN ('idx_midtrans_order_id', 'idx_checkout_idempotency_key', 'idx_snap_status');

-- 3. Periksa kolom `stok_direservasi` pada tabel `produk`
SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = 'beautyland' AND TABLE_NAME = 'produk'
  AND COLUMN_NAME = 'stok_direservasi';

-- 4. Periksa keberadaan tabel `reservasi_stok`
SELECT TABLE_NAME, TABLE_TYPE, ENGINE
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = 'beautyland' AND TABLE_NAME = 'reservasi_stok';
```

---

## 5. Status Kesiapan

### Status Kesiapan Migrasi: **MENUNGGU VERIFIKASI PRE-FLIGHT**
- Skrip migrasi `20261126_add_stock_reservations_and_checkout_idempotency.sql` sudah siap secara sintaksis.
- Namun, sebelum dieksekusi, administrator database wajib menjalankan kueri inspeksi di atas untuk memastikan bagian migrasi Fase 2 mana yang sudah terpasang agar tidak terjadi kegagalan akibat duplikasi kolom/index.

### Status Kesiapan Fase 3.2: **MEMBUTUHKAN PERBAIKAN BLOCKER B-01**
- Fondasi reservasi stok (`includes/stock_reservation.php`) sudah siap digunakan oleh Webhook Fase 3.2 (`convert_stock_reservation` dan `release_stock_reservation`).
- Fase 3.2 baru boleh dimulai setelah perbaikan B-01 diterapkan pada `pelanggan/create_snap_token.php`.
