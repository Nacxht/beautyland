-- =============================================================================
-- Migrasi: Penambahan Reservasi Stok dan Idempotency Checkout
-- Tanggal: 26 November 2026
-- Referensi: docs/ai-generated/audit-checkout-midtrans.md (Fase 3.1.1)
--
-- Prasyarat:
--   1. Schema dasar (beauty_care (1).sql) telah diimpor ke database `beautyland`.
--   2. Migrasi Fase 2 (20261126_add_midtrans_columns.sql) telah dijalankan.
--
-- Database: beautyland (MySQL 8.0.30, InnoDB, utf8mb4_0900_ai_ci)
--
-- Perubahan dalam migrasi ini:
--   1. Tambah kolom `stok_direservasi` pada tabel `produk`.
--   2. Tambah kolom idempotency & snap status pada tabel `transaksi`.
--   3. Buat tabel `reservasi_stok` untuk pencatatan reservasi sementara.
--
-- Cara menjalankan secara manual:
--   mysql -u root beautyland < database/migrations/20261126_add_stock_reservations_and_checkout_idempotency.sql
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 1. Tambah kolom agregat reservasi pada tabel `produk`
--    Nilai default 0 agar tidak mengganggu data produk yang sudah ada.
-- -----------------------------------------------------------------------------

ALTER TABLE `produk`
  ADD COLUMN `stok_direservasi` INT NOT NULL DEFAULT 0 AFTER `stok`;

-- -----------------------------------------------------------------------------
-- 2. Tambah kolom idempotency dan status Snap pada tabel `transaksi`
--    - `checkout_idempotency_key`: identifier unik satu proses checkout.
--    - `snap_status`: status pembuatan token ('Menunggu', 'Berhasil', 'Gagal', 'Perlu Rekonsiliasi').
--    - `snap_expires_at`: batas waktu berlakunya pembayaran Midtrans dan reservasi stok.
-- -----------------------------------------------------------------------------

ALTER TABLE `transaksi`
  ADD COLUMN `checkout_idempotency_key` VARCHAR(64) DEFAULT NULL AFTER `stok_dikurangi`,
  ADD COLUMN `snap_status` VARCHAR(30) DEFAULT NULL AFTER `checkout_idempotency_key`,
  ADD COLUMN `snap_expires_at` TIMESTAMP NULL DEFAULT NULL AFTER `snap_status`;

ALTER TABLE `transaksi`
  ADD UNIQUE INDEX `idx_checkout_idempotency_key` (`checkout_idempotency_key`),
  ADD INDEX `idx_snap_status` (`snap_status`);

-- -----------------------------------------------------------------------------
-- 3. Buat tabel `reservasi_stok`
--    Menyimpan reservasi kuantitas produk per transaksi dengan lifecycle:
--    'Aktif' -> 'Dikonversi' (pembayaran lunas)
--            -> 'Dilepas' (pembayaran batal / expired / gagal)
-- -----------------------------------------------------------------------------

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
