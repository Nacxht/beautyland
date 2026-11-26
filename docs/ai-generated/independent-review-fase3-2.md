# Independent Code Review: Beautyland Fase 3.2 Midtrans Webhook & Concurrency Hardening

**Proyek:** Beautyland (PHP Native 8.1, MariaDB 12.2, Midtrans Snap SDK)  
**Tipe Dokumen:** Laporan Audit Independen & Verifikasi Keamanan Kode  
**Tanggal Audit:** 26 November 2026  
**Auditor:** Antigravity Independent Reviewer  
**Status Evaluasi Akhir:** **REJECTED**

---

## 1. Executive Summary

Audit independen ini dilakukan secara menyeluruh terhadap implementasi Fase 3.2 (Midtrans Notification Webhook) beserta keterkaitannya dengan Fase 3.1.1 (Stock Reservation & Idempotency) dan modul pendukung (Pengiriman, Pesanan, Laporan, Dashboard). Audit bersifat **READ-ONLY**, meninjau source code aktual baris demi baris, memverifikasi struktur basis data MariaDB aktif, menganalisis state machine konseptual, serta menolak asumsi bahwa laporan implementasi sebelumnya otomatis mencerminkan kode yang bebas bug.

### Temuan Kunci Audit:
1. **Pencegahan Overselling & Idempotensi Kriptografis Berjalan Baik:**
   Implementasi signature SHA-512 dengan `hash_equals()`, validasi nominal deterministik murni integer tanpa floating point comparison, isolasi locking transaksi via `SELECT ... FOR UPDATE`, serta urutan locking produk (`ORDER BY id_produk ASC`) pada `midtrans_notification.php` terbukti solid dan berhasil mencegah race condition antar-webhook duplikat.
2. **Celah Keamanan Kritis pada Modul Pengiriman (`admin/pengiriman.php`):**
   - **Ketiadaan Auth Guard:** File `admin/pengiriman.php` sama sekali tidak memiliki pemeriksaan sesi admin (`$_SESSION['user']` / `$_SESSION['role'] === 'admin'`). Siapa pun dapat mengakses data alamat pelanggan dan mengubah status pengiriman secara publik.
   - **Pengiriman Pesanan Belum Lunas (Unpaid Order Fulfillment):** Validasi backend pada `admin/pengiriman.php` hanya memblokir pesanan berstatus `Dibatalkan` dan `Refund`. Pesanan yang masih berstatus `Menunggu Pembayaran` atau `Menunggu Verifikasi` **dapat diproses dan dikirimkan oleh admin/staf gudang sebelum pembeli membayar**.
3. **Flaw Logika Konversi Reservasi Stok (Phantom Stock Conversion):**
   Pada `includes/stock_reservation.php:248-254`, helper `convert_stock_reservation()` mengembalikan `['success' => true, 'converted' => 0]` apabila tidak menemukan reservasi aktif. Pada `midtrans_notification.php:122-136`, jika pesanan menerima settlement saat `stok_dikurangi = 0`, kondisi ini dianggap berhasil, transaksi ditandai `Lunas`, dan `stok_dikurangi` diubah menjadi `1` **tanpa ada pengurangan stok fisik sama sekali di tabel `produk`**. Ini terjadi jika reservasi sebelumnya telah dilepas atau anomali data, memicu phantom inventory.
4. **Penyembunyian Error Pelepasan Stok (Swallowed Exception):**
   Nilai kembalian `release_stock_reservation()` diabaikan di baris 175 dan 203 pada `midtrans_notification.php`. Jika kueri pelepasan stok gagal, transaksi tetap di-commit sebagai `Dibatalkan` dan mengembalikan `HTTP 200 OK` ke Midtrans, menyebabkan stok terkunci permanen (*dead stock*) tanpa memicu retry.
5. **Polusi Semantik Kolom `midtrans_status`:**
   Pada skenario `capture` dengan `fraud_status = challenge`, webhook menuliskan `'challenge'` ke kolom `midtrans_status`. Nilai `'challenge'` adalah `fraud_status`, bukan `transaction_status` resmi Midtrans.
6. **Pembatalan Lokal Mengabaikan Reservasi:**
   Pada `pelanggan/proses_pembayaran.php:11`, pembatalan lokal (`action=cancel`) mengubah `status_pembayaran='Dibatalkan'` tanpa memanggil `release_stock_reservation()`, mengakibatkan stok reservasi menggantung.

**Keputusan Akhir:** **REJECTED**. Kode belum siap untuk rilis produksi dan wajib diperbaiki sesuai rekomendasi sebelum Fase 4 (Frontend Snap JS) dimulai.

---

## 2. Daftar File dan Fungsi yang Diperiksa

| No | File Path | Fungsi / Cakupan Logika yang Diperiksa |
|---|---|---|
| 1 | `midtrans_notification.php` | Validasi HTTP POST, parsing JSON stream, verifikasi signature SHA-512, normalisasi gross_amount integer, locking transaksi `SELECT ... FOR UPDATE`, evaluasi state machine Midtrans (`settlement`, `capture`, `pending`, `expire`, `cancel`, `deny`, `refund`), respons kode status HTTP. |
| 2 | `includes/stock_reservation.php` | `reserve_stock()`, `release_stock_reservation()`, `convert_stock_reservation()`, `get_stock_reservations()`, locking produk `id_produk ASC`, mitigasi stok negatif via `GREATEST()`. |
| 3 | `includes/midtrans.php` | Konfigurasi SDK `\Midtrans\Config`, pembacaan environment Server Key, Client Key, mode Sandbox/Production. |
| 4 | `pelanggan/create_snap_token.php` | `handle_existing_transaction_response()`, validasi profil pelanggan tanpa fallback dummy, persistensi lokal Tahap 1, isolasi panggilan API Midtrans Tahap 2, penyimpanan token dan penanganan late response Tahap 3A (F-01), W-01, W-02. |
| 5 | `pelanggan/proses_checkout.php` | Parsing item cart, validasi stok awal, pembentukan session `pending_order`, pembangkitan idempotency key checkout. |
| 6 | `admin/pengiriman.php` | Handler POST `$_POST['simpan']`, pengecekan status transaksi vs pengiriman, tampilan form per nomor resi, kontrol akses. |
| 7 | `admin/pesanan.php` | Proteksi admin, verifikasi pembayaran manual (`metode_pembayaran != 'Midtrans'`), pencarian transaksi, pemisahan badge pembayaran termasuk `Refund`. |
| 8 | `pelanggan/pesanan_user.php` | Proteksi sesi pelanggan, pembacaan riwayat pesanan, styling badge status (`Lunas`, `Batal`, `Refund`, `Menunggu`). |
| 9 | `admin/laporan.php` | Proteksi admin, agregasi `total_pendapatan` (`status_pembayaran='Lunas'`), kueri `produk_terlaris` dengan filter Lunas, pelaporan transaksi, isolasi data refund. |
| 10 | `admin/dashboard_admin.php` | Proteksi admin, agregasi `total_penjualan` (`status_pembayaran='Lunas'`), kartu antrean pesanan baru dan stok menipis. |
| 11 | `pelanggan/proses_pembayaran.php` | Alur pembayaran manual, upload bukti transfer, direct deduction stok manual, aksi pembatalan lokal (`action=cancel`). |

---

## 3. Temuan Berdasarkan Tingkat Keparahan

### 3.1. Temuan Tingkat Critical

#### [CRIT-01] Ketiadaan Authentication Guard pada Modul Pengiriman Admin
- **File & Baris:** `admin/pengiriman.php:1-8`
- **Potongan Kode:**
  ```php
  <?php
  session_start();
  require_once __DIR__ . '/../includes/conn.php';

  // ======================================
  // UPDATE PENGIRIMAN
  // ======================================
  if(isset($_POST['simpan'])){
      $id_pengiriman = (int)($_POST['id_pengiriman'] ?? 0);
  ```
- **Skenario:**
  Pengguna publik yang tidak memiliki sesi login, atau pelanggan biasa dengan role `pelanggan`, dapat mengakses URL `http://<domain>/admin/pengiriman.php` secara langsung atau mengirimkan HTTP POST request dengan payload `simpan=1`. File `includes/sidebar_admin.php` yang di-include di baris 95 juga tidak memiliki guard autentikasi.
- **Dampak:**
  Pelanggaran fatal kontrol akses (Broken Access Control - OWASP A01:2021). Siapa pun dapat:
  1. Melihat seluruh data sensitif pelanggan (nama lengkap pelanggan, alamat pengiriman fisik lengkap, total belanja, kode pesanan).
  2. Mengubah jasa kirim, nomor resi, dan status pengiriman pesanan apa pun di database.
- **Rekomendasi Perbaikan:**
  Tambahkan guard sesi admin di baris 4 sebelum logika apa pun dijalankan:
  ```php
  if (!isset($_SESSION['user']) || $_SESSION['role'] !== 'admin') {
      header("Location: ../login.php");
      exit;
  }
  ```

---

#### [CRIT-02] Modul Pengiriman Mengizinkan Pemrosesan dan Pengiriman Pesanan Belum Lunas
- **File & Baris:** `admin/pengiriman.php:27-31` dan `admin/pengiriman.php:106-108`
- **Potongan Kode:**
  ```php
  // admin/pengiriman.php:27-31 (Backend Handler)
  $pay_status = strtolower((string)($data_cek['status_pembayaran'] ?? ''));
  if ($pay_status === 'dibatalkan' || $pay_status === 'refund') {
      echo "<script>alert('Pengiriman tidak dapat diperbarui karena pesanan telah dibatalkan atau di-refund.'); window.location='pengiriman.php';</script>";
      exit;
  }

  // admin/pengiriman.php:106-108 (Frontend Guard)
  $is_selesai   = ($row['status_pengiriman'] == 'Selesai');
  $pay_status   = strtolower((string)($row['status_pembayaran'] ?? ''));
  $is_blocked   = ($pay_status === 'dibatalkan' || $pay_status === 'refund');
  $is_disabled  = ($is_selesai || $is_blocked);
  ```
- **Skenario:**
  Saat pelanggan melakukan checkout, baris data pengiriman otomatis dibuat di tabel `pengiriman` dengan `status_pengiriman = 'Belum Diproses'`. Jika pelanggan belum membayar (`status_pembayaran = 'Menunggu Pembayaran'` untuk Midtrans, atau `'Menunggu Verifikasi'` untuk transfer manual), nilai `$pay_status` bukan `'dibatalkan'` dan bukan `'refund'`. Akibatnya, form pengiriman di antarmuka admin tetap aktif dan tombol "Simpan Pengiriman" dapat diklik. Jika staf gudang memilih status "Dikirim" dan menyimpan resi, backend meloloskan perubahan tersebut.
- **Dampak:**
  Barang fisik dapat dikirimkan kepada pelanggan yang belum melakukan pembayaran sama sekali. Jika token Midtrans kedaluwarsa (`expire`) atau pembeli kabur, toko mengalami kerugian finansial langsung karena barang fisik sudah berpindah tangan.
- **Rekomendasi Perbaikan:**
  Ubah validasi backend dan frontend agar pengiriman **HANYA** dapat diproses jika pesanan telah lunas (`status_pembayaran === 'Lunas'`):
  ```php
  if ($pay_status !== 'lunas') {
      echo "<script>alert('Pengiriman hanya dapat diproses untuk pesanan yang telah lunas dibayar.'); window.location='pengiriman.php';</script>";
      exit;
  }
  ```
  Dan pada frontend:
  ```php
  $is_blocked = ($pay_status !== 'lunas');
  ```

---

#### [CRIT-03] Phantom Stock Conversion: `converted = 0` Dianggap Sukses Saat `stok_dikurangi = 0`
- **File & Baris:** `midtrans_notification.php:122-136` dan `includes/stock_reservation.php:248-254`
- **Potongan Kode:**
  `includes/stock_reservation.php:248-254`:
  ```php
  // Idempotent: jika sudah pernah dikonversi, no-op aman
  if (empty($active_reservations)) {
      return [
          'success'   => true,
          'converted' => 0,
          'message'   => 'Tidak ada reservasi aktif untuk dikonversi (mungkin sudah dikonversi sebelumnya).'
      ];
  }
  ```
  `midtrans_notification.php:122-136`:
  ```php
  if ($stok_dikurangi === 0) {
      $conv = convert_stock_reservation($conn, $trx_id);
      if (!$conv['success']) {
          throw new RuntimeException("Gagal mengonversi reservasi stok: " . ($conv['message'] ?? ''));
      }
  }
  $stmt_up = $conn->prepare("
      UPDATE transaksi 
      SET status_pembayaran = 'Lunas', 
          midtrans_status = 'settlement', 
          ...
          stok_dikurangi = 1 
      WHERE id_transaksi = ?
  ");
  ```
- **Skenario:**
  Pertimbangkan skenario di mana transaksi lokal memiliki `stok_dikurangi = 0` (menandakan stok fisik pesanan ini belum pernah dipotong menjadi penjualan). Namun, seluruh reservasi pada tabel `reservasi_stok` untuk transaksi tersebut telah berstatus `'Dilepas'` (misalnya akibat pembatalan manual lokal via `proses_pembayaran.php`, atau kegagalan worker) atau baris reservasi hilang/tidak terbentuk.
  Ketika notifikasi `settlement` tiba dari Midtrans:
  1. Helper `convert_stock_reservation()` mencari `WHERE id_transaksi = ? AND status_reservasi = 'Aktif'`.
  2. Karena tidak ada baris `'Aktif'`, fungsi berasumsi pesanan *"mungkin sudah dikonversi sebelumnya"* dan mengembalikan `['success' => true, 'converted' => 0]`.
  3. Webhook memeriksa `if (!$conv['success'])`, yang bernilai `false` (karena `success` bernilai `true`).
  4. Webhook kemudian mengeksekusi `UPDATE transaksi SET status_pembayaran = 'Lunas', stok_dikurangi = 1`.
- **Dampak:**
  Transaksi resmi ditandai `Lunas` dan `stok_dikurangi = 1`, padahal kuantitas stok fisik pada tabel `produk` (`stok`) **SAMA SEKALI TIDAK PERNAH DIKURANGI**. Stok yang sebelumnya direservasi telah kembali ke pasar dan mungkin sudah dibeli oleh pelanggan lain. Toko mengalami *phantom inventory* dan pesanan fiktif tanpa ketersediaan barang fisik di gudang.
- **Rekomendasi Perbaikan:**
  Jika `stok_dikurangi === 0`, ketiadaan reservasi aktif bukan merupakan kondisi normal. Helper `convert_stock_reservation()` harus membedakan:
  1. Apakah baris reservasi memang sudah `'Dikonversi'`? (Jika ya, kembalikan `success => true, converted => 0`).
  2. Apakah baris reservasi berstatus `'Dilepas'` atau kosong? (Jika ya, kembalikan `success => false, message => 'Reservasi stok telah dilepas atau tidak ditemukan.'`).
  Pada webhook, jika `stok_dikurangi === 0` dan `converted === 0`, lemparkan exception agar webhook me-rollback transaksi, menandai transaksi untuk rekonsiliasi admin, dan mengembalikan `HTTP 500` / log kritis.

---

### 3.2. Temuan Tingkat High

#### [HIGH-01] Nilai Kembalian `release_stock_reservation()` Diabaikan Sepenuhnya pada Webhook
- **File & Baris:** `midtrans_notification.php:175, 203`
- **Potongan Kode:**
  ```php
  // midtrans_notification.php:202-206
  } elseif (in_array($transaction_status, ['expire', 'cancel', 'deny'], true)) {
      release_stock_reservation($conn, $trx_id);
      $stmt_up = $conn->prepare("
          UPDATE transaksi 
          SET status_pembayaran = 'Dibatalkan', 
  ```
- **Skenario:**
  Jika di dalam `release_stock_reservation()` kueri MySQL mengalami kegagalan (misalnya database lock wait timeout atau kegagalan statement prepare pada `stmt_get`), fungsi mengembalikan `['success' => false, 'released' => 0, 'message' => ...]`.
  Namun, pada `midtrans_notification.php`, baris 175 dan 203 memanggil fungsi tersebut tanpa menampung return value dan tanpa memeriksa `success`.
- **Dampak:**
  Kegagalan pelepasan reservasi tertelan (*swallowed failure*). Script melanjutkan eksekusi ke pembaruan status transaksi menjadi `'Dibatalkan'` dan mengeksekusi `$conn->commit()`. Webhook merespons `HTTP 200 OK` ke Midtrans. Akibatnya, Midtrans menganggap notifikasi sukses dan tidak akan mengulangi (retry) webhook. Sementara di database Beautyland, stok produk tetap terkunci pada `produk.stok_direservasi` dan `reservasi_stok.status_reservasi = 'Aktif'`, menyebabkan inventaris terkunci permanen (*dead inventory lock*).
- **Rekomendasi Perbaikan:**
  Periksa hasil eksekusi dan lemparkan exception jika gagal:
  ```php
  $rel = release_stock_reservation($conn, $trx_id);
  if (!$rel['success']) {
      throw new RuntimeException("Gagal melepaskan reservasi stok: " . ($rel['message'] ?? ''));
  }
  ```

---

#### [HIGH-02] Polusi Semantik `midtrans_status`: Menyimpan `'challenge'` dan `'deny'` untuk `transaction_status = 'capture'`
- **File & Baris:** `midtrans_notification.php:165, 179, 192`
- **Potongan Kode:**
  ```php
  } elseif ($transaction_status === 'capture') {
      if ($fraud_status === 'accept') {
          ...
      } elseif ($fraud_status === 'challenge') {
          $stmt_up = $conn->prepare("
              UPDATE transaksi 
              SET midtrans_status = 'challenge', 
                  midtrans_transaction_id = ?, 
                  midtrans_payment_type = ?, 
                  notification_payload = ? 
              WHERE id_transaksi = ?
          ");
  ```
- **Skenario:**
  Saat kartu kredit diproses dan ditahan oleh Fraud Detection System (FDS), Midtrans mengirimkan payload dengan `transaction_status = 'capture'` dan `fraud_status = 'challenge'`. Webhook menyimpan nilai string `'challenge'` ke dalam kolom `transaksi.midtrans_status`.
- **Dampak:**
  Kolom `midtrans_status` mengalami polusi semantik karena mencampurkan nilai `fraud_status` ke dalam kolom status transaksi gateway. Di dokumentasi resmi Midtrans API, status transaksi untuk kejadian tersebut adalah `'capture'`. Menyimpan `'challenge'` menyebabkan inkonsistensi sistem pelaporan dan rekonsiliasi yang mengharapkan `midtrans_status` berisi status transaksi Midtrans yang standar (`capture`, `settlement`, `pending`, `deny`, `cancel`, `expire`, `refund`).
- **Rekomendasi Perbaikan:**
  Tetap simpan `midtrans_status = 'capture'` sesuai nilai asli dari `transaction_status`. Nilai fraud status sudah tersimpan secara utuh di dalam `notification_payload` (JSON) dan dapat diakses melalui query JSON `notification_payload->>'$.fraud_status'`.

---

#### [HIGH-03] Pembatalan Manual Lokal Mengabaikan Pelepasan Reservasi Stok
- **File & Baris:** `pelanggan/proses_pembayaran.php:9-14`
- **Potongan Kode:**
  ```php
  if (isset($_POST['action']) && $_POST['action'] === 'cancel') {
      if (isset($_SESSION['pending_order'])) {
          unset($_SESSION['pending_order']);
      }
      if (!empty($_POST['id_transaksi'])) {
          $id_cancel = (int)$_POST['id_transaksi'];
          mysqli_query($conn, "UPDATE transaksi SET status_pembayaran='Dibatalkan' WHERE id_transaksi='$id_cancel'");
      }
      header('Location: produk_user.php');
      exit;
  }
  ```
- **Skenario:**
  Jika pelanggan membuka halaman pembayaran dan menekan tombol "Batal" (yang memicu `action=cancel` ke `proses_pembayaran.php`), kode hanya meng-update kolom `status_pembayaran = 'Dibatalkan'`. Kode **tidak memanggil** `release_stock_reservation()`.
- **Dampak:**
  Baris di `reservasi_stok` tetap berstatus `'Aktif'`, dan kolom `produk.stok_direservasi` tidak berkurang. Barang tersebut menjadi tidak dapat dibeli oleh orang lain sampai batas waktu reservasi (24 jam) berlalu, itu pun hanya jika ada cron job pembersih (yang saat ini belum ada dalam codebase).
- **Rekomendasi Perbaikan:**
  Sertakan helper `release_stock_reservation()` dan jalankan di dalam blok transaksi atomik saat pembatalan dilakukan:
  ```php
  require_once __DIR__ . '/../includes/stock_reservation.php';
  $conn->begin_transaction();
  try {
      release_stock_reservation($conn, $id_cancel);
      $stmt = $conn->prepare("UPDATE transaksi SET status_pembayaran = 'Dibatalkan' WHERE id_transaksi = ?");
      $stmt->bind_param("i", $id_cancel);
      $stmt->execute();
      $stmt->close();
      $conn->commit();
  } catch (\Throwable $e) {
      $conn->rollback();
  }
  ```

---

### 3.3. Temuan Tingkat Medium

#### [MED-01] Notifikasi `refund` pada Pesanan `Menunggu Pembayaran` Menggantungkan Reservasi
- **File & Baris:** `midtrans_notification.php:228-240`
- **Potongan Kode:**
  ```php
  } elseif (in_array($transaction_status, ['refund', 'partial_refund'], true)) {
      error_log("WARNING: Refund notification received for unpaid order [{$order_id}]");
      $stmt_up = $conn->prepare("
          UPDATE transaksi 
          SET midtrans_status = ?, 
              midtrans_transaction_id = ?, 
              midtrans_payment_type = ?, 
              notification_payload = ? 
          WHERE id_transaksi = ?
      ");
  ```
- **Skenario:**
  Pesanan berstatus lokal `Menunggu Pembayaran` (memiliki reservasi aktif) menerima notifikasi `refund` dari Midtrans (misalnya karena refund langsung dipicu di portal Midtrans MAP saat webhook settlement sebelumnya sempat gagal terkirim).
- **Dampak:**
  `midtrans_status` diperbarui menjadi `refund`, namun `status_pembayaran` dibiarkan tetap `Menunggu Pembayaran`, dan `release_stock_reservation()` tidak dipanggil. Reservasi stok tetap aktif dan mengunci stok produk secara tidak semestinya.
- **Rekomendasi Perbaikan:**
  Pada cabang ini, panggil `release_stock_reservation($conn, $trx_id)` dan perbarui `status_pembayaran = 'Dibatalkan'` atau tandai untuk audit administratif.

---

#### [MED-02] Pemblokiran Status Pengiriman Menghalangi Penutupan Pesanan Retur Saat Terjadi Refund
- **File & Baris:** `admin/pengiriman.php:27-31`
- **Potongan Kode:**
  ```php
  $pay_status = strtolower((string)($data_cek['status_pembayaran'] ?? ''));
  if ($pay_status === 'dibatalkan' || $pay_status === 'refund') {
      echo "<script>alert('Pengiriman tidak dapat diperbarui karena pesanan telah dibatalkan atau di-refund.'); window.location='pengiriman.php';</script>";
      exit;
  }
  ```
- **Skenario:**
  Suatu pesanan telah lunas dan telah dikirim (`status_pengiriman = 'Dikirim'`). Paket fisik sedang dalam perjalanan via kurir. Kemudian pelanggan mengajukan pengembalian dana melalui payment gateway, dan webhook mengubah `status_pembayaran = 'Refund'`. Ketika paket kembali ke penjual (retur) atau kurir mengonfirmasi penyelesaian, admin berusaha mengupdate status pengiriman menjadi `Selesai` atau mencatat status penerimaan retur.
- **Dampak:**
  Admin diblokir total oleh validasi backend. Catatan pengiriman pesanan tersebut menggantung selamanya pada status `Dikirim` tanpa dapat diselesaikan secara logistik.
- **Rekomendasi Perbaikan:**
  Berikan pengecualian jika pesanan sudah berstatus `Dikirim`: admin tetap diizinkan memperbarui status menjadi `Selesai` atau status logistik baru seperti `Retur Diterima`.

---

#### [MED-03] Perhitungan `stok_awal` pada Laporan Produk Terdistorsi oleh Transaksi Refund
- **File & Baris:** `admin/laporan.php:74-95`
- **Potongan Kode:**
  ```php
  $produk_terlaris = mysqli_query($conn, "
      SELECT 
          pr.nama_produk,
          COALESCE(SUM(CASE WHEN t.status_pembayaran = 'Lunas' THEN dt.jumlah ELSE 0 END), 0) as total_terjual,
          pr.stok as sisa_stok,
          (pr.stok + COALESCE(SUM(CASE WHEN t.status_pembayaran = 'Lunas' THEN dt.jumlah ELSE 0 END), 0)) as stok_awal
      FROM produk pr
  ```
- **Skenario:**
  Produk skincare terjual 2 unit dari stok 10 (`pr.stok` menjadi 8). Kemudian pesanan di-refund. Karena produk skincare tidak di-restock otomatis demi higienitas fisik, `pr.stok` tetap bernilai 8. Namun karena status transaksi berubah menjadi `Refund`, klausul `CASE WHEN t.status_pembayaran = 'Lunas'` menghasilkan `0` untuk pesanan tersebut. Rumus menghitung `stok_awal` sebagai `8 + 0 = 8`.
- **Dampak:**
  Laporan produk kehilangan jejak 2 unit fisik. Stok awal tercatat 8 padahal awalnya 10, menimbulkan kebingungan dalam audit pembukuan gudang.
- **Rekomendasi Perbaikan:**
  Pertimbangkan untuk menyertakan barang yang di-refund dalam metrik terpisah pada kueri laporan produk (`total_refund`), dan rumus `stok_awal` disesuaikan menjadi `pr.stok + total_terjual + total_refund`.

---

### 3.4. Temuan Tingkat Low

#### [LOW-01] Fungsi `GREATEST(0, ...)` Menyerap Defisit Stok Tanpa Pencatatan Peringatan
- **File & Baris:** `includes/stock_reservation.php:179, 258-259`
- **Potongan Kode:**
  ```php
  UPDATE produk 
  SET stok = GREATEST(0, stok - ?),
      stok_direservasi = GREATEST(0, stok_direservasi - ?) 
  WHERE id_produk = ?
  ```
- **Skenario:**
  Jika terjadi anomali (misalnya stok produk dikurangi secara manual oleh admin di database saat reservasi sedang aktif sehingga `stok < $qty`), fungsi `GREATEST(0, stok - ?)` memaksa nilai stok menjadi 0 tanpa memicu error atau mencatat log peringatan.
- **Dampak:**
  Selisih barang fisik hilang tertelan tanpa jejak audit peringatan di sistem log.
- **Rekomendasi Perbaikan:**
  Tambahkan pengecekan apakah `stok < $qty` dan catat error log dengan level `CRITICAL_INVENTORY_MISMATCH`.

---

#### [LOW-02] Potensi SQL Injection pada Pencarian Pesanan Admin
- **File & Baris:** `admin/pesanan.php:41-56`
- **Potongan Kode:**
  ```php
  $search = $_GET['search'] ?? '';
  if($search != ''){
      $query = mysqli_query($conn, "
          SELECT t.*, p.nama, p.no_hp 
          FROM transaksi t 
          JOIN pelanggan p ON t.id_pelanggan = p.id_pelanggan 
          WHERE p.nama LIKE '%$search%'
             OR CONCAT('TRX-00', t.id_transaksi) LIKE '%$search%'
             OR t.id_transaksi LIKE '%$search%'
             OR t.status_pembayaran LIKE '%$search%'
          ORDER BY t.id_transaksi DESC
      ");
  ```
- **Skenario:**
  Parameter `$_GET['search']` diinterpolasikan langsung ke dalam string query SQL tanpa escaping (`mysqli_real_escape_string`) maupun prepared statement.
- **Dampak:**
  SQL Injection yang dapat dieksploitasi jika admin membuka tautan pencarian berbahaya via CSRF/Phishing.
- **Rekomendasi Perbaikan:**
  Gunakan prepared statement dengan parameter binding untuk input pencarian.

---

#### [LOW-03] Pengecekan Strict Tipe Data `status_code` Menolak Format Integer
- **File & Baris:** `midtrans_notification.php:35`
- **Potongan Kode:**
  ```php
  !is_string($status_code) || trim($status_code) === ''
  ```
- **Skenario:**
  Midtrans secara resmi mengirimkan `status_code` sebagai string `"200"`, namun dalam lingkungan unit test mock atau simulator pihak ketiga, jika status dikirimkan sebagai integer `200`, validasi ini akan gagal dan mengembalikan `HTTP 400 Bad Request`.
- **Dampak:**
  Kegagalan integrasi mock testing yang tidak memformat status_code sebagai string.
- **Rekomendasi Perbaikan:**
  Gunakan `(!is_string($status_code) && !is_int($status_code)) || trim((string)$status_code) === ''`.

---

## 4. Matriks State Machine Lengkap

Berikut adalah matriks evaluasi transisi state machine pada `midtrans_notification.php` berdasarkan penelusuran kode aktual:

| No | Status DB Awal (`current_pay_status`) | Event Midtrans (`transaction_status` + `fraud_status`) | Status Pembayaran Akhir | `midtrans_status` Akhir | Perubahan Stok Fisik (`produk.stok`) | Perubahan Stok Reservasi (`stok_direservasi`) | Perubahan `stok_dikurangi` | Respons HTTP | Status Idempotensi | Evaluasi Logika & Catatan |
|---|---|---|---|---|---|---|---|---|---|---|
| 1 | Menunggu Pembayaran | settlement | Lunas | settlement | Berkurang sejumlah qty | Berkurang sejumlah qty | Menjadi 1 | 200 OK | Idempoten | **Valid**; memotong stok fisik via reservasi. Namun rentan phantom conversion jika reservasi kosong (lihat CRIT-03). |
| 2 | Menunggu Pembayaran | capture (fraud: accept) | Lunas | capture | Berkurang sejumlah qty | Berkurang sejumlah qty | Menjadi 1 | 200 OK | Idempoten | **Valid**; transaksi kartu kredit disetujui. |
| 3 | Menunggu Pembayaran | capture (fraud: challenge) | Menunggu Pembayaran | challenge | Tidak berubah | Tidak berubah | Tetap 0 | 200 OK | Idempoten | **Polusi Semantik**; status pembayaran ditahan aman, namun `midtrans_status` diset ke `'challenge'` (lihat HIGH-02). |
| 4 | Menunggu Pembayaran | capture (fraud: deny) | Dibatalkan | deny | Tidak berubah | Dilepas (berkurang qty) | Tetap 0 | 200 OK | Idempoten | **Rentan Deadlock Stok**; nilai return pelepasan stok diabaikan (lihat HIGH-01). |
| 5 | Menunggu Pembayaran | pending | Menunggu Pembayaran | pending | Tidak berubah | Tidak berubah | Tetap 0 | 200 OK | Idempoten | **Valid**; reservasi tetap aktif menunggu pembayaran. |
| 6 | Menunggu Pembayaran | expire | Dibatalkan | expire | Tidak berubah | Dilepas (berkurang qty) | Tetap 0 | 200 OK | Idempoten | **Valid secara alur**, namun nilai return pelepasan stok diabaikan (HIGH-01). |
| 7 | Menunggu Pembayaran | cancel | Dibatalkan | cancel | Tidak berubah | Dilepas (berkurang qty) | Tetap 0 | 200 OK | Idempoten | **Valid secara alur**, nilai return pelepasan stok diabaikan (HIGH-01). |
| 8 | Menunggu Pembayaran | deny | Dibatalkan | deny | Tidak berubah | Dilepas (berkurang qty) | Tetap 0 | 200 OK | Idempoten | **Valid secara alur**, nilai return pelepasan stok diabaikan (HIGH-01). |
| 9 | Lunas | settlement | Lunas | Tidak berubah | Tidak berubah | Tidak berubah | Tetap 1 | 200 OK | Idempoten | **Valid**; duplikat settlement diabaikan (No-Op aman pada stok). |
| 10 | Lunas | pending | Lunas | Tidak berubah | Tidak berubah | Tidak berubah | Tetap 1 | 200 OK | Idempoten | **Valid**; event tertunda tidak menurunkan status Lunas (anti-regresi). |
| 11 | Lunas | expire | Lunas | Tidak berubah | Tidak berubah | Tidak berubah | Tetap 1 | 200 OK | Idempoten | **Valid**; event kadaluwarsa terlambat diabaikan, dana sudah sah diterima. |
| 12 | Lunas | cancel | Lunas | Tidak berubah | Tidak berubah | Tidak berubah | Tetap 1 | 200 OK | Idempoten | **Valid**; pembatalan terlambat diabaikan, status Lunas terlindungi. |
| 13 | Lunas | deny | Lunas | Tidak berubah | Tidak berubah | Tidak berubah | Tetap 1 | 200 OK | Idempoten | **Valid**; penolakan terlambat diabaikan. |
| 14 | Lunas | refund | Refund | refund | Tidak berubah | Tidak berubah | Tetap 1 | 200 OK | Idempoten | **Valid**; status berubah menjadi Refund, stok skincare tidak di-restock otomatis. |
| 15 | Lunas | partial_refund | Lunas | partial_refund | Tidak berubah | Tidak berubah | Tetap 1 | 200 OK | Idempoten | **Valid**; status pembayaran tetap Lunas, gateway status mencatat partial_refund. |
| 16 | Dibatalkan | settlement | Dibatalkan | settlement | Tidak berubah | Tidak berubah | Tetap 0 | 200 OK | Idempoten | **Valid (Late Settlement)**; DILARANG un-cancel, stok tidak dipotong, alert kritis dicatat. |
| 17 | Dibatalkan | capture (fraud: accept) | Dibatalkan | capture | Tidak berubah | Tidak berubah | Tetap 0 | 200 OK | Idempoten | **Valid (Late Capture)**; diperlakukan sama dengan late settlement. |
| 18 | Dibatalkan | pending | Dibatalkan | Tidak berubah | Tidak berubah | Tidak berubah | Tetap 0 | 200 OK | Idempoten | **Valid**; event tertunda diabaikan. |
| 19 | Dibatalkan | expire | Dibatalkan | Tidak berubah | Tidak berubah | Tidak berubah | Tetap 0 | 200 OK | Idempoten | **Valid**; duplikat expire diabaikan secara aman. |
| 20 | Dibatalkan | cancel | Dibatalkan | Tidak berubah | Tidak berubah | Tidak berubah | Tetap 0 | 200 OK | Idempoten | **Valid**; duplikat cancel diabaikan secara aman. |
| 21 | Dibatalkan | deny | Dibatalkan | Tidak berubah | Tidak berubah | Tidak berubah | Tetap 0 | 200 OK | Idempoten | **Valid**; duplikat deny diabaikan secara aman. |
| 22 | Dibatalkan | refund | Dibatalkan | refund | Tidak berubah | Tidak berubah | Tetap 0 | 200 OK | Idempoten | **Valid**; refund atas late settlement tetap berada di state Dibatalkan. |
| 23 | Refund | settlement | Refund | Tidak berubah | Tidak berubah | Tidak berubah | Tetap 1 | 200 OK | Idempoten | **Valid**; notifikasi settlement terlambat tidak menghidupkan kembali pesanan refund. |
| 24 | Refund | pending | Refund | Tidak berubah | Tidak berubah | Tidak berubah | Tetap 1 | 200 OK | Idempoten | **Valid**; out-of-order pending diabaikan. |
| 25 | Refund | refund duplikat | Refund | refund | Tidak berubah | Tidak berubah | Tetap 1 | 200 OK | Idempoten | **Valid**; No-Op aman pada data dan inventaris. |
| 26 | Refund | partial_refund | Refund | Tidak berubah | Tidak berubah | Tidak berubah | Tetap 1 | 200 OK | Idempoten | **Valid**; tidak menurunkan integritas status refund penuh. |

---

## 5. Audit Helper Reservasi Stok

Pemeriksaan mendalam terhadap fungsi dalam `includes/stock_reservation.php`:

### 5.1. Analisis Fungsi `convert_stock_reservation()`
- **Kondisi `converted = 0`:**
  Fungsi mengembalikan `['success' => true, 'converted' => 0]` setiap kali `$active_reservations` kosong. Hal ini keliru jika dipanggil dalam alur di mana pesanan belum pernah diproses (`stok_dikurangi === 0`). Fungsi ini tidak memverifikasi apakah ada baris reservasi berstatus `'Dikonversi'` yang membenarkan klaim no-op tersebut.
- **Kondisi Reservasi Tidak Ditemukan:**
  Jika tabel `reservasi_stok` tidak memiliki baris untuk `id_transaksi` tersebut, fungsi tetap mengembalikan `success = true, converted = 0`. Hal ini membuka celah transaksi lolos menjadi `Lunas` tanpa ada pemotongan stok aktual.
- **Proteksi Kuantitas Negatif:**
  Fungsi menggunakan `SET stok = GREATEST(0, stok - ?), stok_direservasi = GREATEST(0, stok_direservasi - ?)`. Klausul `GREATEST(0, ...)` menjamin kolom tidak bernilai negatif di MariaDB, namun berisiko menyamarkan selisih fisik barang di gudang tanpa peringatan.

### 5.2. Analisis Fungsi `release_stock_reservation()`
- **Idempotensi Pelepasan:**
  Fungsi bekerja secara idempoten: jika tidak ada reservasi aktif, fungsi mengembalikan `['success' => true, 'released' => 0]`.
- **Pengurangan Agregat Produk:**
  Menggunakan `SET stok_direservasi = GREATEST(0, stok_direservasi - ?)`. Agregat dikurangi secara proporsional dan baris `reservasi_stok` diubah menjadi `'Dilepas'`.
- **Kelemahan Penanganan Error:**
  Fungsi mengembalikan associative array dan tidak melempar Exception saat terjadi error internal kueri (`!$stmt_get`). Hal ini berakibat fatal jika pemanggil tidak memeriksa return value (seperti pada `midtrans_notification.php`).

---

## 6. Audit Race Condition dan Locking

Pemeriksaan mekanisme konkurensi pada seluruh file yang berinteraksi dengan transaksi dan stok:

### 6.1. Jalur Mutasi Data
1. `transaksi.status_pembayaran` dimutasi oleh:
   - `pelanggan/create_snap_token.php` (tahap inisiasi, timeout, error fallback).
   - `pelanggan/proses_pembayaran.php` (pembatalan manual & verifikasi transfer).
   - `admin/pesanan.php` (verifikasi manual admin).
   - `midtrans_notification.php` (webhook lifecycle).
2. `produk.stok` & `produk.stok_direservasi` dimutasi oleh:
   - `includes/stock_reservation.php` via `reserve_stock()`, `release_stock_reservation()`, `convert_stock_reservation()`.
   - `pelanggan/proses_pembayaran.php` (direct decrement manual payment).
3. `reservasi_stok.status_reservasi` dimutasi oleh:
   - `includes/stock_reservation.php` (`reserve_stock`, `release_stock_reservation`, `convert_stock_reservation`).

### 6.2. Simulasi Konseptual Skenario Konkurensi

1. **Dua Settlement Diterima Bersamaan:**
   - **Hasil: AMAN.**
   - Request pertama memperoleh row lock via `SELECT ... FOR UPDATE` pada tabel `transaksi`. Request kedua diblokir di level InnoDB.
   - Request pertama menyelesaikan konversi stok, mengubah `status_pembayaran = 'Lunas'` dan `stok_dikurangi = 1`, lalu melakukan `commit()`.
   - Request kedua mendapatkan giliran, membaca `status_pembayaran = 'Lunas'`, langsung masuk ke cabang duplikat (No-Op), tidak memotong stok lagi, dan merespons `HTTP 200 OK`.

2. **Settlement Bersamaan dengan Expire:**
   - **Hasil: AMAN.**
   - Jika `settlement` mengunci baris lebih dahulu: Pesanan menjadi `Lunas`. Ketika `expire` unblock, ia mendeteksi pesanan sudah `Lunas` dan diabaikan (anti-regresi).
   - Jika `expire` mengunci baris lebih dahulu: Pesanan menjadi `Dibatalkan` dan reservasi dilepas. Ketika `settlement` unblock, ia mendeteksi pesanan sudah `Dibatalkan` dan masuk ke cabang *Late Settlement* (stok fisik tidak dipotong, alert dicatat, pesanan tetap dibatalkan).

3. **Settlement Bersamaan dengan Pembatalan Lokal:**
   - **Hasil: RENTAN (Terkait Temuan HIGH-03).**
   - Pada `pelanggan/proses_pembayaran.php:11`, pembatalan lokal tidak menggunakan row lock dan tidak memanggil `release_stock_reservation()`. Jika pembatalan lokal mendahului settlement, status transaksi menjadi `Dibatalkan` namun reservasi tetap `Aktif`. Ketika webhook settlement tiba, webhook menganggapnya sebagai late settlement sehingga tidak mengonversi reservasi. Akibatnya reservasi stok menggantung selamanya.

4. **Refund Bersamaan dengan Settlement Duplikat:**
   - **Hasil: AMAN.**
   - Jika `refund` diproses lebih dahulu, status menjadi `Refund`. Notifikasi duplikat settlement yang tiba kemudian membaca status `Refund` dan hanya meng-update payload tanpa mengubah status pembayaran kembali ke Lunas.

5. **Late Snap Token Response Pasca-Webhook:**
   - **Hasil: AMAN.**
   - Pada `pelanggan/create_snap_token.php:650-748` (Tahap 3A), kueri `SELECT ... FOR UPDATE` memeriksa `current_pay_status`. Jika webhook telah mengubah status menjadi `Lunas`, `Dibatalkan`, atau `Refund`, `create_snap_token.php` tidak menimpa status terminal tersebut (dilindungi perbaikan F-01).

6. **Dua Transaksi Memesan Produk Terakhir Bersamaan:**
   - **Hasil: AMAN.**
   - Fungsi `reserve_stock()` mengurutkan item dengan `usort($items, id_produk ASC)` dan mengunci baris produk dengan `SELECT ... FOR UPDATE`. Transaksi pertama mengambil kuantitas yang tersedia dan menambah `stok_direservasi`. Transaksi kedua membaca stok yang telah diperbarui, mendeteksi `stok_tersedia < qty`, dan di-rollback dengan aman.

---

## 7. Audit Integrasi Modul Pengiriman

1. **Validasi Status Pembayaran:**
   - Ditemukan cacat logika kritis (CRIT-02): modul pengiriman tidak memastikan status pembayaran telah `Lunas` sebelum mengizinkan pemrosesan nomor resi dan pengiriman barang.
2. **Pencegahan Perubahan untuk Status Terminal Batal/Refund:**
   - Backend dan frontend telah menonaktifkan update pengiriman untuk pesanan `Dibatalkan` dan `Refund`.
3. **Preservasi Data Historis Logistik:**
   - Webhook pada saat transisi ke status `Refund` tidak menghapus record pengiriman dari tabel `pengiriman`. Data kurir, nomor resi, dan alamat tujuan historis tetap tersimpan utuh di database.
4. **Masalah Pengiriman yang Sedang Berjalan (In-Transit):**
   - Ditemukan kelemahan operasional (MED-02): pesanan yang sudah berstatus `Dikirim` tidak dapat ditutup statusnya menjadi `Selesai` atau `Retur Diterima` jika pembeli melakukan refund di tengah proses pengiriman.

---

## 8. Audit Kompatibilitas Pembayaran Manual dan Laporan

1. **Kueri Pendapatan dan Penjualan Bersih:**
   - Pada `admin/laporan.php:46` dan `admin/dashboard_admin.php:16`, kueri pendapatan menggunakan klausul `WHERE status_pembayaran = 'Lunas'`.
   - Transaksi dengan status `Refund` dan `Dibatalkan` secara tepat **TIDAK DIHITUNG** ke dalam total pendapatan toko.
2. **Kueri Produk Terlaris:**
   - Pada `admin/laporan.php:78`, klausul `COALESCE(SUM(CASE WHEN t.status_pembayaran = 'Lunas' THEN dt.jumlah ELSE 0 END), 0)` memastikan hanya transaksi lunas yang dihitung sebagai barang terjual.
3. **Badge Status Refund pada Seluruh Antarmuka:**
   - Tampilan badge status `Refund` telah diimplementasikan secara konsisten pada `admin/pesanan.php` (kelas `.status-refund`), `pelanggan/pesanan_user.php` (kelas `.status-refund`), dan `admin/laporan.php` (kelas `.refund`).
4. **Tombol Verifikasi Pembayaran Manual:**
   - Pada `admin/pesanan.php:481`, tombol "Verifikasi" disembunyikan untuk pesanan Midtrans (`metode_pembayaran === 'Midtrans'`) dan digantikan teks informasi `"Otomatis (Midtrans)"`.
   - Handler verifikasi manual di baris 20-25 membatasi `WHERE (metode_pembayaran != 'Midtrans' OR metode_pembayaran IS NULL)` sehingga admin tidak dapat memalsukan verifikasi pesanan Midtrans secara manual via parameter URL.
5. **Alur Pembayaran Manual Lama:**
   - Transaksi manual via `pelanggan/proses_pembayaran.php` memotong stok secara langsung pada saat pengunggahan bukti transfer (`UPDATE produk SET stok = stok - ?`).
   - Verifikasi admin di `admin/pesanan.php` hanya mengubah status pembayaran menjadi `Lunas` tanpa memotong stok kembali. Alur lama tetap berfungsi kompatibel.

---

## 9. Daftar Test Case Runtime yang Belum Dilakukan

Audit ini dilakukan secara statis dan analisis konseptual. Pengujian runtime berikut **BELUM DILAKUKAN** dan wajib dieksekusi sebelum rilis produksi:

1. **Simulasi Webhook Concurrency Paralel:**
   Mengirimkan 2 HTTP POST request settlement secara simultan dalam jeda waktu 10 milidetik menggunakan tool konkurensi (seperti ApacheBench, curl paralel, atau script multi-curl PHP) untuk memverifikasi secara empiris bahwa row locking MariaDB tidak menghasilkan deadlock atau double conversion.
2. **Simulasi Late Settlement Runtime:**
   Membuat pesanan Midtrans, mengubah status pembayaran di database menjadi `Dibatalkan` (mensimulasikan expired), lalu menembakkan mock request settlement ke `midtrans_notification.php` untuk memastikan log alert kritis tercatat dan status pembayaran tetap `Dibatalkan`.
3. **Uji Penetrasi Signature & Gross Amount Tampering:**
   - Mengirim request dengan signature palsu (memastikan ditolak dengan `HTTP 401`).
   - Mengirim request dengan nominal yang dimanipulasi berbeda Rp 1 dari database (memastikan ditolak dengan `HTTP 400` dan di-rollback).
   - Mengirim request dengan nilai gross_amount floating point (misal `"150000.50"`) untuk memastikan regex menolak request.
4. **Uji Kartu Kredit FDS Challenge:**
   Mengirim request capture dengan `fraud_status = challenge`, memverifikasi bahwa status pesanan tetap `Menunggu Pembayaran` dan reservasi stok tetap aman.
5. **Simulasi Kegagalan Koneksi Database (HTTP 500 Retry Trigger):**
   Mematikan sementara koneksi DB atau menyimulasikan exception di tengah eksekusi webhook untuk membuktikan bahwa respons adalah `HTTP 500` dan Midtrans akan mengulang pengiriman webhook.
6. **Uji Checkout Paralel Stok Habis:**
   Dua akun pelanggan melakukan checkout secara bersamaan untuk produk dengan sisa stok 1, memastikan satu akun berhasil dan akun lainnya menerima notifikasi stok tidak mencukupi tanpa memicu error database 500.

---

## 10. Rekomendasi Koreksi Berdasarkan Prioritas

### Prioritas 1: Segera (Blocker Produksi)
1. **Tambahkan Auth Guard Admin pada `admin/pengiriman.php` (CRIT-01):**
   Cegah akses tanpa otorisasi ke data pengiriman pelanggan.
2. **Kunci Pemrosesan Pengiriman Hanya untuk Pesanan Lunas (CRIT-02):**
   Pada `admin/pengiriman.php`, tolak pemrosesan nomor resi jika `status_pembayaran !== 'lunas'`.
3. **Perbaiki Logika Konversi Stok pada Webhook & Helper (CRIT-03):**
   Di `includes/stock_reservation.php`, bedakan kondisi reservasi telah dikonversi vs reservasi hilang/dilepas. Di `midtrans_notification.php`, cegah pembaruan `stok_dikurangi = 1` jika `converted === 0` saat `stok_dikurangi === 0`.
4. **Tangkap dan Periksa Hasil Return `release_stock_reservation()` (HIGH-01):**
   Lemparkan `RuntimeException` jika pelepasan reservasi gagal agar transaksi di-rollback dan memicu retry Midtrans.
5. **Sertakan Pelepasan Reservasi pada Pembatalan Manual Lokal (HIGH-03):**
   Panggil `release_stock_reservation()` di dalam blok transaksi pada `pelanggan/proses_pembayaran.php:11`.

### Prioritas 2: Penting (Sebelum Fase 4)
6. **Pertahankan Nilai Semantik Murni `midtrans_status = 'capture'` (HIGH-02):**
   Jangan menyimpan nilai `'challenge'` atau `'deny'` ke dalam `midtrans_status` saat `transaction_status === 'capture'`. Simpan status fraud di dalam payload JSON.
7. **Lepaskan Reservasi Saat Unpaid Order Menerima Refund (MED-01):**
   Panggil `release_stock_reservation()` jika transaksi `Menunggu Pembayaran` menerima status refund dari gateway.
8. **Izinkan Penutupan Status Pengiriman yang Sedang Berjalan (MED-02):**
   Beri pengecualian pada `admin/pengiriman.php` agar pesanan berstatus `Dikirim` tetap dapat diubah menjadi `Selesai` atau `Retur Diterima`.

### Prioritas 3: Peningkatan Mutu (Fase Pemeliharaan)
9. **Perbaiki Perhitungan Metrik Pelaporan Produk Terkait Refund (MED-03):**
   Pisahkan metrik retur/refund agar perhitungan stok awal historis tidak terdistorsi.
10. **Refactor Parameter Pencarian Menggunakan Prepared Statement (LOW-02):**
    Hilangkan string interpolation pada `admin/pesanan.php`.

---

## 11. Keputusan Akhir

### Status: **REJECTED**

### Justifikasi Keputusan:
Meskipun arsitektur kriptografi webhook, validasi nominal anti-floating point, dan state machine anti-regresi pada `midtrans_notification.php` telah dirancang dengan sangat baik, audit independen menemukan **3 temuan tingkat CRITICAL** dan **3 temuan tingkat HIGH**:
1. Modul pengiriman terbuka secara publik tanpa autentikasi admin.
2. Barang pesanan belum lunas dapat dikirimkan ke pembeli.
3. Terjadi *phantom stock conversion* di mana transaksi ditandai lunas tanpa pemotongan stok fisik jika reservasi kosong/dilepas.
4. Error pelepasan stok tertelan dan mengunci inventaris permanen tanpa retry.
5. Pembatalan lokal mengabaikan pelepasan reservasi stok.

Celah-celah tersebut menimbulkan risiko kerugian finansial langsung bagi bisnis Beautyland. Implementasi Fase 3.2 dinyatakan **DITOLAK** untuk rilis produksi sampai seluruh rekomendasi Prioritas 1 diperbaiki dan diverifikasi ulang.
