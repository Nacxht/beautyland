# Laporan Migrasi Database: Integrasi Midtrans Snap

**Tanggal:** 26 November 2026  
**Referensi:** `docs/ai-generated/audit-checkout-midtrans.md` (Fase 2)  
**File Migrasi:** `database/migrations/20261126_add_midtrans_columns.sql`  
**Status:** File migrasi telah dibuat, **belum dieksekusi** ke database aktif.

---

## 1. Struktur Tabel `transaksi` Sebelum Migrasi

Berdasarkan dump database `beauty_care (1).sql` (MySQL 8.0.30, InnoDB, charset `utf8mb4`, collation `utf8mb4_0900_ai_ci`):

| Kolom | Tipe Data | Nullable | Default | Keterangan |
|---|---|---|---|---|
| `id_transaksi` | int | NO | AUTO_INCREMENT | Primary Key |
| `id_pelanggan` | int | YES | NULL | Foreign Key -> `pelanggan.id_pelanggan` |
| `tgl_transaksi` | timestamp | YES | CURRENT_TIMESTAMP | Waktu transaksi |
| `total_harga` | int | YES | NULL | Total nilai transaksi |
| `metode_pembayaran` | varchar(50) | YES | NULL | Default: 'Transfer Bank' |
| `status_pembayaran` | varchar(50) | YES | NULL | Nilai: 'Menunggu Verifikasi', 'Lunas', 'Dibatalkan' |
| `kode_pembayaran` | varchar(30) | YES | NULL | Format 'MB-XXXXXXXX' |
| `bukti_pembayaran` | varchar(255) | YES | NULL | Nama file di `assets/bukti/` |

**Index saat ini:**
- `PRIMARY KEY (`id_transaksi`)`
- `KEY `id_pelanggan` (`id_pelanggan`)`

Tidak ditemukan kolom atau index terkait Midtrans sebelum migrasi ini.

---

## 2. Kolom dan Index yang Ditambahkan

### Kolom Baru pada Tabel `transaksi`

| Kolom | Tipe Data | Nullable | Default | Posisi | Tujuan |
|---|---|---|---|---|---|
| `midtrans_order_id` | VARCHAR(50) | YES | NULL | AFTER `bukti_pembayaran` | Order ID unik yang dikirim ke Midtrans (`BL-{id_transaksi}-{timestamp}`) |
| `midtrans_transaction_id` | VARCHAR(255) | YES | NULL | AFTER `midtrans_order_id` | Transaction ID resmi dari Midtrans |
| `snap_token` | VARCHAR(255) | YES | NULL | AFTER `midtrans_transaction_id` | Token Snap untuk memunculkan popup pembayaran |
| `midtrans_payment_type` | VARCHAR(50) | YES | NULL | AFTER `snap_token` | Metode pembayaran yang dipilih di Snap (cth: `bank_transfer`, `gopay`) |
| `midtrans_status` | VARCHAR(50) | YES | NULL | AFTER `midtrans_payment_type` | Status transaksi dari Midtrans (`settlement`, `pending`, `expire`, dll) |
| `notification_payload` | JSON | YES | NULL | AFTER `midtrans_status` | Payload JSON utuh dari HTTP webhook notifikasi |
| `updated_at` | TIMESTAMP | NO | CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP | AFTER `notification_payload` | Jejak waktu pembaruan status terakhir |
| `stok_dikurangi` | TINYINT(1) | NO | 0 | AFTER `updated_at` | Flag idempotency penanganan webhook stok |

### Index Baru

- **Nama:** `idx_midtrans_order_id`
- **Tipe:** `UNIQUE`
- **Target Kolom:** `midtrans_order_id`
- **Catatan:** Nilai `NULL` diizinkan ganda oleh InnoDB MySQL, sehingga transaksi manual (transfer bank) yang tidak memiliki `midtrans_order_id` tidak akan mengalami konflik.

---

## 3. Penyesuaian Data Historis

Perintah `UPDATE` disiapkan untuk menjaga konsistensi alur baru dengan alur lama:

```sql
UPDATE `transaksi`
SET `stok_dikurangi` = 1
WHERE `status_pembayaran` = 'Lunas';
```

- **Jumlah baris terkena dampak:** **17 transaksi** (berdasarkan dump `beauty_care (1).sql`).
- **Daftar ID transaksi:** 1, 4, 5, 6, 7, 8, 9, 10, 11, 12, 19, 20, 22, 23, 25, 39, 40.
- **Tujuan:** Menandai transaksi lama yang sudah lunas bahwa stok produknya sudah terpotong di masa lalu, mencegah pengurangan stok ganda bila ada proses batch di kemudian hari.

---

## 4. Kompatibilitas Database Proyek

- **Tipe `JSON`:** Didukung penuh secara native pada MySQL 8.0.30 (fitur tersedia sejak MySQL 5.7.8).
- **Storage Engine:** Menggunakan InnoDB, mendukung transaksi atomik dan penguncian baris (`FOR UPDATE`) yang dibutuhkan saat Fase 3 (Webhook).
- **Charset & Collation:** Kolom baru secara default mengikuti tabel induk yaitu `utf8mb4` dengan collation `utf8mb4_0900_ai_ci`.

---

## 5. Cara Menjalankan Migrasi Manual

Jalankan perintah berikut di terminal server:

```bash
mysql -u root beautyland < database/migrations/20261126_add_midtrans_columns.sql
```

Atau jika menggunakan kata sandi database:

```bash
mysql -u root -p beautyland < database/migrations/20261126_add_midtrans_columns.sql
```
