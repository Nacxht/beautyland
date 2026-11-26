# Laporan Perbaikan Lanjutan P1/P2 — Midtrans & Reservasi Stok

**Branch:** `PPL-12`  
**Dokumen:** `docs/ai-generated/fix-followup-fase3-2.md`  
**Tanggal:** 26 November 2026  
**Status Audit:** Siap Diaudit Ulang

---

## 1. Ringkasan Temuan Audit yang Diperbaiki

Berdasarkan hasil audit lanjutan terhadap branch `PPL-12`, ditemukan tiga kelemahan kritis (P1/P2) terkait mekanisme transaksi Midtrans dan pengelolaan reservasi stok:

1. **Kegagalan Pelepasan Reservasi pada Snap Failure (P1):**
   Pada implementasi sebelumnya di `pelanggan/create_snap_token.php:605-645`, jika inisiasi transaksi Midtrans Snap mengembalikan error penolakan definitif (Tahap 3C), pembaruan status transaksi ke `Gagal` dieksekusi di luar transaksi database. Selain itu, `checkout_idempotency_key` pada `$_SESSION['pending_order']` di-generate ulang secara membabi buta sekalipun `release_stock_reservation()` mengalami kegagalan. Akibatnya, terjadi celah di mana status transaksi menjadi `Gagal`, reservasi stok masih menggantung dengan status `Aktif`, dan pengguna diizinkan melakukan checkout baru yang meninggalkan stok terkunci (*dead reservation lock*).

2. **Validasi Status Reservasi Saat Release Kurang Ketat (P1):**
   Fungsi `release_stock_reservation()` di `includes/stock_reservation.php:141-240` sebelumnya belum memvalidasi status keseluruhan baris reservasi secara menyeluruh sebelum melakukan mutasi data. Kondisi reservasi yang sudah dikonversi atau berstatus campuran (*mixed state*, misalnya sebagian `Aktif` dan sebagian `Dikonversi`) berisiko di-update sebagian (*partial release*). Selain itu, ketiadaan baris reservasi pada transaksi Midtrans diperlakukan sama dengan transaksi manual (dianggap sukses `released = 0`), menyembunyikan inkonsistensi data transaksi Midtrans yang seharusnya memiliki baris reservasi.

3. **Penggunaan Klausa `GREATEST(0, ...)` yang Menyembunyikan Inkonsistensi Stok (P2):**
   Pada `includes/stock_reservation.php`, kueri mutasi stok menggunakan `GREATEST(0, stok - ?)` dan `GREATEST(0, stok_direservasi - ?)`. Pendekatan ini secara diam-diam memaksa nilai stok menjadi nol jika terjadi defisit stok fisik atau reservasi tanpa memicu peringatan, rollback, atau pencatatan rekonsiliasi. Tidak terdapat validasi prasyarat eksplisit (`stok >= jumlah` dan `stok_direservasi >= jumlah`) di bawah perlindungan *row-level locking* (`SELECT ... FOR UPDATE`).

---

## 2. Daftar File yang Diubah dan Alasan Perubahannya

| No | File | Status | Alasan Perubahan |
|---|---|---|---|
| 1 | `pelanggan/create_snap_token.php` | Modifikasi | Menyatukan pembatalan Snap dan pelepasan reservasi ke dalam transaksi atomik MySQL dengan row-level lock `FOR UPDATE`. Memastikan `checkout_idempotency_key` baru HANYA dibuat setelah reservasi berhasil dilepas dan di-commit. Mengarahkan transaksi ke `Perlu Rekonsiliasi` jika release gagal serta mempertahankan idempotency key lama agar tidak dapat membuat checkout baru yang menumpuk reservasi aktif. |
| 2 | `includes/stock_reservation.php` | Modifikasi | Memperketat validasi awal status reservasi pada `release_stock_reservation()` dan `convert_stock_reservation()`. Membedakan transaksi manual (`$is_midtrans = false`) dan transaksi Midtrans (`$is_midtrans = true`). Menghilangkan klausa `GREATEST(0, ...)`, menggantikannya dengan validasi eksplisit di bawah kueri `SELECT ... FOR UPDATE`, serta conditional UPDATE berbasis `affected_rows`. Mengurutkan kueri produk berdasarkan `id_produk ASC, id_reservasi ASC`. |
| 3 | `pelanggan/proses_pembayaran.php` | Modifikasi | Menyesuaikan pemanggilan `release_stock_reservation($conn, $id_cancel, $is_midtrans)` saat pembatalan pesanan oleh pelanggan, dengan mendeteksi metode pembayaran transaksi (`metode_pembayaran === 'Midtrans'`) agar kompatibilitas transaksi pembayaran manual tetap terjaga utuh. |
| 4 | `tests/test_midtrans_stock_reservation.php` | Dibuat | Menambahkan rangkaian unit/mock test suite otomatis 12 skenario untuk memverifikasi seluruh alur status reservasi, rollback, idempotency key, dan penolakan stok defisit tanpa menyentuh database aktif proyek. |

### 2.1. Git Diff Perubahan

```diff
diff --git a/includes/stock_reservation.php b/includes/stock_reservation.php
index af4ca09..7d9dcbc 100644
--- a/includes/stock_reservation.php
+++ b/includes/stock_reservation.php
@@ -112,11 +112,21 @@ function reserve_stock(mysqli $conn, int $id_transaksi, array $items, int $durat
 
         // Tambah reservasi pada agregat produk
         $stmt_update_prod->bind_param('ii', $qty, $id_produk);
-        $stmt_update_prod->execute();
+        if (!$stmt_update_prod->execute() || $stmt_update_prod->affected_rows === 0) {
+            $stmt_check->close();
+            $stmt_update_prod->close();
+            $stmt_insert_res->close();
+            return ['success' => false, 'message' => "Gagal memperbarui agregat reservasi untuk produk ID {$id_produk}."];
+        }
 
         // Catat baris reservasi
         $stmt_insert_res->bind_param('iiis', $id_transaksi, $id_produk, $qty, $expires_at);
-        $stmt_insert_res->execute();
+        if (!$stmt_insert_res->execute() || $stmt_insert_res->affected_rows === 0) {
+            $stmt_check->close();
+            $stmt_update_prod->close();
+            $stmt_insert_res->close();
+            return ['success' => false, 'message' => "Gagal mencatat baris reservasi untuk produk ID {$id_produk}."];
+        }
     }
 
     $stmt_check->close();
@@ -138,7 +148,7 @@ function reserve_stock(mysqli $conn, int $id_transaksi, array $items, int $durat
  * @param int $id_transaksi ID transaksi yang reservasinya akan dilepas
  * @return array ['success' => bool, 'released' => int, 'message' => string]
  */
-function release_stock_reservation(mysqli $conn, int $id_transaksi): array
+function release_stock_reservation(mysqli $conn, int $id_transaksi, bool $is_midtrans = true): array
 {
     if ($id_transaksi <= 0) {
         return [
@@ -153,7 +163,7 @@ function release_stock_reservation(mysqli $conn, int $id_transaksi): array
         SELECT id_reservasi, id_produk, jumlah, status_reservasi 
         FROM reservasi_stok 
         WHERE id_transaksi = ? 
-        ORDER BY id_produk ASC 
+        ORDER BY id_produk ASC, id_reservasi ASC 
         FOR UPDATE
     ");
     if (!$stmt_get) {
@@ -175,23 +185,34 @@ function release_stock_reservation(mysqli $conn, int $id_transaksi): array
     }
     $stmt_get->close();
 
-    if (empty($all_reservations)) {
+    $total_count = count($all_reservations);
+
+    if ($total_count === 0) {
+        if (!$is_midtrans) {
+            return [
+                'success'          => true,
+                'released'         => 0,
+                'already_released' => false,
+                'message'          => 'Tidak ada reservasi untuk transaksi pembayaran manual.'
+            ];
+        }
         return [
-            'success'          => true,
+            'success'          => false,
             'released'         => 0,
             'already_released' => false,
-            'message'          => 'Tidak ada reservasi untuk transaksi ini.'
+            'needs_reconciliation' => true,
+            'message'          => "Tidak ada reservasi ditemukan untuk transaksi Midtrans [{$id_transaksi}]. Memerlukan rekonsiliasi."
         ];
     }
 
-    $active_reservations = [];
-    $released_count      = 0;
-    $converted_count     = 0;
+    $active_count    = 0;
+    $released_count  = 0;
+    $converted_count = 0;
 
     foreach ($all_reservations as $r) {
         $st = (string)($r['status_reservasi'] ?? '');
         if ($st === 'Aktif') {
-            $active_reservations[] = $r;
+            $active_count++;
         } elseif ($st === 'Dilepas') {
             $released_count++;
         } elseif ($st === 'Dikonversi') {
@@ -199,7 +220,7 @@ function release_stock_reservation(mysqli $conn, int $id_transaksi): array
         }
     }
 
-    if (count($active_reservations) === 0 && $released_count > 0 && $converted_count === 0) {
+    if ($released_count === $total_count) {
         return [
             'success'          => true,
             'released'         => 0,
@@ -208,21 +229,50 @@ function release_stock_reservation(mysqli $conn, int $id_transaksi): array
         ];
     }
 
-    if (empty($active_reservations)) {
+    if ($converted_count === $total_count) {
         return [
-            'success'          => true,
+            'success'              => false,
             'released'             => 0,
             'already_released'     => false,
-            'message'          => 'Tidak ada reservasi aktif yang perlu dilepas.'
+            'already_converted'    => true,
+            'message'              => "Semua reservasi untuk transaksi [{$id_transaksi}] telah dikonversi. Release tidak dilakukan."
+        ];
+    }
+
+    if ($active_count !== $total_count) {
+        return [
+            'success'              => false,
+            'released'             => 0,
+            'already_released'     => false,
+            'needs_reconciliation' => true,
+            'message'              => "Status reservasi tidak konsisten untuk transaksi [{$id_transaksi}] (Aktif: {$active_count}, Dilepas: {$released_count}, Dikonversi: {$converted_count}) dari total {$total_count}. Memerlukan rekonsiliasi."
+        ];
+    }
+
+    $active_reservations = $all_reservations;
+
+    $stmt_check_prod = $conn->prepare("
+        SELECT id_produk, stok_direservasi 
+        FROM produk 
+        WHERE id_produk = ? 
+        FOR UPDATE
+    ");
+    if (!$stmt_check_prod) {
+        return [
+            'success'          => false,
+            'released'         => 0,
+            'already_released' => false,
+            'message'          => 'Gagal mempersiapkan kueri validasi stok reservasi.'
         ];
     }
 
     $stmt_update_prod = $conn->prepare("
         UPDATE produk 
-        SET stok_direservasi = GREATEST(0, stok_direservasi - ?) 
-        WHERE id_produk = ?
+        SET stok_direservasi = stok_direservasi - ? 
+        WHERE id_produk = ? AND stok_direservasi >= ?
     ");
     if (!$stmt_update_prod) {
+        $stmt_check_prod->close();
         return [
             'success'          => false,
             'released'         => 0,
@@ -237,6 +287,7 @@ function release_stock_reservation(mysqli $conn, int $id_transaksi): array
         WHERE id_reservasi = ?
     ");
     if (!$stmt_update_res) {
+        $stmt_check_prod->close();
         $stmt_update_prod->close();
         return [
             'success'          => false,
@@ -252,33 +303,70 @@ function release_stock_reservation(mysqli $conn, int $id_transaksi): array
         $id_produk = (int)$r['id_produk'];
         $qty       = (int)$r['jumlah'];
 
-        $stmt_update_prod->bind_param('ii', $qty, $id_produk);
-        if (!$stmt_update_prod->execute()) {
+        $stmt_check_prod->bind_param('i', $id_produk);
+        $stmt_check_prod->execute();
+        $prod_data = $stmt_check_prod->get_result()->fetch_assoc();
+
+        if (!$prod_data) {
+            $stmt_check_prod->close();
+            $stmt_update_prod->close();
+            $stmt_update_res->close();
+            return [
+                'success'              => false,
+                'released'             => $count,
+                'already_released'     => false,
+                'needs_reconciliation' => true,
+                'message'              => "Produk ID {$id_produk} tidak ditemukan saat release. Memerlukan rekonsiliasi."
+            ];
+        }
+
+        $current_reserved = (int)$prod_data['stok_direservasi'];
+        if ($current_reserved < $qty) {
+            $stmt_check_prod->close();
+            $stmt_update_prod->close();
+            $stmt_update_res->close();
+            error_log("RECONCILIATION_NEEDED: stok_direservasi ({$current_reserved}) < qty ({$qty}) untuk produk ID {$id_produk} pada transaksi [{$id_transaksi}].");
+            return [
+                'success'              => false,
+                'released'             => $count,
+                'already_released'     => false,
+                'needs_reconciliation' => true,
+                'message'              => "Stok reservasi tidak mencukupi untuk produk ID {$id_produk} (tersedia: {$current_reserved}, dibutuhkan: {$qty}). Memerlukan rekonsiliasi."
+            ];
+        }
+
+        $stmt_update_prod->bind_param('iii', $qty, $id_produk, $qty);
+        if (!$stmt_update_prod->execute() || $stmt_update_prod->affected_rows === 0) {
+            $stmt_check_prod->close();
+            $stmt_update_prod->close();
+            $stmt_update_res->close();
+            return [
+                'success'              => false,
+                'released'             => $count,
+                'already_released'     => false,
+                'needs_reconciliation' => true,
+                'message'              => "Gagal melepaskan agregat reservasi produk ID {$id_produk} (conditional UPDATE gagal). Memerlukan rekonsiliasi."
             ];
         }
 
         $stmt_update_res->bind_param('i', $id_res);
-        if (!$stmt_update_res->execute()) {
+        if (!$stmt_update_res->execute() || $stmt_update_res->affected_rows === 0) {
+            $stmt_check_prod->close();
             $stmt_update_prod->close();
             $stmt_update_res->close();
             return [
-                'success'          => false,
-                'released'         => $count,
-                'already_released' => false,
-                'message'          => "Gagal memperbarui status reservasi ID {$id_res}."
+                'success'              => false,
+                'released'             => $count,
+                'already_released'     => false,
+                'needs_reconciliation' => true,
+                'message'              => "Gagal memperbarui status reservasi ID {$id_res}."
             ];
         }
 
         $count++;
     }
 
+    $stmt_check_prod->close();
     $stmt_update_prod->close();
     $stmt_update_res->close();
 
@@ -313,7 +401,7 @@ function convert_stock_reservation(mysqli $conn, int $id_transaksi): array
         SELECT id_reservasi, id_produk, jumlah, status_reservasi 
         FROM reservasi_stok 
         WHERE id_transaksi = ? 
-        ORDER BY id_produk ASC 
+        ORDER BY id_produk ASC, id_reservasi ASC 
         FOR UPDATE
     ");
     if (!$stmt_get) {
@@ -378,13 +466,29 @@ function convert_stock_reservation(mysqli $conn, int $id_transaksi): array
         ];
     }
 
+    $stmt_check_prod = $conn->prepare("
+        SELECT id_produk, stok, stok_direservasi 
+        FROM produk 
+        WHERE id_produk = ? 
+        FOR UPDATE
+    ");
+    if (!$stmt_check_prod) {
+        return [
+            'success'           => false,
+            'converted'         => 0,
+            'already_converted' => false,
+            'message'           => 'Gagal mempersiapkan kueri validasi stok produk.'
+        ];
+    }
+
     $stmt_update_prod = $conn->prepare("
         UPDATE produk 
-        SET stok = GREATEST(0, stok - ?),
-            stok_direservasi = GREATEST(0, stok_direservasi - ?) 
-        WHERE id_produk = ?
+        SET stok = stok - ?,
+            stok_direservasi = stok_direservasi - ? 
+        WHERE id_produk = ? AND stok >= ? AND stok_direservasi >= ?
     ");
     if (!$stmt_update_prod) {
+        $stmt_check_prod->close();
         return [
             'success'           => false,
             'converted'         => 0,
@@ -399,6 +503,7 @@ function convert_stock_reservation(mysqli $conn, int $id_transaksi): array
         WHERE id_reservasi = ?
     ");
     if (!$stmt_update_res) {
+        $stmt_check_prod->close();
         $stmt_update_prod->close();
         return [
             'success'           => false,
@@ -414,33 +519,86 @@ function convert_stock_reservation(mysqli $conn, int $id_transaksi): array
         $id_produk = (int)$r['id_produk'];
         $qty       = (int)$r['jumlah'];
 
-        $stmt_update_prod->bind_param('iii', $qty, $qty, $id_produk);
-        if (!$stmt_update_prod->execute()) {
+        $stmt_check_prod->bind_param('i', $id_produk);
+        $stmt_check_prod->execute();
+        $prod_data = $stmt_check_prod->get_result()->fetch_assoc();
+
+        if (!$prod_data) {
+            $stmt_check_prod->close();
+            $stmt_update_prod->close();
+            $stmt_update_res->close();
+            return [
+                'success'              => false,
+                'converted'            => $count,
+                'already_converted'    => false,
+                'needs_reconciliation' => true,
+                'message'              => "Produk ID {$id_produk} tidak ditemukan saat konversi. Memerlukan rekonsiliasi."
+            ];
+        }
+
+        $current_stok     = (int)$prod_data['stok'];
+        $current_reserved = (int)$prod_data['stok_direservasi'];
+
+        if ($current_stok < $qty) {
+            $stmt_check_prod->close();
+            $stmt_update_prod->close();
+            $stmt_update_res->close();
+            error_log("RECONCILIATION_NEEDED: stok ({$current_stok}) < qty ({$qty}) untuk produk ID {$id_produk} pada konversi transaksi [{$id_transaksi}].");
+            return [
+                'success'              => false,
+                'converted'            => $count,
+                'already_converted'    => false,
+                'needs_reconciliation' => true,
+                'message'              => "Stok fisik tidak mencukupi untuk konversi produk ID {$id_produk} (stok: {$current_stok}, dibutuhkan: {$qty}). Memerlukan rekonsiliasi."
+            ];
+        }
+
+        if ($current_reserved < $qty) {
+            $stmt_check_prod->close();
+            $stmt_update_prod->close();
+            $stmt_update_res->close();
+            error_log("RECONCILIATION_NEEDED: stok_direservasi ({$current_reserved}) < qty ({$qty}) untuk produk ID {$id_produk} pada konversi transaksi [{$id_transaksi}].");
+            return [
+                'success'              => false,
+                'converted'            => $count,
+                'already_converted'    => false,
+                'needs_reconciliation' => true,
+                'message'              => "Stok reservasi tidak mencukupi untuk konversi produk ID {$id_produk} (reservasi: {$current_reserved}, dibutuhkan: {$qty}). Memerlukan rekonsiliasi."
+            ];
+        }
+
+        $stmt_update_prod->bind_param('iiiii', $qty, $qty, $id_produk, $qty, $qty);
+        if (!$stmt_update_prod->execute() || $stmt_update_prod->affected_rows === 0) {
+            $stmt_check_prod->close();
             $stmt_update_prod->close();
             $stmt_update_res->close();
             return [
-                'success'           => false,
-                'converted'         => $count,
-                'already_converted' => false,
-                'message'           => "Gagal memperbarui stok fisik untuk produk ID {$id_produk}."
+                'success'              => false,
+                'converted'            => $count,
+                'already_converted'    => false,
+                'needs_reconciliation' => true,
+                'message'              => "Gagal memperbarui stok fisik untuk produk ID {$id_produk} (conditional UPDATE gagal). Memerlukan rekonsiliasi."
             ];
         }
 
         $stmt_update_res->bind_param('i', $id_res);
-        if (!$stmt_update_res->execute()) {
+        if (!$stmt_update_res->execute() || $stmt_update_res->affected_rows === 0) {
+            $stmt_check_prod->close();
             $stmt_update_prod->close();
             $stmt_update_res->close();
             return [
-                'success'           => false,
-                'converted'         => $count,
-                'already_converted' => false,
-                'message'           => "Gagal memperbarui status reservasi ID {$id_res}."
+                'success'              => false,
+                'converted'            => $count,
+                'already_converted'    => false,
+                'needs_reconciliation' => true,
+                'message'              => "Gagal memperbarui status reservasi ID {$id_res}."
             ];
         }
 
         $count++;
     }
 
+    $stmt_check_prod->close();
     $stmt_update_prod->close();
     $stmt_update_res->close();
 
@@ -469,7 +627,7 @@ function get_stock_reservations(mysqli $conn, int $id_transaksi): array
         SELECT id_reservasi, id_transaksi, id_produk, jumlah, status_reservasi, expires_at, created_at, updated_at
         FROM reservasi_stok 
         WHERE id_transaksi = ?
-        ORDER BY id_produk ASC
+        ORDER BY id_produk ASC, id_reservasi ASC
     ");
     if (!$stmt) {
         return [];
diff --git a/pelanggan/create_snap_token.php b/pelanggan/create_snap_token.php
index 1477a78..70c5f77 100644
--- a/pelanggan/create_snap_token.php
+++ b/pelanggan/create_snap_token.php
@@ -607,41 +607,100 @@ try {
     // Tandai status 'Gagal' dan lepaskan reservasi stok secara atomik.
     // F-01: Pastikan hanya memperbarui jika status pembayaran belum terminal.
     // =========================================================================
-    $stmt_fail = $conn->prepare("
-        UPDATE transaksi 
-        SET snap_status = 'Gagal' 
-        WHERE id_transaksi = ? AND status_pembayaran = 'Menunggu Pembayaran'
-    ");
-    if ($stmt_fail) {
-        $stmt_fail->bind_param("i", $id_transaksi);
-        $stmt_fail->execute();
-        $stmt_fail->close();
-    }
-
-    // Lepas reservasi stok secara atomik
+    $release_succeeded = false;
     $conn->begin_transaction();
     try {
-        $rel = release_stock_reservation($conn, $id_transaksi);
+        $stmt_lock_trx = $conn->prepare("
+            SELECT id_transaksi, status_pembayaran 
+            FROM transaksi 
+            WHERE id_transaksi = ? 
+            FOR UPDATE
+        ");
+        if (!$stmt_lock_trx) {
+            throw new RuntimeException("Gagal mempersiapkan kueri lock transaksi untuk Tahap 3C.");
+        }
+        $stmt_lock_trx->bind_param("i", $id_transaksi);
+        $stmt_lock_trx->execute();
+        $locked_trx = $stmt_lock_trx->get_result()->fetch_assoc();
+        $stmt_lock_trx->close();
+
+        if (!$locked_trx) {
+            throw new RuntimeException("Transaksi [{$id_transaksi}] tidak ditemukan untuk Tahap 3C.");
+        }
+
+        $locked_pay_status = (string)($locked_trx['status_pembayaran'] ?? '');
+        if (in_array($locked_pay_status, ['Lunas', 'Dibatalkan', 'Refund'], true)) {
+            $conn->rollback();
+
+            ob_clean();
+            http_response_code(409);
+            echo json_encode([
+                'success' => false,
+                'message' => 'Status pesanan telah berubah. Silakan periksa riwayat pesanan Anda.'
+            ]);
+            exit;
+        }
+
+        $rel = release_stock_reservation($conn, $id_transaksi, true);
         if (!$rel['success']) {
-            throw new RuntimeException($rel['message'] ?? 'Gagal melepas reservasi.');
+            throw new RuntimeException("Release reservasi gagal: " . ($rel['message'] ?? 'Tidak diketahui'));
         }
+
+        $stmt_fail = $conn->prepare("
+            UPDATE transaksi 
+            SET snap_status = 'Gagal' 
+            WHERE id_transaksi = ? AND status_pembayaran = 'Menunggu Pembayaran'
+        ");
+        if (!$stmt_fail) {
+            throw new RuntimeException("Gagal mempersiapkan kueri pembaruan snap_status.");
+        }
+        $stmt_fail->bind_param("i", $id_transaksi);
+        $stmt_fail->execute();
+        $stmt_fail->close();
+
         $conn->commit();
+        $release_succeeded = true;
     } catch (\Throwable $rel_err) {
-        $conn->rollback();
-        error_log("Gagal melepas reservasi untuk transaksi [{$id_transaksi}]: " . $rel_err->getMessage());
+        if ($conn instanceof mysqli && $conn->connect_errno === 0) {
             $conn->rollback();
         }
+        error_log("CRITICAL [SNAP_FAIL_RELEASE_FAILED]: Gagal melepas reservasi dan menandai status gagal untuk transaksi [{$id_transaksi}]: " . $rel_err->getMessage());
+
+        try {
+            if ($conn instanceof mysqli && $conn->connect_errno === 0 && @$conn->ping()) {
+                $stmt_recon = $conn->prepare("
+                    UPDATE transaksi 
+                    SET snap_status = 'Perlu Rekonsiliasi' 
+                    WHERE id_transaksi = ? AND status_pembayaran = 'Menunggu Pembayaran'
+                ");
+                if ($stmt_recon) {
+                    $stmt_recon->bind_param("i", $id_transaksi);
+                    $stmt_recon->execute();
+                    $stmt_recon->close();
+                }
+            }
+        } catch (\Throwable $ignored) {
+        }
     }
 
-    // Buat idempotency key baru pada pending_order hanya setelah kegagalan definitif dan reservasi dilepas
-    if (isset($_SESSION['pending_order'])) {
+    if ($release_succeeded && isset($_SESSION['pending_order'])) {
         $_SESSION['pending_order']['checkout_idempotency_key'] = bin2hex(random_bytes(16));
     }
 
     ob_clean();
-    http_response_code(500);
-    echo json_encode([
-        'success' => false,
-        'message' => 'Gagal menginisiasi pembayaran dengan gateway Midtrans. Silakan periksa kembali pesanan Anda atau ulangi beberapa saat lagi.'
-    ]);
+    if ($release_succeeded) {
+        http_response_code(500);
+        echo json_encode([
+            'success' => false,
+            'message' => 'Gagal menginisiasi pembayaran dengan gateway Midtrans. Silakan periksa kembali pesanan Anda atau ulangi beberapa saat lagi.'
+        ]);
+    } else {
+        http_response_code(500);
+        echo json_encode([
+            'success' => false,
+            'message' => 'Gagal menginisiasi pembayaran dan gagal melepaskan reservasi stok. Pesanan Anda memerlukan rekonsiliasi. Mohon periksa riwayat pesanan atau hubungi dukungan.'
+        ]);
+    }
     exit;
 }
 
diff --git a/pelanggan/proses_pembayaran.php b/pelanggan/proses_pembayaran.php
index 038e11d..e981eb3 100644
--- a/pelanggan/proses_pembayaran.php
+++ b/pelanggan/proses_pembayaran.php
@@ -46,7 +46,8 @@ if (isset($_POST['action']) && $_POST['action'] === 'cancel') {
                     exit;
                 }
 
-                $rel = release_stock_reservation($conn, $id_cancel);
+                $is_midtrans = (($trx['metode_pembayaran'] ?? '') === 'Midtrans');
+                $rel = release_stock_reservation($conn, $id_cancel, $is_midtrans);
                 if (!$rel['success']) {
                     throw new RuntimeException("Gagal melepaskan reservasi stok: " . ($rel['message'] ?? ''));
                 }
```

---

## 3. Penjelasan Perubahan Alur Snap Failure dan Idempotency Key

Perbaikan di `pelanggan/create_snap_token.php:605-690`:

1. **Pemisahan Kategori Error Gateway:**
   - **Timeout Ambigu (Tahap 3B):** Dideteksi melalui cURL error 28 atau pesan timeout. Status transaksi diubah menjadi `Perlu Rekonsiliasi`. Reservasi stok **TIDAK DILEPAS**, dan `checkout_idempotency_key` **TIDAK DIGANTI**. Gateway mengembalikan `HTTP 504 Gateway Timeout`.
   - **Penolakan Definitif (Tahap 3C):** Error non-timeout dari Midtrans (misalnya validasi parameter ditolak atau gateway menolak inisiasi transaksi secara final).

2. **Atomisitas Tahap 3C:**
   - Dimulai dengan `$conn->begin_transaction()`.
   - Mengunci baris transaksi `SELECT id_transaksi, status_pembayaran FROM transaksi WHERE id_transaksi = ? FOR UPDATE`.
   - Memeriksa status terminal (`Lunas`, `Dibatalkan`, `Refund`). Jika status sudah terminal, batalkan transaksi lokal via `rollback()` dan kembalikan `HTTP 409 Conflict`.
   - Menjalankan `release_stock_reservation($conn, $id_transaksi, true)`.
   - Jika release gagal: exception dilempar (`RuntimeException`), transaksi di-rollback secara utuh, pesan kegagalan dicatat pada `error_log`, status transaksi ditandai `Perlu Rekonsiliasi` pada kueri terpisah, dan `checkout_idempotency_key` pada session **TETAP DIPERTAHANKAN** (tidak diganti). Respons mengembalikan `HTTP 500`.
   - Jika release sukses: jalankan `UPDATE transaksi SET snap_status = 'Gagal' WHERE id_transaksi = ? AND status_pembayaran = 'Menunggu Pembayaran'`, lalu panggil `$conn->commit()`.
   - HANYA setelah `$conn->commit()` berhasil, `$release_succeeded` bernilai `true` dan `$_SESSION['pending_order']['checkout_idempotency_key']` diganti dengan token acak baru (`bin2hex(random_bytes(16))`).

3. **Jaminan Anti-Orphaned Reservation:**
   Dengan alur ini, tidak mungkin terjadi kondisi di mana transaksi ditandai `Gagal`, reservasi stok masih `Aktif`, dan idempotency key sudah berubah. Jika user mencoba mengulang request dengan session lama yang gagal dilepas, endpoint akan mendeteksi status `Perlu Rekonsiliasi` via `handle_existing_transaction_response()` dan menolak pembuatan checkout baru dengan `HTTP 409`.

---

## 4. Penjelasan Validasi Status Reservasi

Perbaikan di `includes/stock_reservation.php:141-245` pada fungsi `release_stock_reservation()`:

1. **Inspeksi Awal Menyeluruh (*Pre-flight Evaluation*):**
   Seluruh baris reservasi suatu transaksi dibaca sekaligus dengan `SELECT id_reservasi, id_produk, jumlah, status_reservasi FROM reservasi_stok WHERE id_transaksi = ? ORDER BY id_produk ASC, id_reservasi ASC FOR UPDATE`.

2. **Matriks Keputusan Status Reservasi:**
   - **Kasus Reservasi Kosong (`total_count === 0`):**
     - Jika `$is_midtrans === false` (transaksi manual): Kembalikan sukses idempotent `['success' => true, 'released' => 0, 'already_released' => false]`. Menjaga kompatibilitas pembatalan pembayaran transfer manual.
     - Jika `$is_midtrans === true` (transaksi Midtrans): Kembalikan gagal `['success' => false, 'needs_reconciliation' => true]`. Menolak anggapan bahwa hilangnya reservasi adalah release sukses.
   - **Kasus Semua `Dilepas` (`released_count === total_count`):**
     Kembalikan sukses idempotent tanpa memodifikasi stok: `['success' => true, 'released' => 0, 'already_released' => true]`.
   - **Kasus Semua `Dikonversi` (`converted_count === total_count`):**
     Tolak pelepasan: `['success' => false, 'already_converted' => true]`. Reservasi yang sudah menjadi penjualan tidak boleh dilepaskan kembali ke stok bebas.
   - **Kasus Status Campuran / Anomali (`active_count !== total_count`):**
     Jika ditemukan kombinasi status (misal: 1 `Aktif` + 1 `Dikonversi`, atau 1 `Dilepas` + 1 `Dikonversi`), operasi langsung digagalkan sebelum ada satu pun kueri `UPDATE` yang dijalankan: `['success' => false, 'needs_reconciliation' => true]`.
   - **Kasus Semua `Aktif` (`active_count === total_count`):**
     Operasi pelepasan diizinkan berjalan untuk seluruh item.

3. **Perlindungan Terhadap Caller:**
   Fungsi mengembalikan array status lengkap dan mewajibkan transaksi database dikelola oleh caller. Setiap kegagalan pengembalian `success = false` memicu caller untuk menjalankan `rollback()`.

---

## 5. Penjelasan Validasi Stok Fisik dan Stok Reservasi

Perbaikan di `includes/stock_reservation.php:245-365` (`release_stock_reservation`) dan `includes/stock_reservation.php:465-595` (`convert_stock_reservation`):

1. **Penghapusan Klausa `GREATEST(0, ...)`:**
   Semua ekspresi `GREATEST(0, stok - ?)` dan `GREATEST(0, stok_direservasi - ?)` telah dihapus dari seluruh kueri SQL di dalam codebase Beautyland.

2. **Row Locking Berurutan (`ORDER BY id_produk ASC`):**
   Sebelum melakukan mutasi baris produk, baris produk dikunci terlebih dahulu secara individual dalam urutan ID produk menaik:
   - Pada release: `SELECT id_produk, stok_direservasi FROM produk WHERE id_produk = ? FOR UPDATE`.
   - Pada konversi: `SELECT id_produk, stok, stok_direservasi FROM produk WHERE id_produk = ? FOR UPDATE`.

3. **Validasi Ambang Batas Eksplisit:**
   - **Stok Fisik:** Dicek `if ($current_stok < $qty)`. Jika stok fisik di tabel produk kurang dari kuantitas yang dipesan, operasi konversi digagalkan, log peringatan `RECONCILIATION_NEEDED` dicatat, dan helper mengembalikan `['success' => false, 'needs_reconciliation' => true]`.
   - **Stok Reservasi:** Dicek `if ($current_reserved < $qty)`. Jika agregat `stok_direservasi` di tabel produk kurang dari kuantitas yang akan dilepas atau dikonversi, operasi digagalkan, log peringatan dicatat, dan helper mengembalikan `['success' => false, 'needs_reconciliation' => true]`.

4. **Kueri UPDATE Bersyarat dan Pengecekan Baris Terpengaruh:**
   - Kueri UPDATE kini menggunakan klausa pengaman tambahan:
     - Release: `UPDATE produk SET stok_direservasi = stok_direservasi - ? WHERE id_produk = ? AND stok_direservasi >= ?`
     - Konversi: `UPDATE produk SET stok = stok - ?, stok_direservasi = stok_direservasi - ? WHERE id_produk = ? AND stok >= ? AND stok_direservasi >= ?`
   - Kueri mengevaluasi `$stmt->affected_rows`. Jika bernilai 0 (karena kondisi `stok >= ?` tidak terpenuhi pada level database), fungsi langsung keluar dan mengembalikan `success = false`.
   - Pembaruan status di `reservasi_stok` juga memvalidasi `$stmt_update_res->affected_rows === 0` untuk mendeteksi perubahan konkuren tak terduga.

---

## 6. Hasil Setiap Pengujian

Pengujian dijalankan melalui test suite mandiri `tests/test_midtrans_stock_reservation.php`:

| No | Skenario Uji Wajib | Status | Keterangan Hasil Aktual |
|---|---|---|---|
| 1 | Snap failure dan release berhasil: transaksi dilepas dan idempotency key baru hanya dibuat setelah commit | **PASS** | `snap_status=Gagal`, `status_reservasi=Dilepas`, `stok_direservasi=0`, idempotency key baru terbit (32 karakter hex). |
| 2 | Snap failure dan release gagal: rollback, idempotency key tetap sama, reservasi tidak berubah | **PASS** | Exception ditangkap, rollback terpanggil, `snap_status=Perlu Rekonsiliasi`, reservasi tetap `Aktif`, idempotency key sama persis. |
| 3 | Timeout ambigu: idempotency key tetap sama dan reservasi tidak dilepas | **PASS** | HTTP 504 dikembalikan, `snap_status=Perlu Rekonsiliasi`, reservasi tetap `Aktif`, idempotency key tidak berubah. |
| 4 | Semua reservasi `Aktif`: release berhasil | **PASS** | 2 produk dengan total kuantitas dilepaskan, agregat `stok_direservasi` kembali ke 0, status baris menjadi `Dilepas`. |
| 5 | Semua reservasi `Dilepas`: idempotent success | **PASS** | Mengembalikan `already_released = true`, `released = 0`, stok produk tidak dimutasi ulang. |
| 6 | Semua reservasi `Dikonversi`: release ditolak | **PASS** | Mengembalikan `success = false`, `already_converted = true`, tidak ada baris yang diubah. |
| 7 | Reservasi dengan status campuran: operasi ditolak tanpa perubahan parsial | **PASS** | Ditemukan 1 `Aktif` dan 1 `Dikonversi`, fungsi menolak eksekusi (`needs_reconciliation = true`), baris tidak diubah parsial. |
| 8 | Reservasi tidak ditemukan pada transaksi manual: kompatibilitas tetap terjaga | **PASS** | Parameter `$is_midtrans = false`, mengembalikan `success = true`, `released = 0`. Pembatalan manual berjalan lancar. |
| 9 | Reservasi tidak ditemukan pada transaksi Midtrans: tidak dianggap sebagai release berhasil | **PASS** | Parameter `$is_midtrans = true`, mengembalikan `success = false`, `needs_reconciliation = true`. Anomali tidak disamarkan. |
| 10 | Stok fisik tidak mencukupi: operasi gagal tanpa mengubah data | **PASS** | Stok fisik 1 < kuantitas 2, konversi ditolak, `stok` tetap 1 (tidak di-clamp ke 0), status reservasi tetap `Aktif`. |
| 11 | Stok reservasi tidak mencukupi: operasi gagal tanpa mengubah data | **PASS** | Stok reservasi 1 < kuantitas 2, release ditolak, `stok_direservasi` tetap 1 (tidak negatif/nol), status tetap `Aktif`. |
| 12 | Kegagalan UPDATE: seluruh transaksi rollback | **PASS** | Simulasi database failure pada UPDATE, caller melakukan rollback, seluruh data produk dan reservasi kembali ke kondisi awal. |

**Ringkasan Hasil:**
- Total Skenario: **12**
- Lulus (PASS): **12**
- Gagal (FAIL): **0**
- Tidak Dijalankan (NOT RUN): **0**

---

## 7. Metode dan Lingkungan Pengujian

1. **Metode Pengujian:**
   - Pengujian dilakukan secara terisolasi menggunakan arsitektur Mock Database Engine (`MockDbConn`, `MockDbStmt`, `MockDbResult`) yang mengimplementasikan antarmuka `mysqli`.
   - Driver mock mendukung *deep-copy state snapshotting* untuk menyimulasikan transaksi MySQL (`begin_transaction`, `commit`, `rollback`) secara presisi di level memori.
   - Panggilan HTTP ke gateway Midtrans disimulasikan menggunakan stub respons terkendali (simulasi network timeout dan simulasi penolakan API Midtrans).

2. **Lingkungan Pengujian:**
   - Sistem Operasi: Linux (Debian container)
   - PHP: CLI v8.4.27 (Zend Engine v4.4.27, NTS)
   - Mode Pemeriksaan Sintaks: `php -l` pada seluruh file terdampak.

---

## 8. Keterangan Perubahan Database Aktif Proyek

- **Status Database Aktif Proyek:** **TIDAK MENGALAMI PERUBAHAN APAPUN**.
- Tidak ada query DDL/migrasi skema yang dijalankan.
- Tidak ada data pada tabel MySQL lokal yang dimodifikasi, ditambah, maupun dihapus selama proses perbaikan dan pengujian berlangsung.
- Seluruh pengujian data mutasi dijalankan 100% di dalam memori proses PHP yang terisolasi.

---

## 9. Risiko atau Temuan yang Belum Terselesaikan

1. **Pembersihan Reservasi Kedaluwarsa Otomatis (Cron/Scheduler):**
   Saat ini, reservasi yang kedaluwarsa dilepaskan secara reaktif ketika webhook menerima notifikasi `expire` atau saat pelanggan membatalkan pesanan. Jika webhook tidak terkirim dan pengguna menutup browser, baris reservasi akan tetap berstatus `Aktif` hingga transaksi tersebut diakses kembali. Diperlukan background worker atau scheduled task di Fase berikutnya untuk secara berkala memanggil `release_stock_reservation()` pada transaksi yang melewati `expires_at`.
2. **Dashboard Rekonsiliasi Admin:**
   Status `Perlu Rekonsiliasi` kini telah tercatat secara konsisten di kolom `transaksi.snap_status`. Namun, antarmuka panel admin saat ini belum menyediakan filter khusus atau aksi rekonsiliasi satu-klik untuk menyelesaikan pesanan yang memerlukan rekonsiliasi stok manual.

---

## 10. Kesimpulan

Seluruh temuan audit prioritas P1 dan P2 pada branch `PPL-12` telah diselesaikan secara tuntas:
- Alur Snap failure dan pelepasan reservasi telah menjadi satu transaksi atomik.
- Pembuatan `checkout_idempotency_key` baru terlindungi ketat dan hanya terjadi setelah reservasi berhasil dilepas.
- Validasi status reservasi telah diperketat tanpa merusak kompatibilitas transaksi pembayaran manual.
- Klausa `GREATEST(0, ...)` telah sepenuhnya dihilangkan dan digantikan dengan validasi stok eksplisit sebelum dan saat UPDATE.
- 12 dari 12 skenario pengujian wajib lulus dengan hasil **PASS**.

**Implementasi dinyatakan SIAP UNTUK DIAUDIT ULANG.**
