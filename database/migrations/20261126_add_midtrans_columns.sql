-- =============================================================================
-- Migrasi: Tambah kolom Midtrans pada tabel transaksi
-- Tanggal: 26 November 2026
-- Referensi: docs/ai-generated/audit-checkout-midtrans.md, Fase 2
--
-- Database: beautyland (MySQL 8.0.30, InnoDB, utf8mb4_0900_ai_ci)
--
-- Urutan eksekusi:
--   1. ALTER TABLE — tambah 7 kolom Midtrans + 1 kolom idempotency
--   2. CREATE INDEX — unique index pada midtrans_order_id
--   3. UPDATE — tandai transaksi historis berstatus 'Lunas'
--
-- Cara menjalankan:
--   mysql -u root beautyland < database/migrations/20261126_add_midtrans_columns.sql
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 1. Tambah kolom Midtrans (setelah kolom bukti_pembayaran)
-- -----------------------------------------------------------------------------

ALTER TABLE `transaksi`
  ADD COLUMN `midtrans_order_id`        VARCHAR(50)  DEFAULT NULL AFTER `bukti_pembayaran`,
  ADD COLUMN `midtrans_transaction_id`  VARCHAR(255) DEFAULT NULL AFTER `midtrans_order_id`,
  ADD COLUMN `snap_token`               VARCHAR(255) DEFAULT NULL AFTER `midtrans_transaction_id`,
  ADD COLUMN `midtrans_payment_type`    VARCHAR(50)  DEFAULT NULL AFTER `snap_token`,
  ADD COLUMN `midtrans_status`          VARCHAR(50)  DEFAULT NULL AFTER `midtrans_payment_type`,
  ADD COLUMN `notification_payload`     JSON         DEFAULT NULL AFTER `midtrans_status`,
  ADD COLUMN `updated_at`              TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `notification_payload`;

-- -----------------------------------------------------------------------------
-- 2. Unique index pada midtrans_order_id
--    Kolom nullable: transaksi manual (Transfer Bank) tidak memiliki order ID
--    MySQL mengizinkan beberapa NULL pada UNIQUE index
-- -----------------------------------------------------------------------------

ALTER TABLE `transaksi`
  ADD UNIQUE INDEX `idx_midtrans_order_id` (`midtrans_order_id`);

-- -----------------------------------------------------------------------------
-- 3. Tambah kolom idempotency untuk pengurangan stok
-- -----------------------------------------------------------------------------

ALTER TABLE `transaksi`
  ADD COLUMN `stok_dikurangi` TINYINT(1) NOT NULL DEFAULT 0 AFTER `updated_at`;

-- -----------------------------------------------------------------------------
-- 4. Tandai transaksi historis yang sudah Lunas
--    Stok untuk transaksi ini sudah dikurangi oleh proses_pembayaran.php
--    Berdasarkan dump: 17 baris dengan status_pembayaran = 'Lunas'
-- -----------------------------------------------------------------------------

UPDATE `transaksi`
SET `stok_dikurangi` = 1
WHERE `status_pembayaran` = 'Lunas';
