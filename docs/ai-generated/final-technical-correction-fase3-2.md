# Laporan Koreksi Teknis Akhir: Fase 3.2 Midtrans Webhook

**Proyek:** Beautyland (PHP Native 8.1, MariaDB 12.2, Midtrans Snap)  
**Dokumen:** Final Technical Correction & Migration Readiness Report  
**Tanggal:** 26 November 2026  
**Dokumen Rujukan:**
- `docs/ai-generated/analysis-fase3-2-webhook.md`
- `docs/ai-generated/final-review-fase3-2-webhook.md`
- `database/migrations/20261126_add_stock_reservations_and_checkout_idempotency.sql`
- `admin/pengiriman.php`

---

## 1. Rincian Koreksi Teknis yang Diselesaikan

### 1.1. Pemurnian Semantik `snap_status`
- **Masalah Awal:** Pada draf sebelumnya, skenario late settlement sempat dirancang untuk mengubah `snap_status = 'Perlu Rekonsiliasi'`, dan skenario refund sempat dirancang mengubah `snap_status = 'Gagal'`. Hal ini melanggar definisi semantik dasar bahwa `snap_status` hanya mencatat siklus hidup inisiasi token Snap.
- **Koreksi:**
  - `snap_status` bernilai `'Berhasil'` begitu token Midtrans berhasil diperoleh dan disimpan saat checkout.
  - Nilai `'Berhasil'` ini bersifat permanen terhadap event-event pasca-checkout (tidak boleh diubah menjadi `'Perlu Rekonsiliasi'` pada late settlement, dan tidak boleh diubah menjadi `'Gagal'` pada refund/pembatalan).
  - Kasus rekonsiliasi late settlement diidentifikasi secara deterministik menggunakan kombinasi kolom eksisting:
    ```sql
    WHERE status_pembayaran = 'Dibatalkan' AND midtrans_status = 'settlement'
    ```
  - Kasus rekonsiliasi late settlement yang telah di-refund diidentifikasi melalui:
    ```sql
    WHERE status_pembayaran = 'Dibatalkan' AND midtrans_status = 'refund'
    ```
  - Tidak diperlukan penambahan kolom baru pada skema database.

### 1.2. Rantai Skenario Lengkap Late Settlement dan Refund
Ditetapkan alur state machine 4 skenario beruntun:
1. **Pesanan `Dibatalkan` Menerima `settlement`:**
   - Invarian: Reservasi yang sudah dilepas **TIDAK DIKONVERSI**, stok fisik **TIDAK DIPOTONG**, status pesanan **TIDAK DIHIDUPKAN KEMBALI** (tetap `'Dibatalkan'`).
   - `midtrans_status` diperbarui menjadi `'settlement'`. `snap_status` tetap `'Berhasil'`.
   - Log alert kritis dicatat untuk tindakan refund admin di portal Midtrans MAP atau kontak manual.
2. **Pesanan Tersebut Menerima `settlement` Duplikat (Retry):**
   - Deteksi idempotensi: `status_pembayaran === 'Dibatalkan'` dan `midtrans_status === 'settlement'`.
   - Sistem melakukan No-Op dan mengembalikan `HTTP 200 OK` tanpa tindakan berulang.
3. **Pesanan Tersebut Kemudian Menerima Notifikasi `refund`:**
   - Invarian: Status pesanan **DILARANG BERUBAH MENJADI 'Lunas' MAUPUN 'Refund'** (pesanan ini tidak pernah lunas dan tidak pernah dipenuhi; status lokalnya tetap `'Dibatalkan'`).
   - `midtrans_status` diperbarui menjadi `'refund'`.
   - `snap_status` tetap `'Berhasil'`. Stok fisik tidak disesuaikan (karena sebelumnya memang tidak dipotong).
   - Mengembalikan `HTTP 200 OK`.
4. **Pesanan Tersebut Menerima Notifikasi `pending` yang Terlambat:**
   - Diabaikan sebagai event kadaluwarsa (*out-of-order*). No-Op, mengembalikan `HTTP 200 OK`.

### 1.3. Proteksi Backend Modul Pengiriman (`admin/pengiriman.php`)
- **Masalah pada Kode Eksisting:** Handler `$_POST['simpan']` pada `admin/pengiriman.php` hanya mengecek `status_pengiriman !== 'Selesai'`, tanpa memeriksa `transaksi.status_pembayaran`.
- **Koreksi Arsitektural untuk Tahap Implementasi:**
  1. Backend handler wajib memvalidasi relasi ke transaksi sebelum mengeksekusi kueri `UPDATE pengiriman`:
     Jika `status_pembayaran IN ('Dibatalkan', 'Refund')`, proses update nomor resi atau jasa kirim **wajib ditolak** di backend (tidak hanya mengandalkan penonaktifan tombol di frontend).
  2. Status pengiriman historis (`nomor_resi`, `tanggal_kirim`, `status_pengiriman`) **DILARANG DIHAPUS / DI-RESET SECARA OTOMATIS** saat webhook menerima `refund`, guna mempertahankan jejak fisik logistik yang telah terjadi.
  3. UI form pada `admin/pengiriman.php` wajib dinonaktifkan untuk pesanan yang dibatalkan atau di-refund.

### 1.4. Audit Keamanan Webhook & Pernyataan Keterbatasan
- Endpoint `midtrans_notification.php` wajib menerapkan:
  1. Validasi HTTP `POST`.
  2. Parsing JSON murni stream `php://input`.
  3. Validasi keberadaan 5 field wajib (`order_id`, `status_code`, `gross_amount`, `signature_key`, `transaction_status`).
  4. Verifikasi signature SHA-512 menggunakan `hash_equals()`.
  5. Validasi `gross_amount` tanpa floating point (regex format strict integer + perbandingan identik).
  6. Pencarian transaksi via `midtrans_order_id` dengan `SELECT ... FOR UPDATE`.
  7. Transaksi database atomik (`begin_transaction`, `commit`, `rollback`).
  8. Idempotensi multi-lapis (`stok_dikurangi` dan status `reservasi_stok`).
  9. Penanganan database failure: kembalikan `HTTP 500` saat kegagalan transient agar Midtrans melakukan retry otomatis.
- **Pernyataan Batasan Pengujian:** Keamanan webhook **tidak boleh diklaim telah teruji** sebelum pengujian penetrasi runtime dengan berbagai simulasi mock payload (sukses, duplikat, signature palsu, nominal manipulasi) dieksekusi secara nyata.

---

## 2. Audit Langsung File Migrasi SQL

Pemeriksaan baris demi baris terhadap file migrasi:
`database/migrations/20261126_add_stock_reservations_and_checkout_idempotency.sql`

### 2.1. Tinjauan Isi SQL Aktual
```sql
-- Operasi 1: Penambahan kolom agregat reservasi pada produk
ALTER TABLE `produk`
  ADD COLUMN `stok_direservasi` INT NOT NULL DEFAULT 0 AFTER `stok`;

-- Operasi 2: Penambahan kolom idempotensi dan snapshot pada transaksi
ALTER TABLE `transaksi`
  ADD COLUMN `checkout_idempotency_key` VARCHAR(64) DEFAULT NULL AFTER `stok_dikurangi`,
  ADD COLUMN `snap_status` VARCHAR(30) DEFAULT NULL AFTER `checkout_idempotency_key`,
  ADD COLUMN `snap_expires_at` TIMESTAMP NULL DEFAULT NULL AFTER `snap_status`;

-- Operasi 3: Pembuatan indeks unik dan indeks pencarian
ALTER TABLE `transaksi`
  ADD UNIQUE INDEX `idx_checkout_idempotency_key` (`checkout_idempotency_key`),
  ADD INDEX `idx_snap_status` (`snap_status`);

-- Operasi 4: Pembuatan tabel reservasi stok terpisah
CREATE TABLE IF NOT EXISTS `reservasi_stok` (
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

### 2.2. Hasil Verifikasi Read-Only Database Aktual
Pemeriksaan langsung tanpa modifikasi terhadap server MariaDB 12.2.2 menghasilkan data berikut:
- **Tabel `produk`:** Kolom `stok` bertipe `int(11)`. Kolom `stok_direservasi` terbukti **belum ada**.
- **Tabel `transaksi`:** Kolom `stok_dikurangi` bertipe `tinyint(1)`. Kolom `checkout_idempotency_key`, `snap_status`, dan `snap_expires_at` terbukti **belum ada**.
- **Indeks pada `transaksi`:** Indeks `idx_checkout_idempotency_key` dan `idx_snap_status` terbukti **belum ada**.
- **Tabel `reservasi_stok`:** Terbukti **belum ada**.
- **Kesesuaian Tipe Foreign Key:** Kolom induk `transaksi.id_transaksi` dan `produk.id_produk` bertipe `int(11) NOT NULL PRIMARY KEY`. Tipe kolom anak `id_transaksi` dan `id_produk` pada `reservasi_stok` bertipe `INT NOT NULL`. Tipe data dan panjang bit cocok sempurna.
- **Engine dan Collation:** Engine `InnoDB` dan collation `utf8mb4_0900_ai_ci` didukung penuh oleh server MariaDB 12.2.

### 2.3. Analisis Idempotensi dan Risiko Kegagalan DDL
1. **Ketiadaan Sifat Idempoten:** File SQL ini **TIDAK IDEMPOTEN**. Jika dijalankan ulang setelah sebagian statement berhasil dieksekusi, perintah `ALTER TABLE ... ADD COLUMN` akan gagal dengan `Error 1060 (Duplicate column name)`.
2. **Sifat Non-Transaksional (Implicit Commit):** Setiap perintah `ALTER TABLE` langsung di-commit oleh MariaDB dan tidak dapat di-rollback.
3. **Prosedur Pemulihan (Recovery) Jika Terhenti di Tengah Jalan:**
   Jika eksekusi terputus (misalnya koneksi putus saat Operasi 3):
   - Administrator harus memeriksa kolom mana yang sudah terlanjur dibuat.
   - Jalankan statement pembersihan manual untuk mengembalikan skema ke kondisi awal:
     ```sql
     ALTER TABLE produk DROP COLUMN IF EXISTS stok_direservasi;
     ALTER TABLE transaksi DROP COLUMN IF EXISTS snap_expires_at, DROP COLUMN IF EXISTS snap_status, DROP COLUMN IF EXISTS checkout_idempotency_key;
     DROP TABLE IF EXISTS reservasi_stok;
     ```
   - Setelah bersih, jalankan kembali script migrasi dari baris pertama.

---

## 3. Kesimpulan Evaluasi Kesiapan

Berdasarkan seluruh hasil audit, evaluasi diklasifikasikan ke dalam 5 status tegas:

### 1. Desain yang Sudah Disetujui
- Desain arsitektur endpoint `midtrans_notification.php` telah disetujui penuh:
  - Verifikasi signature SHA-512 menggunakan `hash_equals()`.
  - Normalisasi nominal deterministik anti-floating point.
  - Pengetatan isolasi status `capture` dengan proteksi *fail-safe*.
  - Rantai state machine late settlement & refund tanpa penambahan kolom database baru.
  - Penegakan semantik murni `snap_status`.

### 2. Koreksi yang Masih Diperlukan (Pada Tahap Implementasi Kode)
- Penulisan endpoint `midtrans_notification.php` sesuai spesifikasi final.
- Penambahan validasi backend pada `admin/pengiriman.php` agar pesanan `Dibatalkan` dan `Refund` tidak dapat diproses kirim.
- Penambahan styling CSS badge status `'Refund'` pada `pelanggan/pesanan_user.php` dan `admin/pesanan.php`.

### 3. Status Validasi SQL
- **TERVALIDASI 100% AMAN UNTUK CLEAN RUN.**
- Tidak ada benturan nama kolom atau indeks dengan skema eksisting.
- Tipe data foreign key dan collation kompatibel.

### 4. Status Kesiapan Eksekusi Migrasi
- **READY FOR EXECUTION (BLOCKER AKTIF SEBELUM KODING).**
- File migrasi `database/migrations/20261126_add_stock_reservations_and_checkout_idempotency.sql` aman untuk dieksekusi satu kali secara utuh oleh administrator database.
- Eksekusi migrasi ini merupakan prasyarat mutlak sebelum kode `midtrans_notification.php` dapat diuji secara runtime.

### 5. Langkah Berikutnya
1. **Administrator mengeksekusi migrasi Fase 3.1.1** ke kontainer MariaDB aktif.
2. **Verifikasi ketersediaan tabel `reservasi_stok` dan kolom `stok_direservasi`.**
3. **Implementasi kode `midtrans_notification.php`** berdasarkan dokumen `analysis-fase3-2-webhook.md`.
4. **Patch validasi backend pada `admin/pengiriman.php`.**
5. **Pengujian runtime simulasi webhook menggunakan mock request Midtrans.**
